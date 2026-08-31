<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use App\Domain\Registry\Models\Enterprise;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * The business register, one row per enterprise.
 *
 * This is the file a client opens first, so the columns are ordered the way a
 * reader needs them rather than the way the tables are shaped: what the business
 * is, then where it is, then how well we know it, then who established that and
 * when. Coordinates are last because nobody scanning a register reads longitude
 * first, and they are separate columns rather than a WKT blob because the file
 * has to survive being opened in a spreadsheet.
 *
 * No identity number leaves here. A CAC registration is public record and is
 * given in full; a NIN is reduced to whether a check happened and when, because
 * the hash we hold would still let a recipient link a person across files and no
 * client needs that to know the person was verified.
 */
final class EnterpriseCsv
{
    /** @var list<string> */
    private const COLUMNS = [
        'trading_name',
        'registered_name',
        'sector_code',
        'subsector_code',
        'scale_band',
        'employee_band',
        'operating_status',
        'years_at_location',
        'signage_observed',
        'structure_type',
        'occupancy_status',
        'floors',
        'unit_label',
        'floor',
        'plus_code',
        'ward',
        'lga',
        'state',
        'h3_index',
        'cac_number',
        'identity_verified',
        'identity_verified_at',
        'record_status',
        'confidence_score',
        'captured_by',
        'captured_at',
        'accuracy_m',
        'longitude',
        'latitude',
    ];

    /**
     * Yields the header, then one CSV line at a time.
     *
     * @return Generator<int, string>
     */
    public function stream(ExportScope $scope): Generator
    {
        yield $this->line(self::COLUMNS);

        foreach ($this->rows($scope) as $row) {
            yield $this->line(array_map(
                static fn (string $column): string => (string) ($row->{$column} ?? ''),
                self::COLUMNS,
            ));
        }
    }

    /**
     * One CSV line, quoted by PHP rather than by hand.
     *
     * A trading name in this register can legitimately contain a comma, a quote
     * or a newline, and hand rolled joining is how a register becomes unopenable
     * three rows in.
     *
     * @param  list<string>  $values
     */
    private function line(array $values): string
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $values, ',', '"', '\\');
        rewind($handle);
        $line = (string) stream_get_contents($handle);
        fclose($handle);

        return $line;
    }

    /**
     * @return Generator<int, object>
     */
    private function rows(ExportScope $scope): Generator
    {
        $bindings = [$scope->area->id];
        $cellClause = '';

        if ($scope->cell !== null) {
            $cellClause = 'and structures.grid_cell_id = ?';
            $bindings[] = $scope->cell->id;
        }

        $statuses = $scope->statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $bindings = [...$bindings, ...$statuses];

        return DB::cursor(<<<SQL
            select
                enterprises.trading_name,
                enterprises.registered_name,
                enterprises.sector_code,
                enterprises.subsector_code,
                enterprises.scale_band,
                latest.employee_band,
                enterprises.operating_status,
                latest.years_at_location,
                case when latest.signage_observed then 'yes' else 'no' end as signage_observed,
                structures.structure_type,
                structures.occupancy_status,
                structures.floors,
                enterprises.unit_label,
                enterprises.floor,
                structures.plus_code,
                ward.name as ward,
                lga.name as lga,
                state.name as state,
                to_hex(structures.h3_index) as h3_index,
                cac.reference_token as cac_number,
                case when verified.id is null then 'no' else 'yes' end as identity_verified,
                to_char(verified.verified_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') as identity_verified_at,
                enterprises.status as record_status,
                structures.confidence_score,
                officer.name as captured_by,
                to_char(enterprises.captured_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') as captured_at,
                structures.capture_accuracy_m as accuracy_m,
                round(st_x(structures.centroid::geometry)::numeric, 7) as longitude,
                round(st_y(structures.centroid::geometry)::numeric, 7) as latitude
            from enterprises
            join structures on structures.id = enterprises.structure_id
            join users officer on officer.id = enterprises.captured_by
            left join admin_boundaries ward on ward.id = structures.ward_id
            left join admin_boundaries lga on lga.id = structures.lga_id
            left join admin_boundaries state on state.id = structures.state_id
            left join lateral (
                select employee_band, years_at_location, signage_observed
                  from enterprise_observations
                 where enterprise_id = enterprises.id
                 order by observed_at desc, id desc
                 limit 1
            ) latest on true
            -- Public record, so it is given in full.
            left join lateral (
                select reference_token
                  from identity_claims
                 where claimable_type = ? and claimable_id = enterprises.id and kind = 'cac'
                 order by id desc limit 1
            ) cac on true
            -- Everything else is reduced to the fact of a check.
            left join lateral (
                select id, verified_at
                  from identity_claims
                 where claimable_type = ? and claimable_id = enterprises.id
                   and kind <> 'cac' and verified_at is not null
                 order by verified_at desc limit 1
            ) verified on true
            where structures.coverage_area_id = ?
              {$cellClause}
              and structures.status in ({$placeholders})
            order by ward.name nulls last, enterprises.trading_name, enterprises.id
        SQL, $this->orderedBindings($scope, $bindings));
    }

    /**
     * The lateral joins bind before the where clause, so the morph class goes
     * in front of the scope.
     *
     * @param  list<mixed>  $tail
     * @return list<mixed>
     */
    private function orderedBindings(ExportScope $scope, array $tail): array
    {
        $morph = (new Enterprise)->getMorphClass();

        return [$morph, $morph, ...$tail];
    }
}
