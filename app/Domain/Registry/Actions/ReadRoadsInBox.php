<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Streets to draw under the directory map, for a box.
 *
 * OpenStreetMap geometry already loaded for the field packs, which is public
 * data: nothing about a business is in it. Simplified and capped in the
 * database, so a box drawn over a whole state returns the main roads and not
 * every lane in it.
 */
final class ReadRoadsInBox
{
    private const MAX_FEATURES = 2500;

    /** Wider boxes get only the roads that read at that scale. */
    private const MAJOR = ['motorway', 'trunk', 'primary', 'secondary', 'motorway_link', 'trunk_link', 'primary_link'];

    /** @return array{type: string, features: list<array<string, mixed>>} */
    public function __invoke(float $west, float $south, float $east, float $north): array
    {
        $span = max(abs($east - $west), abs($north - $south));

        if ($span > 3 || $span <= 0) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        $classes = $span > 0.25 ? self::MAJOR : array_merge(self::MAJOR, ['tertiary', 'residential', 'unclassified', 'tertiary_link', 'living_street']);
        $tolerance = $span > 0.25 ? 0.0004 : 0.00005;

        $rows = DB::select('
            SELECT name, highway, ST_AsGeoJSON(ST_Simplify(geometry, ?), 5) AS geometry
            FROM roads
            WHERE geometry && ST_MakeEnvelope(?, ?, ?, ?, 4326)
              AND highway = ANY(?)
            LIMIT '.self::MAX_FEATURES,
            [$tolerance, $west, $south, $east, $north, '{'.implode(',', $classes).'}'],
        );

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(static fn (object $r): array => [
                'type' => 'Feature',
                'geometry' => json_decode((string) $r->geometry, true),
                'properties' => [
                    'name' => $r->name,
                    'major' => in_array($r->highway, self::MAJOR, true),
                ],
            ], $rows),
        ];
    }
}
