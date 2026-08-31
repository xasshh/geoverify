<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use App\Domain\Coverage\Models\Road;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the street network from newline delimited GeoJSON.
 *
 * Same shape as the footprint ingest, and for the same reasons: one feature per
 * line so a national extract streams rather than being held in memory, and an
 * upsert on the source's own id so an interrupted run is repaired by running it
 * again rather than by cleaning up first.
 *
 * Geometry is written by PostGIS from the GeoJSON. Nothing is parsed or
 * projected in PHP.
 */
final class IngestRoads
{
    /** Written in batches: one statement per road is a minute of round trips. */
    private const BATCH = 500;

    /**
     * @param  list<string>  $paths
     * @return array{read: int, ingested: int, skipped: int}
     */
    public function ingest(array $paths, string $source = 'openstreetmap', ?callable $progress = null): array
    {
        $read = 0;
        $ingested = 0;
        $skipped = 0;
        $batch = [];

        foreach ($paths as $path) {
            $handle = $this->open($path);

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $read++;

                $feature = json_decode($line, true);

                if (! is_array($feature) || ! isset($feature['geometry'], $feature['properties'])) {
                    $skipped++;

                    continue;
                }

                $row = $this->row($feature, $source);

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $batch[] = $row;

                if (count($batch) >= self::BATCH) {
                    $ingested += $this->write($batch);
                    $batch = [];

                    if ($progress !== null) {
                        $progress($read, $ingested);
                    }
                }
            }

            fclose($handle);
        }

        if ($batch !== []) {
            $ingested += $this->write($batch);
        }

        if ($progress !== null) {
            $progress($read, $ingested);
        }

        return ['read' => $read, 'ingested' => $ingested, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>|null
     */
    private function row(array $feature, string $source): ?array
    {
        /** @var array<string, mixed> $properties */
        $properties = $feature['properties'];
        $highway = isset($properties['highway']) ? (string) $properties['highway'] : '';

        // A class this build does not draw is still stored: styling changes
        // without a re-ingest, and a class nobody thought of is data rather
        // than an error. What is refused is a feature with no class at all,
        // because that is not a road.
        if ($highway === '') {
            return null;
        }

        $geometry = $feature['geometry'];

        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString') {
            return null;
        }

        $sourceId = isset($properties['id']) ? (string) $properties['id'] : null;

        return [
            'source' => $source,
            // No id from the source means the geometry is the identity, the
            // same answer the footprint ingest reached when the publishers
            // turned out not to give stable ones either.
            'source_id' => $sourceId ?? hash('xxh128', json_encode($geometry, JSON_THROW_ON_ERROR)),
            'name' => isset($properties['name']) ? mb_substr((string) $properties['name'], 0, 255) : null,
            'ref' => isset($properties['ref']) ? mb_substr((string) $properties['ref'], 0, 32) : null,
            'highway' => mb_substr($highway, 0, 32),
            'geojson' => json_encode($geometry, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function write(array $batch): int
    {
        $values = [];
        $bindings = [];

        foreach ($batch as $row) {
            // ST_LineMerge collapses the odd MultiLineString a converter emits
            // for a way that was split; ST_Force2D drops any elevation, which
            // the column does not carry and which no query here asks about.
            $values[] = '(?, ?, ?, ?, ?, ST_Force2D(ST_LineMerge(ST_GeomFromGeoJSON(?))), now(), now())';

            array_push(
                $bindings,
                $row['source'],
                $row['source_id'],
                $row['name'],
                $row['ref'],
                $row['highway'],
                $row['geojson'],
            );
        }

        return DB::affectingStatement(
            'INSERT INTO roads (source, source_id, name, ref, highway, geometry, created_at, updated_at)
             VALUES '.implode(',', $values).'
             ON CONFLICT (source, source_id) DO UPDATE SET
                name = EXCLUDED.name,
                ref = EXCLUDED.ref,
                highway = EXCLUDED.highway,
                geometry = EXCLUDED.geometry,
                updated_at = now()',
            $bindings,
        );
    }

    /** @return resource */
    private function open(string $path)
    {
        $handle = str_ends_with($path, '.gz')
            ? gzopen($path, 'rb')
            : fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $handle;
    }

    /** @return list<string> */
    public function classes(): array
    {
        return Road::CLASSES;
    }
}
