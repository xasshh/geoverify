<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Actions\ResolveAdminHierarchy;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An Enumerate site visit, from the supervisor's pin to the supervisor's word.
 *
 * Every step appends to verification_events against the request, so the
 * request's own timeline is the whole story. Money moves once, when the
 * supervisor accepts a Tier 2 visit: the held price becomes income, because
 * that is when the work the requester paid for has been done and checked.
 */
final class ManageEnumerateVisits
{
    /** Nigeria's extent, loosely: a pin outside it is a typo, not a business. */
    private const LAT = [4.0, 14.0];

    private const LNG = [2.5, 15.0];

    /** What an officer is told about yesterday's daily visit. */
    public const DAY_OVER = 'That day is over. Today’s visit is on your Today screen.';

    public function __construct(
        private readonly ResolveAdminHierarchy $admin,
        private readonly FieldMessaging $messages,
        private readonly PostTransaction $post,
        private readonly ManageMonitoring $monitoring,
        private readonly ScoreEnumerateRequest $score,
    ) {}

    /** A supervisor pins the premises and sends an officer. */
    public function assign(EnumerateRequest $request, User $agent, User $supervisor, float $latitude, float $longitude): EnumerateVisit
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor sends an officer.');
        }

        if (! $agent->capturesInTheField()) {
            throw new RuntimeException("{$agent->name} is not an active field officer.");
        }

        if ($latitude < self::LAT[0] || $latitude > self::LAT[1] || $longitude < self::LNG[0] || $longitude > self::LNG[1]) {
            throw new RuntimeException('That point is not in Nigeria. Paste it as latitude, longitude, for example 9.0421, 7.4912.');
        }

        $area = $this->admin->forPoint($longitude, $latitude);

        return DB::transaction(function () use ($request, $agent, $supervisor, $latitude, $longitude, $area): EnumerateVisit {
            /** @var EnumerateRequest $fresh */
            $fresh = EnumerateRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $open = EnumerateVisit::query()
                ->where('enumerate_request_id', $fresh->id)
                ->where('kind', EnumerateVisit::KIND_SITE)
                ->whereIn('status', [EnumerateVisit::ASSIGNED, EnumerateVisit::SUBMITTED])
                ->lockForUpdate()
                ->first();

            if ($open?->status === EnumerateVisit::SUBMITTED) {
                throw new RuntimeException('The officer has already filed a report. Review it before sending anyone else.');
            }

            if ($open === null && $fresh->status !== RequestStatus::AwaitingAgent) {
                throw new RuntimeException('That request is not waiting for an officer.');
            }

            // Reassigning before arrival: the first visit is closed as
            // returned, with the reason, rather than handed over in place.
            if ($open !== null) {
                if ($open->arrived_at !== null) {
                    throw new RuntimeException('The officer is already at the premises. Wait for their report.');
                }

                $open->update([
                    'status' => EnumerateVisit::RETURNED,
                    'reviewed_by' => $supervisor->id,
                    'reviewed_at' => now(),
                    'review_note' => "Reassigned to {$agent->name} before arrival.",
                ]);
            }

            $visit = $this->open($fresh, $agent, $supervisor, $latitude, $longitude, $area);

            $fresh->update(['status' => RequestStatus::AgentAssigned]);

            VerificationEvent::record($fresh, 'enumerate.agent_assigned', $supervisor, [
                'visit_id' => $visit->id,
                'agent_id' => $agent->id,
                'agent_ref' => $agent->staff_ref,
            ]);

            $this->messages->toOfficer($supervisor, $agent, sprintf(
                'Site visit to %s, %s. Request %s.',
                $fresh->subject_name,
                $fresh->registered_address ?? $visit->area() ?? 'see the job',
                $fresh->reference,
            ));

            return $visit;
        });
    }

    /**
     * The officer says they are there. The distance from the pin is measured
     * in PostGIS; the requester is told the distance, never either point.
     */
    public function arrive(EnumerateVisit $visit, User $agent, float $longitude, float $latitude, ?float $accuracyM): EnumerateVisit
    {
        $this->assertAgent($visit, $agent);

        if ($visit->arrived_at !== null) {
            return $visit;
        }

        if ($visit->status === EnumerateVisit::MISSED) {
            throw new RuntimeException(self::DAY_OVER);
        }

        if ($visit->status !== EnumerateVisit::ASSIGNED) {
            throw new RuntimeException('That visit is closed.');
        }

        DB::transaction(function () use ($visit, $agent, $longitude, $latitude, $accuracyM): void {
            DB::update(<<<'SQL'
                UPDATE enumerate_visits
                   SET arrived_at = now(),
                       arrival_accuracy_m = ?,
                       arrival_position = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                       arrival_distance_m = round(ST_Distance(site_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)::numeric, 1),
                       updated_at = now()
                 WHERE id = ? AND arrived_at IS NULL
            SQL, [$accuracyM, $longitude, $latitude, $longitude, $latitude, $visit->id]);

            $request = $visit->request()->firstOrFail();

            // A daily visit's arrival is part of the log, not a new stage.
            if ($visit->kind === EnumerateVisit::KIND_SITE) {
                $request->update(['status' => RequestStatus::OnSite]);
            }

            VerificationEvent::record($request, $visit->kind === EnumerateVisit::KIND_SITE ? 'enumerate.agent_arrived' : 'enumerate.day_arrived', $agent, [
                'visit_id' => $visit->id,
                'distance_m' => $visit->refresh()->arrival_distance_m,
            ]);
        });

        return $visit->refresh();
    }

    /**
     * A photograph of the premises. The distance from the pin is written the
     * way StorePhotograph writes it for a building, against the pin instead.
     *
     * @param  'storefront'|'signage'|'interior'|'other'  $angle
     */
    public function photograph(EnumerateVisit $visit, User $agent, UploadedFile $file, string $angle, string $clientUuid, ?float $longitude, ?float $latitude, StorePhotograph $storer): Media
    {
        $this->assertAgent($visit, $agent);

        if ($visit->status === EnumerateVisit::MISSED) {
            throw new RuntimeException(self::DAY_OVER);
        }

        if ($visit->submitted_at !== null || $visit->status !== EnumerateVisit::ASSIGNED) {
            throw new RuntimeException('The report is already filed.');
        }

        $kind = match ($angle) {
            'storefront' => Media::KIND_VISIT_STOREFRONT,
            'signage' => Media::KIND_VISIT_SIGNAGE,
            'interior' => Media::KIND_VISIT_INTERIOR,
            default => Media::KIND_VISIT_OTHER,
        };

        $media = $storer->store($file, $visit, $kind, $agent, $clientUuid, $longitude, $latitude);

        DB::update(<<<'SQL'
            UPDATE media m
               SET distance_from_subject_m = round(ST_Distance(COALESCE(m.capture_point, m.device_reported_point), v.site_point)::numeric, 2)
              FROM enumerate_visits v
             WHERE m.id = ? AND v.id = ? AND COALESCE(m.capture_point, m.device_reported_point) IS NOT NULL
        SQL, [$media->id, $visit->id]);

        return $media->refresh();
    }

    /**
     * The report, once, idempotent on the handset's uuid.
     *
     * @param  array<string, array{passed: bool, detail?: string|null}>  $answers
     */
    public function submit(EnumerateVisit $visit, User $agent, string $reportUuid, array $answers, ?string $notes): EnumerateVisit
    {
        $this->assertAgent($visit, $agent);

        if (! Str::isUuid($reportUuid)) {
            throw new RuntimeException('A report needs the uuid the handset gave it.');
        }

        return DB::transaction(function () use ($visit, $agent, $reportUuid, $answers, $notes): EnumerateVisit {
            /** @var EnumerateVisit $fresh */
            $fresh = EnumerateVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($fresh->report_uuid === $reportUuid) {
                return $fresh;
            }

            if ($fresh->kind !== EnumerateVisit::KIND_SITE) {
                throw new RuntimeException('A daily visit files a log, not a checklist.');
            }

            if ($fresh->status !== EnumerateVisit::ASSIGNED) {
                throw new RuntimeException('That visit already has a report.');
            }

            if ($fresh->arrived_at === null) {
                throw new RuntimeException('Record your arrival before the report.');
            }

            if ($fresh->photos()->where('kind', Media::KIND_VISIT_STOREFRONT)->doesntExist()) {
                throw new RuntimeException('Take at least one photograph of the storefront, or of the place the business should be.');
            }

            $template = EnumerateVisit::CHECKLIST;

            if (array_diff(array_keys($template), array_keys($answers)) !== [] || array_diff(array_keys($answers), array_keys($template)) !== []) {
                throw new RuntimeException('Answer every check on the list, and only those.');
            }

            $checklist = [];

            foreach ($template as $key => [$label]) {
                $detail = isset($answers[$key]['detail']) ? trim((string) $answers[$key]['detail']) : '';
                $checklist[] = [
                    'key' => $key,
                    'label' => $label,
                    'passed' => (bool) $answers[$key]['passed'],
                    'detail' => $detail === '' ? null : mb_substr($detail, 0, 200),
                ];
            }

            $fresh->update([
                'status' => EnumerateVisit::SUBMITTED,
                'report_uuid' => $reportUuid,
                'checklist' => $checklist,
                'notes' => $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 2000),
                'submitted_at' => now(),
            ]);

            VerificationEvent::record($fresh->request()->firstOrFail(), 'enumerate.visit_submitted', $agent, [
                'visit_id' => $fresh->id,
                'passed' => count(array_filter($checklist, static fn (array $c): bool => $c['passed'])),
                'of' => count($checklist),
                'photos' => $fresh->photos()->count(),
            ]);

            return $fresh;
        });
    }

    /**
     * The supervisor's word on a filed report.
     *
     * Accepting confirms the location. A Tier 2 is then complete and its
     * price is earned; a Tier 3 goes on to its daily visits and earns at the
     * end of them. Sending back closes this visit with the reason and gives
     * the same officer a fresh one to the same pin: what they filed stays.
     */
    public function review(EnumerateVisit $visit, User $supervisor, bool $accept, ?string $note): EnumerateVisit
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor reviews a visit.');
        }

        $note = $note === null ? null : trim($note);

        if (! $accept && ($note === null || mb_strlen($note) < 10)) {
            throw new RuntimeException('Tell the officer what to redo, in a sentence.');
        }

        return DB::transaction(function () use ($visit, $supervisor, $accept, $note): EnumerateVisit {
            /** @var EnumerateVisit $fresh */
            $fresh = EnumerateVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== EnumerateVisit::SUBMITTED) {
                throw new RuntimeException('There is no report waiting on that visit.');
            }

            if ($fresh->agent_id === $supervisor->id) {
                throw new RuntimeException('Somebody else reviews your own visit.');
            }

            /** @var EnumerateRequest $request */
            $request = EnumerateRequest::query()->whereKey($fresh->enumerate_request_id)->lockForUpdate()->firstOrFail();

            if ($fresh->kind === EnumerateVisit::KIND_MONITORING) {
                return $this->reviewDay($fresh, $request, $supervisor, $accept, $note);
            }

            $fresh->update([
                'status' => $accept ? EnumerateVisit::ACCEPTED : EnumerateVisit::RETURNED,
                'reviewed_by' => $supervisor->id,
                'reviewed_at' => now(),
                'review_note' => $note === '' ? null : ($note === null ? null : mb_substr($note, 0, 500)),
            ]);

            if (! $accept) {
                $agent = $fresh->agent()->firstOrFail();
                $point = DB::selectOne('SELECT ST_Y(site_point::geometry) AS lat, ST_X(site_point::geometry) AS lng FROM enumerate_visits WHERE id = ?', [$fresh->id]);
                $next = $this->open($request, $agent, $supervisor, (float) $point->lat, (float) $point->lng, ['ward_id' => $fresh->ward_id, 'lga_id' => $fresh->lga_id]);

                $request->update(['status' => RequestStatus::AgentAssigned]);

                VerificationEvent::record($request, 'enumerate.visit_returned', $supervisor, [
                    'visit_id' => $fresh->id,
                    'next_visit_id' => $next->id,
                    'reason' => $note,
                ]);

                $this->messages->toOfficer($supervisor, $agent, "Please visit {$request->subject_name} again ({$request->reference}): {$note}");

                return $fresh;
            }

            $completes = $request->tier === Tier::Location;

            $transaction = $completes ? ($this->post)(
                [
                    LedgerAccount::CUSTOMER_FUNDS_HELD => $request->price_minor,
                    LedgerAccount::VERIFICATION_INCOME => -$request->price_minor,
                ],
                LedgerEntry::REASON_WORK_COMPLETED,
                null,
                "Site visit for {$request->reference}",
                enumerateRequestId: $request->id,
                walletId: $request->wallet_id,
            ) : null;

            $request->update([
                'status' => $completes ? RequestStatus::Completed : RequestStatus::Monitoring,
                'completed_at' => $completes ? now() : null,
            ]);

            if (! $completes) {
                $this->monitoring->start($request, $fresh);
            } else {
                $this->score->stamp($request);
            }

            VerificationEvent::record($request, 'enumerate.location_confirmed', $supervisor, [
                'visit_id' => $fresh->id,
                'transaction_uuid' => $transaction,
            ]);

            return $fresh;
        });
    }

    /**
     * A day's log. Accepted, it joins the requester's log. Struck off, it is
     * kept with the reason, and if the day is still today the officer gets a
     * fresh visit for it; a past day cannot be observed again, so it stays
     * without a log and counts as missed when monitoring closes.
     */
    private function reviewDay(EnumerateVisit $visit, EnumerateRequest $request, User $supervisor, bool $accept, ?string $note): EnumerateVisit
    {
        $visit->update([
            'status' => $accept ? EnumerateVisit::ACCEPTED : EnumerateVisit::RETURNED,
            'reviewed_by' => $supervisor->id,
            'reviewed_at' => now(),
            'review_note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
        ]);

        $redo = null;
        $today = now(config('app.timezone'))->toDateString();

        if (! $accept && $visit->visit_date?->toDateString() === $today) {
            $redo = $this->monitoring->openDay($request, now(config('app.timezone'))->startOfDay(), $visit->agent()->firstOrFail(), $supervisor);
            $this->messages->toOfficer($supervisor, $visit->agent()->firstOrFail(), "Please redo today's visit to {$request->subject_name}: {$note}");
        }

        VerificationEvent::record($request, $accept ? 'enumerate.day_logged' : 'enumerate.day_struck', $supervisor, [
            'visit_id' => $visit->id,
            'day' => $visit->day_number,
            'reason' => $note,
            'redo_visit_id' => $redo?->id,
        ]);

        return $visit;
    }

    /**
     * The officer seen in the field most recently nearest the pin, if any
     * were out in the last twelve hours. Measured in PostGIS.
     *
     * @return array{id: int, name: string, staffRef: string|null, km: float}|null
     */
    public function nearestAgent(float $latitude, float $longitude): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT u.id, u.name, u.staff_ref,
                   ST_Distance(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, f.point::geography) AS m
              FROM (SELECT DISTINCT ON (fs.user_id) fs.user_id, pf.point
                      FROM position_fixes pf
                      JOIN field_sessions fs ON fs.id = pf.field_session_id
                     WHERE pf.recorded_at >= now() - interval '12 hours'
                       AND pf.is_mock = false
                     ORDER BY fs.user_id, pf.recorded_at DESC) f
              JOIN users u ON u.id = f.user_id
             WHERE u.status = 'active' AND u.role = 'officer'
             ORDER BY m
             LIMIT 1
        SQL, [$longitude, $latitude]);

        return $row === null ? null : [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'staffRef' => $row->staff_ref,
            'km' => round((float) $row->m / 1000, 1),
        ];
    }

    /** @param  array{ward_id: int|null, lga_id: int|null}  $area */
    private function open(EnumerateRequest $request, User $agent, User $supervisor, float $latitude, float $longitude, array $area): EnumerateVisit
    {
        $visit = new EnumerateVisit([
            'enumerate_request_id' => $request->id,
            'kind' => 'site',
            'status' => EnumerateVisit::ASSIGNED,
            'agent_id' => $agent->id,
            'assigned_by' => $supervisor->id,
            'assigned_at' => now(),
            'ward_id' => $area['ward_id'],
            'lga_id' => $area['lga_id'],
        ]);

        // Floats formatted by sprintf, so nothing but a number reaches the SQL.
        $visit->forceFill(['site_point' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $longitude, $latitude))])->save();

        return $visit->refresh();
    }

    private function assertAgent(EnumerateVisit $visit, User $agent): void
    {
        if ($visit->agent_id !== $agent->id) {
            throw new RuntimeException('That visit is not yours.');
        }
    }
}
