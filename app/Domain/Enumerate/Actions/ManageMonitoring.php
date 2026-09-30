<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tier 3's daily visits, from the confirmed location to the closing ledger.
 *
 * The period is calendar days, as it was sold ("30 days"); visits happen on
 * the trading days inside it, Monday to Saturday and not on a public holiday,
 * because a shop shut on Sunday is not news. Each trading day the schedule
 * gives the monitoring officer that day's visit, and a visit still unfiled
 * when the day is over is marked missed: the log says so, and when monitoring
 * closes the requester gets back the share of the price those days stood for.
 */
final class ManageMonitoring
{
    public function __construct(
        private readonly FieldMessaging $messages,
        private readonly PostTransaction $post,
        private readonly ScoreEnumerateRequest $score,
    ) {}

    /**
     * The site visit was accepted: the period starts tomorrow, with the
     * officer who found the premises, unless a supervisor says otherwise.
     */
    public function start(EnumerateRequest $request, EnumerateVisit $siteVisit): void
    {
        $starts = Carbon::now(config('app.timezone'))->addDay()->startOfDay();

        $request->update([
            'monitoring_starts_on' => $starts->toDateString(),
            'monitoring_ends_on' => $starts->copy()->addDays(((int) $request->monitoring_days) - 1)->toDateString(),
            'monitoring_officer_id' => $siteVisit->agent_id,
        ]);
    }

    /** Whether the business is expected to trade on this date. */
    public static function isTradingDay(Carbon $date): bool
    {
        /** @var list<string> $holidays */
        $holidays = (array) config('geoverify.public_holidays', []);

        return ! $date->isSunday() && ! in_array($date->toDateString(), $holidays, true);
    }

    /**
     * The trading days in a request's period.
     *
     * @return list<Carbon>
     */
    public function tradingDays(EnumerateRequest $request): array
    {
        return self::tradingDaysOf($request);
    }

    /**
     * The same, for callers that must not depend on this action.
     *
     * @return list<Carbon>
     */
    public static function tradingDaysOf(EnumerateRequest $request): array
    {
        if ($request->monitoring_starts_on === null || $request->monitoring_ends_on === null) {
            return [];
        }

        $days = [];

        for ($d = $request->monitoring_starts_on->copy(); $d->lte($request->monitoring_ends_on); $d->addDay()) {
            if (self::isTradingDay($d)) {
                $days[] = $d->copy();
            }
        }

        return $days;
    }

    /**
     * The morning run. Yesterday's unfiled visits become missed; today's are
     * handed out. Safe to run twice: a day with a visit already is skipped.
     *
     * @return array{opened: int, missed: int}
     */
    public function schedule(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now(config('app.timezone')))->copy()->startOfDay();

        $missed = EnumerateVisit::query()
            ->where('kind', EnumerateVisit::KIND_MONITORING)
            ->where('status', EnumerateVisit::ASSIGNED)
            ->whereDate('visit_date', '<', $today->toDateString())
            ->get();

        foreach ($missed as $visit) {
            $visit->update(['status' => EnumerateVisit::MISSED, 'review_note' => 'Not filed on the day.']);
            VerificationEvent::record($visit->request()->firstOrFail(), 'enumerate.day_missed', null, [
                'visit_id' => $visit->id,
                'day' => $visit->day_number,
            ], VerificationEvent::ACTOR_SYSTEM);
        }

        $opened = 0;

        if (self::isTradingDay($today)) {
            $due = EnumerateRequest::query()
                ->where('status', RequestStatus::Monitoring->value)
                ->whereDate('monitoring_starts_on', '<=', $today->toDateString())
                ->whereDate('monitoring_ends_on', '>=', $today->toDateString())
                ->whereNotNull('monitoring_officer_id')
                ->get();

            foreach ($due as $request) {
                $exists = EnumerateVisit::query()
                    ->where('enumerate_request_id', $request->id)
                    ->where('kind', EnumerateVisit::KIND_MONITORING)
                    ->whereDate('visit_date', $today->toDateString())
                    ->exists();

                if (! $exists) {
                    $this->openDay($request, $today, $request->monitoringOfficer()->firstOrFail(), null);
                    $opened++;
                }
            }
        }

        return ['opened' => $opened, 'missed' => $missed->count()];
    }

    /** One day's visit, to the pin the site visit was accepted at. */
    public function openDay(EnumerateRequest $request, Carbon $date, User $officer, ?User $by): EnumerateVisit
    {
        $site = EnumerateVisit::query()
            ->where('enumerate_request_id', $request->id)
            ->where('kind', EnumerateVisit::KIND_SITE)
            ->where('status', EnumerateVisit::ACCEPTED)
            ->latest('id')
            ->firstOrFail();

        $starts = $request->monitoring_starts_on ?? throw new RuntimeException('Monitoring has not started.');
        $day = (int) $starts->diffInDays($date) + 1;

        $visit = new EnumerateVisit([
            'enumerate_request_id' => $request->id,
            'kind' => EnumerateVisit::KIND_MONITORING,
            'day_number' => $day,
            'visit_date' => $date->toDateString(),
            'status' => EnumerateVisit::ASSIGNED,
            'agent_id' => $officer->id,
            // The schedule acts for the supervisor who last touched it.
            'assigned_by' => $by === null ? $site->assigned_by : $by->id,
            'assigned_at' => now(),
            'ward_id' => $site->ward_id,
            'lga_id' => $site->lga_id,
        ]);
        $visit->forceFill(['site_point' => DB::raw(sprintf('(SELECT site_point FROM enumerate_visits WHERE id = %d)', $site->id))])->save();

        $this->messages->toOfficer($by ?? User::query()->findOrFail($site->assigned_by), $officer, sprintf(
            'Daily visit to %s today, day %d of %d. Request %s.',
            $request->subject_name,
            $day,
            (int) $request->monitoring_days,
            $request->reference,
        ));

        return $visit->refresh();
    }

    /**
     * The day's log, once, idempotent on the handset's uuid.
     *
     * @param  array{state: string, opens?: string|null, closes?: string|null, staff?: int|null, customers?: int|null, activity: string}  $log
     */
    public function submitLog(EnumerateVisit $visit, User $agent, string $reportUuid, array $log, ?string $notes): EnumerateVisit
    {
        if ($visit->agent_id !== $agent->id) {
            throw new RuntimeException('That visit is not yours.');
        }

        if (! Str::isUuid($reportUuid)) {
            throw new RuntimeException('A report needs the uuid the handset gave it.');
        }

        if (! in_array($log['state'], EnumerateVisit::DAY_STATES, true)) {
            throw new RuntimeException('Say whether the business was open, quiet or closed.');
        }

        $activity = trim($log['activity']);

        if (mb_strlen($activity) < 10) {
            throw new RuntimeException('Say in a sentence what you saw today.');
        }

        foreach ([$log['opens'] ?? null, $log['closes'] ?? null] as $time) {
            if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw new RuntimeException('Write the hours as 08:05 and 18:10.');
            }
        }

        return DB::transaction(function () use ($visit, $agent, $reportUuid, $log, $activity, $notes): EnumerateVisit {
            /** @var EnumerateVisit $fresh */
            $fresh = EnumerateVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($fresh->report_uuid === $reportUuid) {
                return $fresh;
            }

            if ($fresh->status !== EnumerateVisit::ASSIGNED) {
                throw new RuntimeException($fresh->status === EnumerateVisit::MISSED
                    ? ManageEnumerateVisits::DAY_OVER
                    : 'That day already has a log.');
            }

            if ($fresh->arrived_at === null) {
                throw new RuntimeException('Record your arrival before the log.');
            }

            if ($fresh->photos()->doesntExist()) {
                throw new RuntimeException('Take at least one photograph, even of a closed shutter.');
            }

            $closed = $log['state'] === 'closed';

            $fresh->update([
                'status' => EnumerateVisit::SUBMITTED,
                'report_uuid' => $reportUuid,
                'log' => [
                    'state' => $log['state'],
                    'opens' => $closed ? null : ($log['opens'] ?? null),
                    'closes' => $closed ? null : ($log['closes'] ?? null),
                    'staff' => $closed || ! isset($log['staff']) ? null : max(0, (int) $log['staff']),
                    'customers' => $closed || ! isset($log['customers']) ? null : max(0, (int) $log['customers']),
                    'activity' => mb_substr($activity, 0, 300),
                ],
                'notes' => $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 2000),
                'submitted_at' => now(),
            ]);

            VerificationEvent::record($fresh->request()->firstOrFail(), 'enumerate.day_submitted', $agent, [
                'visit_id' => $fresh->id,
                'day' => $fresh->day_number,
                'state' => $log['state'],
            ]);

            return $fresh;
        });
    }

    /**
     * A supervisor gives the rest of the period to another officer. Today's
     * visit moves with it if nobody has arrived yet.
     */
    public function reassign(EnumerateRequest $request, User $officer, User $supervisor): void
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor changes the officer.');
        }

        if (! $officer->capturesInTheField()) {
            throw new RuntimeException("{$officer->name} is not an active field officer.");
        }

        if ($request->status !== RequestStatus::Monitoring) {
            throw new RuntimeException('That request is not being monitored.');
        }

        DB::transaction(function () use ($request, $officer, $supervisor): void {
            $request->update(['monitoring_officer_id' => $officer->id]);

            $today = EnumerateVisit::query()
                ->where('enumerate_request_id', $request->id)
                ->where('kind', EnumerateVisit::KIND_MONITORING)
                ->where('status', EnumerateVisit::ASSIGNED)
                ->whereNull('arrived_at')
                ->where('agent_id', '<>', $officer->id)
                ->lockForUpdate()
                ->first();

            if ($today !== null) {
                $today->update([
                    'status' => EnumerateVisit::RETURNED,
                    'reviewed_by' => $supervisor->id,
                    'reviewed_at' => now(),
                    'review_note' => "Reassigned to {$officer->name} before arrival.",
                ]);
                $this->openDay($request, Carbon::parse($today->visit_date?->toDateString()), $officer, $supervisor);
            }

            VerificationEvent::record($request, 'enumerate.monitoring_reassigned', $supervisor, [
                'officer_id' => $officer->id,
            ]);
        });
    }

    /**
     * The period is over: the price is earned for the days that were
     * logged, and the share of it for trading days nobody logged goes back
     * to the wallet. The desk check's fee is earned whatever happened.
     *
     * @return array{missed: int, returnedMinor: int}
     */
    public function close(EnumerateRequest $request, User $supervisor): array
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor closes monitoring.');
        }

        return DB::transaction(function () use ($request, $supervisor): array {
            /** @var EnumerateRequest $fresh */
            $fresh = EnumerateRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== RequestStatus::Monitoring || $fresh->monitoring_ends_on === null) {
                throw new RuntimeException('That request is not being monitored.');
            }

            if ($fresh->monitoring_ends_on->gte(Carbon::now(config('app.timezone'))->startOfDay())) {
                throw new RuntimeException('Monitoring runs until '.$fresh->monitoring_ends_on->format('j M').'. Close it after that.');
            }

            if (EnumerateVisit::query()->where('enumerate_request_id', $fresh->id)
                ->whereIn('status', [EnumerateVisit::ASSIGNED, EnumerateVisit::SUBMITTED])->exists()) {
                throw new RuntimeException('A daily log is still waiting. Review it first.');
            }

            $trading = count($this->tradingDays($fresh));
            $logged = EnumerateVisit::query()
                ->where('enumerate_request_id', $fresh->id)
                ->where('kind', EnumerateVisit::KIND_MONITORING)
                ->where('status', EnumerateVisit::ACCEPTED)
                ->distinct()
                ->count('visit_date');

            $missed = max(0, $trading - $logged);
            $perDay = $trading === 0 ? 0 : intdiv($fresh->price_minor - $fresh->registry_fee_minor, $trading);
            $returned = $perDay * $missed;

            $legs = [
                LedgerAccount::CUSTOMER_FUNDS_HELD => $fresh->price_minor,
                LedgerAccount::VERIFICATION_INCOME => -($fresh->price_minor - $returned),
            ];

            if ($returned > 0) {
                $legs[LedgerAccount::REQUESTER_WALLETS] = -$returned;
            }

            $transaction = ($this->post)(
                $legs,
                LedgerEntry::REASON_WORK_COMPLETED,
                null,
                $returned > 0
                    ? "Monitoring for {$fresh->reference}; {$missed} trading days without a log returned"
                    : "Monitoring for {$fresh->reference}",
                enumerateRequestId: $fresh->id,
                walletId: $fresh->wallet_id,
            );

            $fresh->update([
                'status' => RequestStatus::Completed,
                'completed_at' => now(),
                'monitoring_missed_days' => $missed,
            ]);

            $this->score->stamp($fresh);

            VerificationEvent::record($fresh, 'enumerate.monitoring_closed', $supervisor, [
                'trading_days' => $trading,
                'logged' => $logged,
                'missed' => $missed,
                'returned_minor' => $returned,
                'transaction_uuid' => $transaction,
            ]);

            return ['missed' => $missed, 'returnedMinor' => $returned];
        });
    }
}
