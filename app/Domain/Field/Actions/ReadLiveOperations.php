<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Registry\Models\Structure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who is out there right now, and how the day is going.
 *
 * "Right now" is defined by the last fix a device sent, not by whether a session
 * was politely closed. A handset that ran out of battery on a rooftop leaves a
 * session open forever, and an officer who finished at four does not stop being
 * findable because the app was killed before it could post an end.
 *
 * Every position and every trace here is serialised by PostGIS. Nothing is
 * projected or measured in PHP.
 */
final class ReadLiveOperations
{
    /** Past this without a fix, an officer is not working, they are elsewhere. */
    private const ACTIVE_MINUTES = 45;

    /** Vertices kept per trace. Enough to read a day's shape on a map. */
    private const TRACE_POINTS = 300;

    /**
     * @return array{officers: list<array<string, mixed>>, day: array<string, mixed>}
     */
    public function __invoke(?int $coverageAreaId = null): array
    {
        return [
            'officers' => $this->officers($coverageAreaId),
            'day' => $this->day($coverageAreaId),
        ];
    }

    /**
     * One row per officer with a session today, whether still moving or not.
     *
     * @return list<array<string, mixed>>
     */
    private function officers(?int $coverageAreaId): array
    {
        $where = '';
        $bindings = [self::TRACE_POINTS, Structure::STATUS_SUBMITTED];

        if ($coverageAreaId !== null) {
            $where = 'and cells.coverage_area_id = ?';
            $bindings[] = $coverageAreaId;
        }

        $bindings[] = self::ACTIVE_MINUTES;

        $rows = DB::select(<<<SQL
            with today as (
                select sessions.*
                  from field_sessions sessions
                 where sessions.started_at >= now() - interval '18 hours'
            ),
            latest as (
                select distinct on (today.user_id)
                       today.id,
                       today.user_id,
                       today.assignment_id,
                       today.started_at,
                       today.ended_at,
                       today.trace,
                       today.distance_m,
                       today.integrity_verdict
                  from today
                 order by today.user_id, today.started_at desc
            ),
            last_fix as (
                select distinct on (fixes.field_session_id)
                       fixes.field_session_id,
                       fixes.recorded_at,
                       fixes.accuracy_m,
                       fixes.is_mock,
                       st_x(fixes.point) as longitude,
                       st_y(fixes.point) as latitude
                  from position_fixes fixes
                 where fixes.field_session_id in (select id from latest)
                 order by fixes.field_session_id, fixes.recorded_at desc
            ),
            sampled as (
                select session_id,
                       json_agg(json_build_array(lon, lat) order by idx) as points
                  from (
                      select session_id, idx, st_x(geom) as lon, st_y(geom) as lat,
                             count(*) over (partition by session_id) as total
                        from (
                            select latest.id as session_id,
                                   (dumped.dp).path[1] as idx,
                                   (dumped.dp).geom as geom
                              from latest
                              join lateral (
                                  select st_dumppoints(latest.trace) as dp
                              ) dumped on latest.trace is not null
                        ) vertices
                  ) counted
                 where total <= ? or idx % greatest(1, (total / 300)::int) = 0
                 group by session_id
            )
            select
                users.id as officer_id,
                users.name as officer,
                users.staff_ref,
                latest.id as session_id,
                latest.started_at,
                latest.ended_at,
                latest.distance_m,
                latest.integrity_verdict,
                last_fix.recorded_at as last_seen_at,
                last_fix.longitude,
                last_fix.latitude,
                last_fix.accuracy_m,
                last_fix.is_mock,
                sampled.points as trace,
                to_hex(cells.h3_index) as h3,
                cells.coverage_area_id,
                (
                    select count(*) from structure_observations
                     where field_session_id = latest.id
                ) as captures,
                (
                    select count(*) from structure_observations
                     where field_session_id = latest.id and status = ?
                ) as awaiting,
                (
                    select avg(confidence_score) from structure_observations
                     where field_session_id = latest.id and confidence_score is not null
                )::float8 as mean_confidence
              from latest
              join users on users.id = latest.user_id
              left join last_fix on last_fix.field_session_id = latest.id
              left join sampled on sampled.session_id = latest.id
              left join assignments on assignments.id = latest.assignment_id
              left join grid_cells cells on cells.id = assignments.grid_cell_id
             where true {$where}
             order by (last_fix.recorded_at >= now() - make_interval(mins => ?)) desc nulls last,
                      last_fix.recorded_at desc nulls last
        SQL, $bindings);

        return array_map(function (object $row): array {
            $lastSeen = $row->last_seen_at;

            return [
                'officerId' => (int) $row->officer_id,
                'officer' => (string) $row->officer,
                'staffRef' => $row->staff_ref,
                'sessionId' => (int) $row->session_id,
                'startedAt' => $this->iso($row->started_at),
                'endedAt' => $this->iso($row->ended_at),
                'lastSeenAt' => $this->iso($lastSeen),
                'active' => $lastSeen !== null
                    && strtotime((string) $lastSeen) >= strtotime('-'.self::ACTIVE_MINUTES.' minutes'),
                'longitude' => $row->longitude === null ? null : (float) $row->longitude,
                'latitude' => $row->latitude === null ? null : (float) $row->latitude,
                'accuracyM' => $row->accuracy_m === null ? null : round((float) $row->accuracy_m, 1),
                'isMock' => (bool) $row->is_mock,
                'distanceM' => (int) $row->distance_m,
                'integrityVerdict' => (string) $row->integrity_verdict,
                'h3' => $row->h3,
                'captures' => (int) $row->captures,
                'awaiting' => (int) $row->awaiting,
                'meanConfidence' => $row->mean_confidence === null
                    ? null
                    : (int) round((float) $row->mean_confidence),
                'trace' => $row->trace === null
                    ? []
                    : json_decode((string) $row->trace, true, 512, JSON_THROW_ON_ERROR),
            ];
        }, $rows);
    }

    /**
     * A raw timestamp from a database row, made unambiguous for a browser.
     *
     * The column carries no offset, so a browser parses it as its own local
     * time. On a server running UTC and a console open in Lagos that is an hour
     * of error in "last seen", which is exactly the number a supervisor uses to
     * decide whether to ring somebody. Carbon reads it in the application
     * timezone and writes the offset back out.
     */
    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * The day in four numbers, which is what a supervisor glances at.
     *
     * @return array<string, mixed>
     */
    private function day(?int $coverageAreaId): array
    {
        $where = '';
        $bindings = [];

        if ($coverageAreaId !== null) {
            $where = 'and structures.coverage_area_id = ?';
            $bindings[] = $coverageAreaId;
        }

        $row = DB::selectOne(<<<SQL
            select
                count(*) as captures,
                count(distinct observations.captured_by) as officers,
                avg(observations.confidence_score)::float8 as mean_confidence,
                count(*) filter (where observations.confidence_score < 45) as contested
              from structure_observations observations
              join structures on structures.id = observations.structure_id
             where observations.observed_at >= current_date {$where}
        SQL, $bindings);

        return [
            'captures' => (int) ($row->captures ?? 0),
            'officers' => (int) ($row->officers ?? 0),
            'meanConfidence' => $row?->mean_confidence === null
                ? null
                : (int) round((float) $row->mean_confidence),
            'contested' => (int) ($row->contested ?? 0),
        ];
    }
}
