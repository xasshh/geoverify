<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use App\Domain\Coverage\Models\CoverageArea;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ingests detected building footprints: the denominator that turns "the officer
 * says the cell is done" into "381 of 412 detected structures were visited".
 *
 * Reads newline-delimited GeoJSON, which is what both Google Open Buildings and
 * Microsoft Global ML Building Footprints publish, so the command needs no GDAL.
 *
 * Two filters run, in this order and for a reason:
 *
 *  1. A cheap bounding box test in PHP, to discard the overwhelming majority of a
 *     national tile without touching the database. The Abuja tiles hold 1.6 million
 *     footprints; only a fraction are inside the mandate.
 *  2. An exact ST_Intersects against the mandate boundary, in PostGIS, which is the
 *     only test that decides what is actually ingested.
 */
final class IngestFootprints
{
    private const CHUNK = 2000;

    /**
     * @param  list<string>  $paths
     * @param  null|Closure(int, int): void  $onProgress
     * @return array{read: int, near: int, ingested: int, skipped_invalid: int, repaired: int}
     */
    public function ingest(
        CoverageArea $area,
        array $paths,
        string $source,
        ?Closure $onProgress = null,
    ): array {
        // A national tile is millions of rows. If query logging is on anywhere,
        // this is where it exhausts memory, so it is disabled for the duration.
        DB::connection()->disableQueryLog();

        $bbox = $this->boundingBox($area);

        $read = 0;
        $near = 0;
        $invalid = 0;
        $buffer = [];

        $this->createStaging();

        foreach ($paths as $path) {
            $handle = $this->open($path);

            while (($line = fgets($handle)) !== false) {
                $read++;

                if ($read % 50_000 === 0 && $onProgress !== null) {
                    $onProgress($read, $near);
                }

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                /** @var array<string, mixed>|null $feature */
                $feature = json_decode($line, true);

                if (! is_array($feature) || ! is_array($feature['geometry'] ?? null)) {
                    $invalid++;

                    continue;
                }

                /** @var array<string, mixed> $geometry */
                $geometry = $feature['geometry'];

                if (! $this->intersectsBox($geometry, $bbox)) {
                    continue;
                }

                $near++;
                $encoded = json_encode($geometry, JSON_THROW_ON_ERROR);

                /** @var array<string, mixed> $properties */
                $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

                $buffer[] = [
                    // No source publishes a stable identifier, so the geometry is
                    // its own identity. Re-running is then a no-op, not a duplicate.
                    'source_id' => hash('xxh128', $encoded),
                    'confidence' => $this->confidence($properties),
                    'geojson' => $encoded,
                ];

                if (count($buffer) >= self::CHUNK) {
                    $this->stage($buffer);
                    $buffer = [];
                }
            }

            fclose($handle);
        }

        if ($buffer !== []) {
            $this->stage($buffer);
        }

        if ($onProgress !== null) {
            $onProgress($read, $near);
        }

        // Detections whose geometry was invalid and had to be repaired. A quality
        // signal about the source, so it is measured before the staging table goes.
        $repaired = (int) DB::scalar(
            "select count(*) from staging_footprints where GeometryType(geom) <> 'POLYGON'",
        );

        $ingested = $this->promote($area, $source);

        return [
            'read' => $read,
            'near' => $near,
            'ingested' => $ingested,
            'skipped_invalid' => $invalid,
            'repaired' => $repaired,
        ];
    }

    /**
     * Assigns each footprint its H3 cell and links it to the grid, then refreshes
     * the per-cell denominator. Entirely in PostgreSQL.
     */
    private function promote(CoverageArea $area, string $source): int
    {
        $resolution = $area->default_h3_resolution;

        // ST_PointOnSurface, not centroid: a building's centroid can fall outside a
        // concave footprint, which would file it under the wrong cell.
        //
        // The lateral reduces each staged geometry to its largest polygon part. A
        // detection that repairs into several pieces is a bowtie in the source data,
        // not several buildings, so keeping the largest part is the honest reading
        // and inflating the denominator with slivers is not.
        $inserted = DB::affectingStatement(<<<'SQL'
            INSERT INTO external_footprints (
                source, source_id, confidence, area_m2, h3_index, footprint, created_at, updated_at
            )
            SELECT ?,
                   s.source_id,
                   s.confidence,
                   round(ST_Area(poly.geom::geography)::numeric, 2),
                   h3_latlng_to_cell(ST_PointOnSurface(poly.geom), ?)::bigint,
                   poly.geom,
                   now(),
                   now()
              FROM staging_footprints s
              CROSS JOIN LATERAL (
                    SELECT d.geom
                      FROM (SELECT (ST_Dump(ST_CollectionExtract(s.geom, 3))).geom) d
                     ORDER BY ST_Area(d.geom) DESC
                     LIMIT 1
                   ) poly
              JOIN coverage_areas ca ON ca.id = ?
             WHERE poly.geom IS NOT NULL
               -- The representative point, not the polygon, decides membership.
               -- A footprint is assigned to the H3 cell containing this point, so
               -- testing the mandate the same way is what makes it impossible for a
               -- footprint to be ingested and then belong to no cell. Using
               -- ST_Intersects here instead admits buildings that straddle the
               -- boundary but sit mostly outside, and they then orphan: counted in
               -- the table, absent from every cell's denominator.
               AND ST_Contains(ca.boundary, ST_PointOnSurface(poly.geom))
             ON CONFLICT (source, source_id) DO NOTHING
        SQL, [$source, $resolution, $area->id]);

        DB::statement(<<<'SQL'
            UPDATE external_footprints f
               SET grid_cell_id = g.id, updated_at = now()
              FROM grid_cells g
             WHERE g.h3_index = f.h3_index
               AND g.coverage_area_id = ?
               AND f.grid_cell_id IS DISTINCT FROM g.id
        SQL, [$area->id]);

        // The denominator itself. Dismissed footprints stop counting as work.
        DB::statement(<<<'SQL'
            UPDATE grid_cells g
               SET footprint_count = COALESCE(c.n, 0),
                   coverage_pct = CASE WHEN COALESCE(c.n, 0) = 0 THEN 0
                                       ELSE LEAST(100, round((g.structures_captured::numeric / c.n) * 100, 2)) END,
                   updated_at = now()
              FROM (
                    SELECT g2.id, count(f.id) AS n
                      FROM grid_cells g2
                      LEFT JOIN external_footprints f
                             ON f.grid_cell_id = g2.id AND f.dismissed = false
                     WHERE g2.coverage_area_id = ?
                     GROUP BY g2.id
                   ) c
             WHERE c.id = g.id
        SQL, [$area->id]);

        return $inserted;
    }

    private function createStaging(): void
    {
        DB::statement('DROP TABLE IF EXISTS staging_footprints');
        // Generic geometry, not Polygon: repairing a self-intersecting detection
        // can legitimately yield a MultiPolygon, and the staging table must be able
        // to hold what the repair produced before it is normalised.
        DB::statement('CREATE UNLOGGED TABLE staging_footprints (
            source_id text PRIMARY KEY,
            confidence numeric,
            geom geometry(Geometry, 4326)
        )');
    }

    /**
     * @param  list<array{source_id: string, confidence: float|null, geojson: string}>  $rows
     */
    private function stage(array $rows): void
    {
        $values = [];
        $bindings = [];

        foreach ($rows as $row) {
            $values[] = '(?, ?, ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))';
            array_push($bindings, $row['source_id'], $row['confidence'], $row['geojson']);
        }

        DB::statement(
            'INSERT INTO staging_footprints (source_id, confidence, geom) VALUES '
            .implode(', ', $values)
            .' ON CONFLICT (source_id) DO NOTHING',
            $bindings,
        );
    }

    /**
     * @return array{minx: float, miny: float, maxx: float, maxy: float}
     */
    private function boundingBox(CoverageArea $area): array
    {
        /** @var object{minx: float, miny: float, maxx: float, maxy: float}|null $box */
        $box = DB::selectOne(
            'select ST_XMin(boundary) as minx, ST_YMin(boundary) as miny,
                    ST_XMax(boundary) as maxx, ST_YMax(boundary) as maxy
               from coverage_areas where id = ?',
            [$area->id],
        );

        if ($box === null) {
            throw new RuntimeException("Coverage area {$area->id} has no boundary.");
        }

        return [
            'minx' => (float) $box->minx,
            'miny' => (float) $box->miny,
            'maxx' => (float) $box->maxx,
            'maxy' => (float) $box->maxy,
        ];
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @param  array{minx: float, miny: float, maxx: float, maxy: float}  $bbox
     */
    private function intersectsBox(array $geometry, array $bbox): bool
    {
        $rings = $geometry['coordinates'] ?? null;

        if (! is_array($rings) || ! is_array($rings[0] ?? null)) {
            return false;
        }

        foreach ($rings[0] as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1])) {
                continue;
            }

            $x = (float) $point[0];
            $y = (float) $point[1];

            if ($x >= $bbox['minx'] && $x <= $bbox['maxx'] && $y >= $bbox['miny'] && $y <= $bbox['maxy']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function confidence(array $properties): ?float
    {
        $value = $properties['confidence'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        // Microsoft publishes -1 for unscored regions, Nigeria among them. Storing
        // that as a number would invent a confidence the source never gave.
        $confidence = (float) $value;

        return ($confidence < 0 || $confidence > 1) ? null : $confidence;
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Footprint file not readable: {$path}");
        }

        $handle = str_ends_with($path, '.gz')
            ? gzopen($path, 'rb')
            : fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$path}");
        }

        return $handle;
    }
}
