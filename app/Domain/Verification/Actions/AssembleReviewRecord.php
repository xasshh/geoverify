<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Models\ObservationSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One capture, laid out as the three questions a supervisor actually asks.
 *
 * Presence, then identification, then plausibility. Not map, photos, form. The
 * flags travel with the evidence that produced them rather than being collected
 * into a sidebar, because "accuracy over 5 m on 2 fixes" means nothing next to a
 * photograph and everything next to the fix list.
 */
final class AssembleReviewRecord
{
    /** The review screen draws the mark at 240px, so it can carry the route. */
    private const RECORD_TRACE_POINTS = 400;

    public function __construct(private readonly BuildReviewQueue $queue) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(StructureObservation $observation): array
    {
        $session = $this->session($observation);
        $trace = $observation->field_session_id === null
            ? []
            : ($this->queue->tracesFor([$observation->field_session_id], self::RECORD_TRACE_POINTS)[$observation->field_session_id] ?? []);

        return [
            'id' => $observation->id,
            'score' => $observation->confidence_score,
            'status' => $observation->status,
            'observedAt' => $observation->observed_at->toIso8601String(),
            'structureType' => $observation->structure_type,
            'occupancyStatus' => $observation->occupancy_status,
            'floors' => $observation->floors,
            'unitCount' => $observation->unit_count,
            'notes' => $observation->notes,
            'accuracyM' => $observation->capture_accuracy_m,

            'signals' => $this->signals($observation),
            'presence' => $session,
            'trace' => $trace,
            'photographs' => $this->photographs($observation),
            'enterprises' => $this->enterprises($observation),
            'officer' => $this->officer($observation),
            'place' => $this->place($observation),
        ];
    }

    /**
     * Every signal reading, grouped by the question it answers.
     *
     * Grouped rather than filtered: a supervisor needs to see that presence was
     * asked and answered ok just as much as they need to see it flagged, because
     * a column with nothing in it is ambiguous between "clean" and "not checked".
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function signals(StructureObservation $observation): array
    {
        $grouped = [];

        $readings = ObservationSignal::query()
            ->where('structure_observation_id', $observation->id)
            ->orderByDesc('deduction')
            ->orderBy('signal')
            ->get();

        foreach ($readings as $reading) {
            $grouped[$reading->question->value][] = [
                'signal' => $reading->signal,
                'verdict' => $reading->verdict->value,
                'message' => $reading->message,
                'weight' => $reading->weight,
                'deduction' => $reading->deduction,
                'evidence' => $reading->evidence,
            ];
        }

        return $grouped;
    }

    /**
     * The session behind the capture, summarised in PostGIS.
     *
     * @return array<string, mixed>|null
     */
    private function session(StructureObservation $observation): ?array
    {
        if ($observation->field_session_id === null) {
            return null;
        }

        $row = DB::selectOne(<<<'SQL'
            select
                sessions.started_at,
                sessions.ended_at,
                sessions.integrity_verdict,
                sessions.app_version,
                (select count(*) from position_fixes where field_session_id = sessions.id) as fix_count,
                (select avg(accuracy_m) from position_fixes where field_session_id = sessions.id)::float8 as mean_accuracy_m,
                (select max(accuracy_m) from position_fixes where field_session_id = sessions.id)::float8 as worst_accuracy_m,
                st_length(sessions.trace::geography)::float8 as walked_m
            from field_sessions sessions
            where sessions.id = ?
        SQL, [$observation->field_session_id]);

        if ($row === null) {
            return null;
        }

        return [
            'startedAt' => $this->iso($row->started_at),
            'endedAt' => $this->iso($row->ended_at),
            'integrityVerdict' => $row->integrity_verdict,
            'appVersion' => $row->app_version,
            'fixCount' => (int) $row->fix_count,
            'meanAccuracyM' => $row->mean_accuracy_m === null ? null : round((float) $row->mean_accuracy_m, 1),
            'worstAccuracyM' => $row->worst_accuracy_m === null ? null : round((float) $row->worst_accuracy_m, 1),
            'walkedM' => $row->walked_m === null ? null : (int) round((float) $row->walked_m),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function photographs(StructureObservation $observation): array
    {
        $rows = DB::select(<<<'SQL'
            select id, kind, from_device_camera, distance_from_subject_m, captured_at
            from media
            where (mediable_type = ? and mediable_id = ?)
               or (mediable_type = ? and mediable_id in (
                       select id from enterprises where structure_id = ?
                   ))
            order by kind
        SQL, [
            (new Structure)->getMorphClass(),
            $observation->structure_id,
            (new Enterprise)->getMorphClass(),
            $observation->structure_id,
        ]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'fromDeviceCamera' => $row->from_device_camera === null ? null : (bool) $row->from_device_camera,
            'distanceM' => $row->distance_from_subject_m === null
                ? null
                : round((float) $row->distance_from_subject_m, 1),
            'capturedAt' => $row->captured_at === null
                ? null
                : Carbon::parse((string) $row->captured_at)->toIso8601String(),
        ], $rows);
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
     * @return list<array<string, mixed>>
     */
    private function enterprises(StructureObservation $observation): array
    {
        $rows = DB::select(<<<'SQL'
            select distinct on (enterprises.id)
                enterprises.id,
                observations.trading_name,
                observations.registered_name,
                observations.sector_code,
                observations.scale_band,
                observations.employee_band,
                observations.operating_status,
                observations.years_at_location,
                observations.signage_observed
            from enterprises
            join enterprise_observations observations on observations.enterprise_id = enterprises.id
            where enterprises.structure_id = ?
            order by enterprises.id, observations.observed_at desc
        SQL, [$observation->structure_id]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'tradingName' => (string) $row->trading_name,
            'registeredName' => $row->registered_name,
            'sectorCode' => $row->sector_code,
            'scaleBand' => $row->scale_band,
            'employeeBand' => $row->employee_band,
            'operatingStatus' => (string) $row->operating_status,
            'yearsAtLocation' => $row->years_at_location === null ? null : (int) $row->years_at_location,
            'signageObserved' => (bool) $row->signage_observed,
        ], $rows);
    }

    /**
     * The officer's record, so a low score is judged against a person's history
     * rather than in isolation. A first bad day and a pattern are not the same
     * finding, and the screen should not make them look alike.
     *
     * @return array<string, mixed>
     */
    private function officer(StructureObservation $observation): array
    {
        $row = DB::selectOne(<<<'SQL'
            select
                users.id,
                users.name,
                count(observations.id) as captures,
                avg(observations.confidence_score)::float8 as mean_confidence,
                count(*) filter (where observations.status = ?) as returned
            from users
            left join structure_observations observations on observations.captured_by = users.id
            where users.id = ?
            group by users.id, users.name
        SQL, [Structure::STATUS_REJECTED, $observation->captured_by]);

        $captures = $row === null ? 0 : (int) $row->captures;

        return [
            'id' => $observation->captured_by,
            'name' => $row === null ? '' : (string) $row->name,
            'captures' => $captures,
            'meanConfidence' => $row?->mean_confidence === null
                ? null
                : (int) round((float) $row->mean_confidence),
            'returnRate' => $captures === 0 ? null : round(((int) $row->returned / $captures) * 100, 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function place(StructureObservation $observation): array
    {
        $row = DB::selectOne(<<<'SQL'
            select
                to_hex(cells.h3_index) as h3,
                structures.plus_code,
                ward.name as ward,
                lga.name as lga,
                st_y(structures.centroid::geometry)::float8 as latitude,
                st_x(structures.centroid::geometry)::float8 as longitude
            from structures
            join grid_cells cells on cells.id = structures.grid_cell_id
            left join admin_boundaries ward on ward.id = structures.ward_id
            left join admin_boundaries lga on lga.id = structures.lga_id
            where structures.id = ?
        SQL, [$observation->structure_id]);

        return [
            'h3' => $row === null ? null : (string) $row->h3,
            'plusCode' => $row?->plus_code,
            'ward' => $row?->ward,
            'lga' => $row?->lga,
            'latitude' => $row === null ? null : (float) $row->latitude,
            'longitude' => $row === null ? null : (float) $row->longitude,
        ];
    }
}
