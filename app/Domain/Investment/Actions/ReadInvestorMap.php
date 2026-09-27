<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Registry\Actions\DirectoryVisibility;
use Illuminate\Support\Facades\DB;

/**
 * The explore map: where verified businesses are, as H3 density, and where the
 * opportunities are, as cells.
 *
 * Density is counted at resolution 6 (about 36 km²) over the directory-visible
 * population, and a cell holding fewer than three verified businesses is not
 * drawn at all: the directory's own floor for a breakdown, applied for the
 * same reason, so the map can never point at one shop that did not ask to be
 * found. Opportunities are drawn at the centre of their resolution 7 cell,
 * which is as precise as the dossier is. Every geometry is built in PostgreSQL.
 */
final class ReadInvestorMap
{
    public const DENSITY_RESOLUTION = 6;

    public const OPPORTUNITY_RESOLUTION = 7;

    public const FLOOR = 3;

    /** @return array<string, mixed> */
    public function __invoke(int $organisationId, bool $withOpportunities): array
    {
        $hexes = DB::select('
            SELECT cell::text AS cell, n, ST_AsGeoJSON(h3_cell_to_boundary_geometry(cell)) AS geometry
            FROM (
                SELECT h3_cell_to_parent(s.h3_index::h3index, '.self::DENSITY_RESOLUTION.') AS cell, count(*) AS n
                '.DirectoryVisibility::FROM.'
                WHERE '.DirectoryVisibility::WHERE."
                  AND s.origin = 'field'
                  AND s.h3_index IS NOT NULL
                GROUP BY 1
                HAVING count(*) >= ".self::FLOOR.'
            ) cells
        ');

        $states = DB::select("
            SELECT name, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary::geometry, 0.005)) AS geometry
            FROM admin_boundaries
            WHERE level = 'state' AND boundary IS NOT NULL
        ");

        $extent = DB::selectOne("
            SELECT ST_XMin(e) AS w, ST_YMin(e) AS s, ST_XMax(e) AS e, ST_YMax(e) AS n
            FROM (SELECT ST_Extent(boundary::geometry) AS e FROM admin_boundaries WHERE level = 'state') x
        ");

        $opportunities = $withOpportunities ? DB::select('
            SELECT o.id, e.trading_name AS name,
                   ST_AsGeoJSON(h3_cell_to_geometry(h3_cell_to_parent(s.h3_index::h3index, '.self::OPPORTUNITY_RESOLUTION.'))) AS geometry
            '.ReadOpportunities::FROM.'
            WHERE '.ReadOpportunities::WHERE.' AND s.h3_index IS NOT NULL
        ') : [];

        $feature = static fn (object $row, array $properties): array => [
            'type' => 'Feature',
            'geometry' => json_decode((string) $row->geometry, true),
            'properties' => $properties,
        ];

        return [
            'hexes' => [
                'type' => 'FeatureCollection',
                'features' => array_map(fn (object $r): array => $feature($r, ['cell' => (string) $r->cell, 'count' => (int) $r->n]), $hexes),
            ],
            'states' => [
                'type' => 'FeatureCollection',
                'features' => array_map(fn (object $r): array => $feature($r, ['name' => (string) $r->name]), $states),
            ],
            'opportunities' => [
                'type' => 'FeatureCollection',
                'features' => array_map(fn (object $r): array => $feature($r, ['id' => (int) $r->id, 'name' => (string) $r->name]), $opportunities),
            ],
            'bounds' => $extent === null || $extent->w === null
                ? [[2.67, 4.27], [14.68, 13.89]]
                : [[(float) $extent->w, (float) $extent->s], [(float) $extent->e, (float) $extent->n]],
            'floor' => self::FLOOR,
        ];
    }
}
