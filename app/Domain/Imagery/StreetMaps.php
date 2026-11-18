<?php

declare(strict_types=1);

namespace App\Domain\Imagery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The street map an officer carries offline: the national basemap cut to one
 * mandate's box, a few megabytes rather than half a gigabyte.
 *
 * Beside the map pack and never inside it, like the satellite imagery: the
 * pack is what a shipped handset already depends on, and this is extra. Each
 * cut is a PMTiles file with a small JSON note beside it (size, checksum, box),
 * which is all the field API needs to offer it and a phone needs to know it has
 * the current one. Cut again whenever the national file is refreshed.
 */
final class StreetMaps
{
    /** Margin around the mandate, in degrees: the street just outside still matters. */
    private const MARGIN = 0.01;

    public function directory(): string
    {
        return dirname((string) config('geoverify.basemap.path')).'/areas';
    }

    public function path(int $coverageAreaId): string
    {
        return $this->directory()."/{$coverageAreaId}.pmtiles";
    }

    /**
     * What is known about a mandate's street map, or null when there is none.
     *
     * @return array{bytes: int, checksum: string, builtAt: string, bounds: array{0: float, 1: float, 2: float, 3: float}, maxZoom: int}|null
     */
    public function meta(int $coverageAreaId): ?array
    {
        $note = $this->directory()."/{$coverageAreaId}.json";

        if (! is_file($this->path($coverageAreaId)) || ! is_file($note)) {
            return null;
        }

        /** @var array{bytes: int, checksum: string, builtAt: string, bounds: array{0: float, 1: float, 2: float, 3: float}, maxZoom: int}|null $meta */
        $meta = json_decode((string) file_get_contents($note), true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * Cuts the mandate's street map out of the national basemap.
     *
     * @return array{bytes: int, checksum: string, builtAt: string, bounds: array{0: float, 1: float, 2: float, 3: float}, maxZoom: int}
     */
    public function build(int $coverageAreaId): array
    {
        $national = (string) config('geoverify.basemap.path');

        if (! is_file($national)) {
            throw new RuntimeException('There is no national basemap to cut from. Run geoverify:basemap-fetch first.');
        }

        // The box is PostGIS's, from the contracted boundary.
        $box = DB::selectOne(<<<'SQL'
            select st_xmin(e) - ? as west, st_ymin(e) - ? as south,
                   st_xmax(e) + ? as east, st_ymax(e) + ? as north
              from (select st_extent(boundary) as e from coverage_areas where id = ?) extent
        SQL, [self::MARGIN, self::MARGIN, self::MARGIN, self::MARGIN, $coverageAreaId]);

        if ($box === null || $box->west === null) {
            throw new RuntimeException("Mandate {$coverageAreaId} has no boundary to cut a street map for.");
        }

        $bounds = [(float) $box->west, (float) $box->south, (float) $box->east, (float) $box->north];
        $maxZoom = (int) config('geoverify.basemap.max_zoom');

        if (! is_dir($this->directory()) && ! mkdir($this->directory(), 0775, true) && ! is_dir($this->directory())) {
            throw new RuntimeException('Could not create '.$this->directory().'.');
        }

        $target = $this->path($coverageAreaId);
        $partial = $target.'.partial';

        $result = Process::timeout(1800)->run([
            (string) config('geoverify.imagery.pmtiles_binary'),
            'extract',
            $national,
            $partial,
            '--bbox='.implode(',', array_map(static fn (float $v): string => number_format($v, 6, '.', ''), $bounds)),
            "--maxzoom={$maxZoom}",
        ]);

        if ($result->failed() || ! is_file($partial)) {
            @unlink($partial);

            throw new RuntimeException('pmtiles extract failed: '.trim($result->errorOutput()));
        }

        rename($partial, $target);

        $meta = [
            'bytes' => (int) filesize($target),
            'checksum' => (string) md5_file($target),
            'builtAt' => now()->toIso8601String(),
            'bounds' => $bounds,
            'maxZoom' => $maxZoom,
        ];

        file_put_contents($this->directory()."/{$coverageAreaId}.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return $meta;
    }
}
