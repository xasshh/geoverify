<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Models\ObservationSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'footprint' => $this->footprint($observation),
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
            select id, kind, from_device_camera, distance_from_subject_m, captured_at,
                   disk, disk_path, thumb_path
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

        return array_map(static function (object $row): array {
            /*
             * The photograph itself, not just a line saying one exists.
             *
             * A supervisor deciding whether a capture is sound cannot do it
             * from the word "facade". This screen listed the kind and the
             * metadata and never showed the picture, which made the whole
             * Identification column an assertion rather than evidence.
             *
             * Short lived and signed, the same contract object storage offers.
             * The thumbnail is preferred where one exists: this is a contact
             * sheet, and pulling three full size photographs to draw them at
             * 160 pixels is the officer's upload wasted twice.
             */
            $diskName = (string) ($row->disk ?: 'media');
            $disk = Storage::disk($diskName);
            $path = is_string($row->thumb_path) && $row->thumb_path !== ''
                ? $row->thumb_path
                : (string) $row->disk_path;

            return [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'url' => $disk->exists($path) ? Media::signedUrl($diskName, $path, 30) : null,
                'fromDeviceCamera' => $row->from_device_camera === null ? null : (bool) $row->from_device_camera,
                'distanceM' => $row->distance_from_subject_m === null
                    ? null
                    : round((float) $row->distance_from_subject_m, 1),
                'capturedAt' => $row->captured_at === null
                    ? null
                    : Carbon::parse((string) $row->captured_at)->toIso8601String(),
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
     * The building's outline, in metres, about its own centre.
     *
     * Projected to the UTM zone the building actually stands in, so the numbers
     * that come back are metres on the ground rather than degrees or Mercator
     * metres that are one percent long at this latitude. The console draws the
     * massing from this, which is why it has to be the real outline: a generic
     * box beside a real photograph is a drawing of a building that does not
     * exist.
     *
     * Null for a kiosk, a container or anything else with no detected outline,
     * and that is a real answer rather than a gap. Nothing is invented to fill
     * the space.
     *
     * @return array<string, mixed>|null
     */
    private function footprint(StructureObservation $observation): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            with source as (
                select
                    footprint as geom,
                    -- The UTM zone under the building. Nigeria is northern
                    -- hemisphere throughout, so the 326xx band holds.
                    32600 + floor((st_x(st_centroid(footprint)) + 180) / 6)::int + 1 as utm
                from structures
                where id = ? and footprint is not null
            ),
            centred as (
                select st_translate(
                    st_transform(geom, utm),
                    -st_x(st_centroid(st_transform(geom, utm))),
                    -st_y(st_centroid(st_transform(geom, utm)))
                ) as geom
                from source
            )
            select
                st_asgeojson(geom) as outline,
                (st_xmax(geom) - st_xmin(geom))::float8 as width_m,
                (st_ymax(geom) - st_ymin(geom))::float8 as depth_m,
                st_area(geom)::float8 as area_m2
            from centred
        SQL, [$observation->structure_id]);

        if ($row === null) {
            return null;
        }

        /** @var array{coordinates?: array<int, array<int, array<int, float>>>} $outline */
        $outline = json_decode((string) $row->outline, true, 512, JSON_THROW_ON_ERROR);

        return [
            // The exterior ring only. A courtyard is not drawn: at this size it
            // would read as a second building rather than a hole.
            'ring' => array_map(
                static fn (array $point): array => [
                    round($point[0], 2),
                    round($point[1], 2),
                ],
                $outline['coordinates'][0] ?? [],
            ),
            'widthM' => round((float) $row->width_m, 1),
            'depthM' => round((float) $row->depth_m, 1),
            'areaM2' => round((float) $row->area_m2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enterprises(StructureObservation $observation): array
    {
        $rows = DB::select(<<<'SQL'
            select distinct on (enterprises.id)
                enterprises.id,
                enterprises.unit_label,
                enterprises.floor,
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
            'unitLabel' => $row->unit_label,
            'floor' => $row->floor === null ? null : (int) $row->floor,
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
