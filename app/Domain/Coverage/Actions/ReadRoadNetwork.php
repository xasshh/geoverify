<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use App\Domain\Coverage\Models\Road;
use Illuminate\Support\Facades\DB;

/**
 * The street network under a boundary, drawn by the database.
 *
 * Clipped and simplified in PostGIS rather than shipped whole and thinned in the
 * browser. Abuja Municipal holds eleven thousand kilometres of road across forty
 * one thousand ways: sending that to a map three hundred pixels tall would be
 * megabytes to draw something no eye could resolve, and the browser would spend
 * the time rather than the database.
 *
 * Two levels, and they are about what the map is for rather than about zoom. An
 * overview answers "where is this ground, and what runs through it", which the
 * trunk and primary network does on its own. Detail adds the streets somebody
 * would actually navigate by once they are looking at one town.
 */
final class ReadRoadNetwork
{
    public const OVERVIEW = 'overview';

    public const DETAIL = 'detail';

    /**
     * Simplification tolerance in degrees, per level.
     *
     * Roughly 55 m and 11 m at this latitude. Both are below the width of the
     * line as drawn at the zoom each level is meant for, so the saving is in
     * bytes rather than in anything a reader could see.
     */
    private const TOLERANCE = [
        self::OVERVIEW => 0.0005,
        self::DETAIL => 0.0001,
    ];

    /**
     * @param  list<mixed>  $bindings
     * @return array<string, mixed>
     */
    public function forBoundary(string $boundarySql, array $bindings, string $level = self::OVERVIEW): array
    {
        $classes = $level === self::DETAIL
            ? ['motorway', 'trunk', 'primary', 'secondary', 'tertiary', 'unclassified']
            : Road::MAJOR;

        $tolerance = self::TOLERANCE[$level] ?? self::TOLERANCE[self::OVERVIEW];

        $placeholders = implode(',', array_fill(0, count($classes), '?'));

        /** @var object{collection: string|null}|null $row */
        $row = DB::selectOne(<<<SQL
            with clipped as (
                select
                    roads.name,
                    roads.ref,
                    roads.highway,
                    ST_SimplifyPreserveTopology(
                        ST_Intersection(roads.geometry, boundary.geom), ?
                    ) as geom
                from roads
                cross join lateral ({$boundarySql}) as boundary(geom)
                where roads.highway in ({$placeholders})
                  and ST_Intersects(roads.geometry, boundary.geom)
            )
            select json_build_object(
                'type', 'FeatureCollection',
                'features', coalesce(json_agg(
                    json_build_object(
                        'type', 'Feature',
                        'geometry', ST_AsGeoJSON(geom, 5)::json,
                        'properties', json_build_object(
                            'name', name, 'ref', ref, 'highway', highway
                        )
                    )
                ), '[]'::json)
            )::text as collection
            from clipped
            -- An intersection can come back empty where a way only grazes the
            -- boundary, and an empty geometry is a feature that draws nothing
            -- and still costs bytes.
            where geom is not null and not ST_IsEmpty(geom)
        SQL, [$tolerance, ...$bindings, ...$classes]);

        $collection = $row === null ? null : $row->collection;

        return json_decode(
            $collection ?? '{"type":"FeatureCollection","features":[]}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The named roads worth putting a label on, longest first.
     *
     * Labels are drawn as HTML rather than as map symbols, because a symbol
     * layer needs a glyph endpoint and this application self hosts its fonts as
     * web fonts rather than as rendered glyph ranges. Fewer, larger labels in
     * the site's own typeface reads better here than a full label engine would,
     * and it costs no new asset pipeline.
     *
     * One label per name, at the midpoint of that name's longest run inside the
     * boundary. Labelling every way would put "Ahmadu Bello Way" on screen
     * eleven times, once per segment the network happens to be split into.
     *
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function labelsFor(string $boundarySql, array $bindings, int $limit = 28): array
    {
        $classes = Road::MAJOR;
        $placeholders = implode(',', array_fill(0, count($classes), '?'));

        $rows = DB::select(<<<SQL
            with clipped as (
                select
                    roads.name,
                    roads.highway,
                    ST_Intersection(roads.geometry, boundary.geom) as geom
                from roads
                cross join lateral ({$boundarySql}) as boundary(geom)
                where roads.name is not null
                  and roads.highway in ({$placeholders})
                  and ST_Intersects(roads.geometry, boundary.geom)
            ),
            merged as (
                select
                    name,
                    min(highway) as highway,
                    ST_LineMerge(ST_Union(geom)) as geom,
                    sum(ST_Length(geom::geography)) as metres
                from clipped
                where geom is not null and not ST_IsEmpty(geom)
                group by name
            )
            select
                name,
                highway,
                round(metres::numeric) as metres,
                ST_X(ST_LineInterpolatePoint(ST_GeometryN(ST_Multi(geom), 1), 0.5)) as lon,
                ST_Y(ST_LineInterpolatePoint(ST_GeometryN(ST_Multi(geom), 1), 0.5)) as lat
            from merged
            order by metres desc
            limit {$limit}
        SQL, [...$bindings, ...$classes]);

        return array_map(static fn (object $row): array => [
            'name' => (string) $row->name,
            'highway' => (string) $row->highway,
            'metres' => (int) $row->metres,
            'lon' => (float) $row->lon,
            'lat' => (float) $row->lat,
        ], $rows);
    }
}
