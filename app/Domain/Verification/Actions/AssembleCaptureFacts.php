<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Data\CaptureFacts;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Everything the signals need, computed in PostgreSQL.
 *
 * Every distance here is metres on the geography type, over the ellipsoid, which
 * is the only kind of distance this project accepts. No haversine in PHP, and no
 * degrees pretending to be metres.
 *
 * The statistics are SQL too. stddev_samp over a window of consecutive fixes is
 * one pass in the database; the same thing in PHP is the whole fix table pulled
 * into memory once per capture, and a review queue does this two hundred times.
 */
final class AssembleCaptureFacts
{
    public function __invoke(StructureObservation $observation): CaptureFacts
    {
        $fixes = $this->fixStatistics($observation->field_session_id);
        $trace = $this->traceShape($observation->field_session_id);
        $rhythm = $this->captureRhythm($observation);
        $place = $this->placement($observation);
        $photos = $this->photographs($observation);

        return new CaptureFacts(
            fixCount: (int) ($fixes->fix_count ?? 0),
            meanAccuracyM: $this->float($fixes->mean_accuracy_m ?? null),
            worstAccuracyM: $this->float($fixes->worst_accuracy_m ?? null),
            fixesOverFiveMetres: (int) ($fixes->fixes_over_five ?? 0),
            accuracyStdDev: $this->float($fixes->accuracy_stddev ?? null),
            distinctAccuracyValues: (int) ($fixes->distinct_accuracy ?? 0),
            mockFixCount: (int) ($fixes->mock_fixes ?? 0),
            zeroSatelliteFixCount: (int) ($fixes->zero_satellite_fixes ?? 0),
            satelliteAwareFixCount: (int) ($fixes->satellite_aware_fixes ?? 0),
            worstNetworkDivergenceM: $this->float($fixes->worst_divergence_m ?? null),
            networkComparableFixCount: (int) ($fixes->comparable_fixes ?? 0),
            traceLengthM: $this->float($trace->walked_m ?? null),
            traceEndToEndM: $this->float($trace->end_to_end_m ?? null),
            traceVertexCount: (int) ($trace->vertices ?? 0),
            segmentLengthStdDev: $this->float($trace->segment_stddev ?? null),
            meanSegmentLengthM: $this->float($trace->mean_segment_m ?? null),
            fastestImpliedSpeedMps: $this->float($fixes->fastest_implied_mps ?? null),
            captureCount: (int) ($rhythm->captures ?? 0),
            captureIntervalStdDev: $this->float($rhythm->interval_stddev ?? null),
            meanCaptureIntervalSeconds: $this->float($rhythm->mean_interval ?? null),
            insideAssignedCell: $place === null ? null : (bool) $place->inside,
            metresOutsideCell: $place === null ? null : $this->float($place->metres_outside),
            photographCount: (int) ($photos->photographs ?? 0),
            photographsFromDeviceCamera: (int) ($photos->from_camera ?? 0),
            photographsWithoutProvenance: (int) ($photos->without_provenance ?? 0),
            worstPhotographDistanceM: $this->float($photos->worst_distance_m ?? null),
        );
    }

    /**
     * Accuracy, mock flags, satellites, network divergence and implied speed.
     *
     * Implied speed is the distance between consecutive fixes over the time
     * between them, computed with a window function so a session of any length
     * costs one pass. The device's own reported speed is deliberately ignored:
     * a fabricated fix can claim any speed, but it cannot make two positions and
     * two timestamps agree with each other.
     */
    private function fixStatistics(?int $sessionId): stdClass
    {
        if ($sessionId === null) {
            return new stdClass;
        }

        $rows = DB::select(<<<'SQL'
            with ordered as (
                select
                    accuracy_m,
                    is_mock,
                    satellite_count,
                    point,
                    network_point,
                    recorded_at,
                    lag(point) over w as previous_point,
                    lag(recorded_at) over w as previous_at
                from position_fixes
                where field_session_id = ?
                window w as (order by recorded_at)
            )
            select
                count(*) as fix_count,
                avg(accuracy_m)::float8 as mean_accuracy_m,
                max(accuracy_m)::float8 as worst_accuracy_m,
                count(*) filter (where accuracy_m > 5) as fixes_over_five,
                stddev_samp(accuracy_m)::float8 as accuracy_stddev,
                count(distinct accuracy_m) as distinct_accuracy,
                count(*) filter (where is_mock) as mock_fixes,
                count(*) filter (where satellite_count = 0) as zero_satellite_fixes,
                count(*) filter (where satellite_count is not null) as satellite_aware_fixes,
                count(*) filter (where network_point is not null) as comparable_fixes,
                max(
                    st_distance(point::geography, network_point::geography)
                ) filter (where network_point is not null)::float8 as worst_divergence_m,
                max(
                    case
                        when previous_point is null then null
                        when extract(epoch from (recorded_at - previous_at)) <= 0 then null
                        else st_distance(point::geography, previous_point::geography)
                             / extract(epoch from (recorded_at - previous_at))
                    end
                )::float8 as fastest_implied_mps
            from ordered
        SQL, [$sessionId]);

        return $rows[0] ?? new stdClass;
    }

    /**
     * The shape of the walk: how far it ran, how far apart its ends are, and
     * how evenly its steps were spaced.
     *
     * The trace is a LineStringM whose M carries epoch seconds, so it is dumped
     * to points and measured pairwise rather than with ST_Length alone, which
     * would give the walked distance but nothing about its regularity.
     */
    private function traceShape(?int $sessionId): stdClass
    {
        if ($sessionId === null) {
            return new stdClass;
        }

        $rows = DB::select(<<<'SQL'
            with vertices as (
                select
                    (dp).path[1] as position,
                    (dp).geom as point
                from (
                    select st_dumppoints(trace) as dp
                    from field_sessions
                    where id = ? and trace is not null
                ) dumped
            ),
            segments as (
                select st_distance(
                    point::geography,
                    lag(point) over (order by position)::geography
                ) as length_m
                from vertices
            ),
            ends as (
                select
                    st_distance(
                        (select point from vertices order by position asc limit 1)::geography,
                        (select point from vertices order by position desc limit 1)::geography
                    )::float8 as end_to_end_m
            )
            select
                (select count(*) from vertices) as vertices,
                (select coalesce(sum(length_m), 0) from segments where length_m is not null)::float8 as walked_m,
                (select avg(length_m) from segments where length_m is not null)::float8 as mean_segment_m,
                (select stddev_samp(length_m) from segments where length_m is not null)::float8 as segment_stddev,
                (select end_to_end_m from ends) as end_to_end_m
        SQL, [$sessionId]);

        return $rows[0] ?? new stdClass;
    }

    /**
     * The gaps between this officer's captures in this session.
     *
     * Read across the whole session rather than around this one capture, because
     * a metronomic rhythm is a property of the day, and one capture cannot show
     * it on its own.
     */
    private function captureRhythm(StructureObservation $observation): stdClass
    {
        if ($observation->field_session_id === null) {
            return new stdClass;
        }

        $rows = DB::select(<<<'SQL'
            with gaps as (
                select extract(epoch from (
                    observed_at - lag(observed_at) over (order by observed_at)
                )) as seconds
                from structure_observations
                where field_session_id = ?
            )
            select
                (select count(*) from structure_observations where field_session_id = ?) as captures,
                avg(seconds)::float8 as mean_interval,
                stddev_samp(seconds)::float8 as interval_stddev
            from gaps
            where seconds is not null
        SQL, [$observation->field_session_id, $observation->field_session_id]);

        return $rows[0] ?? new stdClass;
    }

    /**
     * Whether the structure stands in the cell the assignment named.
     *
     * ST_Contains against the cell boundary, and when it is outside, the distance
     * to the boundary on the geography type so the answer is in metres.
     */
    private function placement(StructureObservation $observation): ?stdClass
    {
        if ($observation->assignment_id === null) {
            return null;
        }

        $rows = DB::select(<<<'SQL'
            select
                st_contains(cells.boundary, structures.centroid::geometry) as inside,
                st_distance(
                    structures.centroid,
                    st_boundary(cells.boundary)::geography
                )::float8 as metres_outside
            from structure_observations
            join structures on structures.id = structure_observations.structure_id
            join assignments on assignments.id = structure_observations.assignment_id
            join grid_cells cells on cells.id = assignments.grid_cell_id
            where structure_observations.id = ?
        SQL, [$observation->id]);

        return $rows[0] ?? null;
    }

    /**
     * The photographs attached to the structure and to its enterprises.
     *
     * distance_from_subject_m is written at ingestion from the EXIF position and
     * the subject's own, so the comparison is already stored rather than redone.
     */
    private function photographs(StructureObservation $observation): stdClass
    {
        $rows = DB::select(<<<'SQL'
            select
                count(*) as photographs,
                count(*) filter (where from_device_camera is true) as from_camera,
                count(*) filter (where from_device_camera is not true) as without_provenance,
                max(distance_from_subject_m)::float8 as worst_distance_m
            from media
            where (mediable_type = ? and mediable_id = ?)
               or (mediable_type = ? and mediable_id in (
                       select id from enterprises where structure_id = ?
                   ))
        SQL, [
            (new Structure)->getMorphClass(),
            $observation->structure_id,
            (new Enterprise)->getMorphClass(),
            $observation->structure_id,
        ]);

        return $rows[0] ?? new stdClass;
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
