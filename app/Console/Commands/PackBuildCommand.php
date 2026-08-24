<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\MapPack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Builds the offline map pack an officer downloads before deployment.
 *
 * A PMTiles archive is a single file holding every tile for a mandate, so the
 * handset fetches one thing on wifi rather than thousands of tiles it will never
 * get on 2G. There is no tile server involved and nothing is billed per view,
 * which is the only arrangement that works for a client who owns their data.
 *
 * The pack carries four layers, and each is there for a reason:
 *
 *   roads       so an officer knows which street they are on. Without it the
 *               map is a field of hexagons with no landmarks at all.
 *   footprints  the work list, tappable rather than drawn by hand.
 *   cells       the assignment boundary, so leaving it is visible.
 *   wards       the administrative line, because it is what the register
 *               reports against and it does not follow the streets.
 *
 * Geometry comes out of PostGIS as GeoJSON. Nothing is projected in PHP.
 */
final class PackBuildCommand extends Command
{
    protected $signature = 'geoverify:pack-build
        {coverage_area : Coverage area id}
        {--roads= : Newline delimited GeoJSON of roads, see docs/geodata.md}
        {--min-zoom=10}
        {--max-zoom=16}';

    protected $description = 'Build the offline PMTiles map pack for a coverage area';

    public function handle(): int
    {
        $area = CoverageArea::query()->find($this->argument('coverage_area'));

        if (! $area instanceof CoverageArea) {
            $this->components->error('No coverage area with that id.');

            return self::FAILURE;
        }

        if ((new Process(['which', 'tippecanoe']))->run() !== 0) {
            $this->components->error(
                'tippecanoe is not installed. brew install tippecanoe, or see docs/geodata.md.',
            );

            return self::FAILURE;
        }

        $work = storage_path('app/private/packs/work-'.$area->id);
        @mkdir($work, 0o775, true);

        $this->components->info("Building the offline pack for {$area->name}");

        $layers = [];
        $counts = [];

        foreach ($this->exports($area) as $layer => $sql) {
            $path = "{$work}/{$layer}.geojsonl";
            $count = $this->export($sql, $area->id, $path);
            $counts[$layer] = $count;

            $this->components->twoColumnDetail(ucfirst($layer), number_format($count).' features');

            if ($count > 0) {
                $layers[] = "--named-layer={$layer}:{$path}";
            }
        }

        $roads = $this->option('roads');

        if (is_string($roads) && is_readable($roads)) {
            $layers[] = "--named-layer=roads:{$roads}";
            $counts['roads'] = $this->countLines($roads);
            $this->components->twoColumnDetail('Roads', basename($roads).' ('.number_format($counts['roads']).' ways)');
        } else {
            // Said plainly rather than buried: a pack with no roads is usable but
            // an officer has nothing to navigate by except their own trace.
            $this->components->warn(
                'No roads layer. The pack will have no streets, so an officer has no landmarks.',
            );
        }

        $output = "{$work}/pack.pmtiles";
        @unlink($output);

        $process = new Process(array_merge([
            'tippecanoe',
            '-o', $output,
            '--minimum-zoom='.(string) $this->option('min-zoom'),
            '--maximum-zoom='.(string) $this->option('max-zoom'),
            // Buildings must survive to the zoom an officer actually works at.
            // Dropping them as "too dense" would delete the work list.
            '--no-feature-limit',
            '--no-tile-size-limit',
            '--drop-densest-as-needed',
            '--force',
        ], $layers));

        $process->setTimeout(1800);

        $this->components->info('Running tippecanoe');
        $process->run(function (string $type, string $buffer): void {
            if (str_contains($buffer, 'tile') || str_contains($buffer, '%')) {
                $this->output->write("\r  ".trim($buffer).'   ');
            }
        });

        $this->newLine();

        if (! $process->isSuccessful()) {
            $this->components->error(trim($process->getErrorOutput()) ?: 'tippecanoe failed.');

            return self::FAILURE;
        }

        $stored = $this->publish($area, $output, $counts);

        return $stored ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function exports(CoverageArea $area): array
    {
        return [
            'footprints' => <<<'SQL'
                SELECT json_build_object(
                    'type', 'Feature',
                    'geometry', ST_AsGeoJSON(f.footprint, 6)::json,
                    'properties', json_build_object('id', f.id, 'visited', f.matched_structure_id IS NOT NULL)
                )::text
                  FROM external_footprints f
                  JOIN grid_cells g ON g.id = f.grid_cell_id
                 WHERE g.coverage_area_id = ? AND f.dismissed = false
            SQL,

            'cells' => <<<'SQL'
                SELECT json_build_object(
                    'type', 'Feature',
                    'geometry', ST_AsGeoJSON(g.boundary, 6)::json,
                    'properties', json_build_object(
                        'h3', to_hex(g.h3_index), 'footprints', g.footprint_count
                    )
                )::text
                  FROM grid_cells g WHERE g.coverage_area_id = ?
            SQL,

            'wards' => <<<'SQL'
                SELECT json_build_object(
                    'type', 'Feature',
                    'geometry', ST_AsGeoJSON(w.boundary, 6)::json,
                    'properties', json_build_object('name', w.name, 'code', w.code)
                )::text
                  FROM admin_boundaries w
                  JOIN coverage_areas c ON c.id = ?
                 WHERE w.level = 'ward' AND ST_Intersects(w.boundary, c.boundary)
            SQL,
        ];
    }

    /**
     * Streams a layer to disk a row at a time.
     *
     * Half a million footprints cannot be held in memory as one array, and this
     * command has to run on the same machine as everything else.
     */
    private function export(string $sql, int $areaId, string $path): int
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            return 0;
        }

        $written = 0;

        foreach (DB::cursor($sql, [$areaId]) as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $feature = reset($values);

            if (is_string($feature)) {
                fwrite($handle, $feature."\n");
                $written++;
            }
        }

        fclose($handle);

        return $written;
    }

    /**
     * Publishes the built archive and records it.
     *
     * The path carries a version, so a handset already reading the previous pack
     * keeps reading it. The old row is superseded rather than deleted: a pack an
     * officer is carrying is evidence of what they were shown in the field, and
     * that is not something to remove because a newer one exists.
     *
     * @param  array<string, int>  $counts
     */
    private function publish(CoverageArea $area, string $built, array $counts): bool
    {
        $bytes = filesize($built);
        $contents = fopen($built, 'rb');

        if ($contents === false || $bytes === false) {
            $this->components->error('The pack was built but could not be read.');

            return false;
        }

        $target = "packs/coverage-{$area->id}/".Str::lower((string) Str::ulid()).'.pmtiles';

        Storage::disk('media')->put($target, $contents);
        fclose($contents);

        $bounds = $this->bounds($area);

        MapPack::query()
            ->where('coverage_area_id', $area->id)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);

        $pack = MapPack::query()->create([
            'coverage_area_id' => $area->id,
            'path' => $target,
            'bytes' => $bytes,
            // Hashed so a handset can tell a half written download from a whole
            // one, and so a supervisor can prove which pack an officer carried.
            'checksum' => hash_file('sha256', $built),
            'min_zoom' => (int) $this->option('min-zoom'),
            'max_zoom' => (int) $this->option('max-zoom'),
            'layer_counts' => $counts,
            'west' => $bounds['west'],
            'south' => $bounds['south'],
            'east' => $bounds['east'],
            'north' => $bounds['north'],
            'built_at' => now(),
        ]);

        $this->newLine();
        $this->components->twoColumnDetail('Pack', $target);
        $this->components->twoColumnDetail('Size', $pack->megabytes().' MB');
        // The officer is shown this before they start the download, so a pack
        // that is too large to fetch on a hotel wifi is their decision to make.
        $this->components->twoColumnDetail(
            'On a 2 Mbps connection',
            gmdate('i:s', $pack->secondsAt2Mbps()).' to download',
        );

        return true;
    }

    /**
     * The mandate's own extent, read from PostGIS.
     *
     * Not the extent tippecanoe reports: that is the extent of the tiles, which
     * is rounded out to tile edges and would let the map pan further than the
     * mandate goes.
     *
     * @return array{west: float, south: float, east: float, north: float}
     */
    private function bounds(CoverageArea $area): array
    {
        /** @var object{west: float|string, south: float|string, east: float|string, north: float|string}|null $box */
        $box = DB::selectOne(
            'select ST_XMin(e) as west, ST_YMin(e) as south, ST_XMax(e) as east, ST_YMax(e) as north
               from (select ST_Extent(boundary) as e from grid_cells where coverage_area_id = ?) t',
            [$area->id],
        );

        return [
            'west' => (float) ($box->west ?? 0),
            'south' => (float) ($box->south ?? 0),
            'east' => (float) ($box->east ?? 0),
            'north' => (float) ($box->north ?? 0),
        ];
    }

    private function countLines(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return $lines;
    }
}
