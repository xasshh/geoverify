<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Loads administrative geography from GeoJSON.
 *
 * GeoJSON is the input format rather than shapefile or GeoPackage so the loader
 * carries no GDAL dependency; converting other formats is a documented one-off
 * preparation step. See docs/geodata.md.
 *
 * Two disciplines matter here:
 *
 *  - Idempotent and resumable. Re-running loads the same rows, so an interrupted
 *    import is fixed by running it again rather than by cleaning up first.
 *  - The hierarchy is resolved spatially, in PostGIS, not by matching source name
 *    strings. Nigerian place names differ between OCHA, GRID3 and geoBoundaries
 *    ("Abuja Municipal" against "Municipal Area Council"), so containment is the
 *    only thing that can be trusted to link a ward to its LGA.
 */
final class LoadAdminBoundaries
{
    /** Rows per INSERT. Large enough to be fast, small enough to keep memory flat. */
    private const CHUNK = 250;

    /**
     * @param  array{name: string, code: Closure(array<string, mixed>): string, alt: Closure(array<string, mixed>): array<int, string>, parent?: string}  $mapping
     * @param  null|Closure(int, int): void  $onProgress
     * @return array{loaded: int, skipped: int}
     */
    public function load(
        string $path,
        string $level,
        string $source,
        array $mapping,
        ?Closure $onProgress = null,
    ): array {
        if (! is_readable($path)) {
            throw new RuntimeException("Boundary file not readable: {$path}");
        }

        $features = $this->readFeatures($path);
        $total = count($features);

        $loaded = 0;
        $skipped = 0;
        $buffer = [];

        foreach ($features as $index => $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = $feature['geometry'] ?? null;

            if (! is_array($geometry)) {
                $skipped++;

                continue;
            }

            $name = $this->stringProperty($properties, $mapping['name']);

            if ($name === '') {
                $skipped++;

                continue;
            }

            $buffer[] = [
                'level' => $level,
                'code' => ($mapping['code'])($properties),
                'name' => $name,
                'alt_names' => json_encode(array_values(array_filter(($mapping['alt'])($properties))), JSON_THROW_ON_ERROR),
                'source' => $source,
                'source_parent_name' => isset($mapping['parent'])
                    ? $this->stringProperty($properties, $mapping['parent'])
                    : '',
                'geojson' => json_encode($geometry, JSON_THROW_ON_ERROR),
            ];

            if (count($buffer) >= self::CHUNK) {
                $loaded += $this->flush($buffer);
                $buffer = [];
                if ($onProgress !== null) {
                    $onProgress($index + 1, $total);
                }
            }
        }

        if ($buffer !== []) {
            $loaded += $this->flush($buffer);
        }

        if ($onProgress !== null) {
            $onProgress($total, $total);
        }

        return ['loaded' => $loaded, 'skipped' => $skipped];
    }

    /**
     * Links each boundary to the one containing it, using a representative interior
     * point rather than a centroid: a centroid can fall outside a concave or
     * multipart boundary, and several Nigerian LGAs are both.
     *
     * Runs entirely in PostgreSQL.
     */
    public function resolveHierarchy(string $childLevel, string $parentLevel): int
    {
        return DB::update(<<<'SQL'
            UPDATE admin_boundaries AS child
               SET parent_id = parent.id,
                   updated_at = now()
              FROM admin_boundaries AS parent
             WHERE child.level  = ?
               AND parent.level = ?
               AND ST_Contains(parent.boundary, ST_PointOnSurface(child.boundary))
               AND (child.parent_id IS DISTINCT FROM parent.id)
        SQL, [$childLevel, $parentLevel]);
    }

    /**
     * Writes a chunk. ST_Multi normalises Polygon and MultiPolygon inputs to the
     * column's MultiPolygon type; ST_MakeValid repairs the self-intersections that
     * are common in published administrative data.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function flush(array $rows): int
    {
        $values = [];
        $bindings = [];

        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?::jsonb, ?, NULLIF(?, \'\'), ST_Multi(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))), now(), now())';
            array_push(
                $bindings,
                $row['level'],
                $row['code'],
                $row['name'],
                $row['alt_names'],
                $row['source'],
                $row['source_parent_name'],
                $row['geojson'],
            );
        }

        $sql = 'INSERT INTO admin_boundaries (level, code, name, alt_names, source, source_parent_name, boundary, created_at, updated_at) VALUES '
            .implode(', ', $values)
            .' ON CONFLICT (level, code) DO UPDATE SET
                    name = EXCLUDED.name,
                    alt_names = EXCLUDED.alt_names,
                    source = EXCLUDED.source,
                    source_parent_name = EXCLUDED.source_parent_name,
                    boundary = EXCLUDED.boundary,
                    updated_at = now()';

        DB::statement($sql, $bindings);

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readFeatures(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$path} is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        $features = $decoded['features'] ?? null;

        if (! is_array($features)) {
            throw new RuntimeException("{$path} has no FeatureCollection features array");
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($features, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function stringProperty(array $properties, string $key): string
    {
        $value = $properties[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
