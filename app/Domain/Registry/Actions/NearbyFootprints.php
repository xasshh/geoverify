<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use Illuminate\Support\Facades\DB;

/**
 * The buildings around a point, drawn by the database.
 *
 * This is the location picker, and it is deliberately not a map. A tile map is
 * the heaviest thing that could go on the one screen where weight matters most:
 * the audience is on mobile data, often standing in the shop, and a person who
 * cannot finish this form does not become a listing. So instead of shipping a
 * renderer and fetching tiles, PostGIS returns the handful of building outlines
 * within sixty metres, already projected to metres and already centred on the
 * person, as SVG path data. A few hundred bytes, no library, no tile requests.
 *
 * It also asks a better question. A map asks "where are you", which the device
 * already answered; this asks "which of these buildings is yours", which is the
 * thing the device gets wrong and the person knows for certain.
 */
final class NearbyFootprints
{
    /** Far enough to include the building next door, close enough to stay legible. */
    private const RADIUS_M = 60;

    private const LIMIT = 12;

    /**
     * @return list<array{id: int, metres: int, area_m2: int, path: string, occupied: bool}>
     */
    public function around(float $longitude, float $latitude): array
    {
        /** @var list<object{id: int, metres: float, area_m2: float|null, path: string|null, occupied: bool}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH me AS (
                SELECT ST_SetSRID(ST_Point(?, ?), 4326)              AS wgs,
                       ST_Transform(ST_SetSRID(ST_Point(?, ?), 4326), 3857) AS mercator
            )
            SELECT f.id,
                   ST_Distance(f.footprint::geography, me.wgs::geography) AS metres,
                   f.area_m2,
                   -- Translated so the person sits at the origin, and in metres
                   -- rather than degrees, so the client can draw it without
                   -- knowing anything about projections. Web Mercator distorts
                   -- scale by latitude, which over sixty metres at this
                   -- latitude is far below the width of a drawn line.
                   ST_AsSVG(
                       ST_Translate(
                           ST_Transform(f.footprint, 3857),
                           -ST_X(me.mercator), -ST_Y(me.mercator)
                       ), 0, 2
                   ) AS path,
                   (f.matched_structure_id IS NOT NULL) AS occupied
              FROM external_footprints f, me
             WHERE f.dismissed IS NOT TRUE
               AND ST_DWithin(f.footprint::geography, me.wgs::geography, ?)
             ORDER BY metres
             LIMIT ?
        SQL, [$longitude, $latitude, $longitude, $latitude, self::RADIUS_M, self::LIMIT]);

        return array_values(array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'metres' => (int) round((float) $r->metres),
            'area_m2' => (int) round((float) ($r->area_m2 ?? 0)),
            'path' => (string) $r->path,
            'occupied' => (bool) $r->occupied,
        ], array_filter($rows, static fn (object $r): bool => $r->path !== null)));
    }
}
