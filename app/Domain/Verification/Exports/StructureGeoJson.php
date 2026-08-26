<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use App\Domain\Registry\Models\Structure;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * The structures in a scope, as a GeoJSON FeatureCollection.
 *
 * Streamed feature by feature rather than assembled and returned. A mandate can
 * hold tens of thousands of accepted records, and building one string for them
 * would put the whole register in memory to hand over a file.
 *
 * Every geometry is serialised by PostGIS. No coordinate is assembled in PHP,
 * which is the same rule the rest of this system runs on and matters more here
 * than anywhere: this file is the deliverable.
 */
final class StructureGeoJson
{
    /**
     * Yields the file in order: the envelope, then one feature at a time.
     *
     * @return Generator<int, string>
     */
    public function stream(ExportScope $scope): Generator
    {
        yield '{"type":"FeatureCollection","name":'.json_encode($scope->label(), JSON_THROW_ON_ERROR)
            .',"crs":{"type":"name","properties":{"name":"urn:ogc:def:crs:OGC:1.3/CRS84"}},"features":[';

        $first = true;

        foreach ($this->rows($scope) as $row) {
            yield ($first ? '' : ',').(string) $row->feature;
            $first = false;
        }

        yield ']}';
    }

    /**
     * @return Generator<int, object>
     */
    private function rows(ExportScope $scope): Generator
    {
        // The morph class is bound rather than written into the SQL: there is
        // no morph map on this project, so it is a namespaced class name and
        // escaping backslashes through a heredoc into Postgres is a way to be
        // quietly wrong. It is in the select list, so it binds first.
        $bindings = [(new Structure)->getMorphClass(), $scope->area->id];
        $cellClause = '';

        if ($scope->cell !== null) {
            $cellClause = 'and structures.grid_cell_id = ?';
            $bindings[] = $scope->cell->id;
        }

        $statuses = $scope->statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $bindings = [...$bindings, ...$statuses];

        // ST_AsGeoJSON on the geometry, and the whole feature assembled by
        // PostgreSQL so the row arrives ready to write.
        //
        // There is no identity reference here beyond whether a check happened.
        // A CAC number is public record and belongs in the register; a hashed
        // NIN is a stable identifier that would let a recipient link a person
        // across files, and no client needs that to know the person was
        // verified.
        return DB::cursor(<<<SQL
            select json_build_object(
                'type', 'Feature',
                'id', structures.id,
                'geometry', st_asgeojson(structures.centroid::geometry, 7)::json,
                'properties', json_build_object(
                    'h3', to_hex(structures.h3_index),
                    'plus_code', structures.plus_code,
                    'structure_type', structures.structure_type,
                    'layout_class', structures.layout_class,
                    'floors', structures.floors,
                    'unit_count', structures.unit_count,
                    'condition', structures.condition,
                    'occupancy_status', structures.occupancy_status,
                    'status', structures.status,
                    'confidence_score', structures.confidence_score,
                    'ward', ward.name,
                    'lga', lga.name,
                    'state', state.name,
                    'captured_at', to_char(structures.captured_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
                    'captured_by', officer.name,
                    'capture_accuracy_m', structures.capture_accuracy_m,
                    'enterprises', (
                        select count(*) from enterprises where structure_id = structures.id
                    ),
                    'photographs', (
                        select count(*) from media
                         where mediable_type = ?
                           and mediable_id = structures.id
                    )
                )
            )::text as feature
            from structures
            join users officer on officer.id = structures.captured_by
            left join admin_boundaries ward on ward.id = structures.ward_id
            left join admin_boundaries lga on lga.id = structures.lga_id
            left join admin_boundaries state on state.id = structures.state_id
            where structures.coverage_area_id = ?
              {$cellClause}
              and structures.status in ({$placeholders})
            order by structures.id
        SQL, $bindings);
    }
}
