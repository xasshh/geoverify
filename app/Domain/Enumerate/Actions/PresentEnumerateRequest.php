<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Enumerate\Models\RegistryCheck;
use App\Domain\Media\Models\Media;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What a requester is shown about their own request, and nothing more.
 *
 * Built here rather than in the controller so the page and, later, the PDF
 * report read one projection: a report must never say something the page
 * withheld. The registry facts are the reduced ones RunRegistryChecks stored
 * (directors by name and role only); the updates are the requester's own
 * events and the platform's, never a note from the console.
 */
final class PresentEnumerateRequest
{
    public function __construct(private readonly ScoreEnumerateRequest $score) {}

    /** Events a requester is told about, in their words. Anything else stays in the console. */
    private const UPDATES = [
        'enumerate.paid' => 'Paid from wallet',
        'enumerate.registry_checked' => 'Registry lookups returned',
        'enumerate.desk_passed' => 'Registry check passed',
        'enumerate.desk_failed' => 'Registry check failed',
        'enumerate.agent_assigned' => 'Agent assigned',
        'enumerate.agent_arrived' => 'Agent on site',
        'enumerate.visit_submitted' => 'Visit report filed, with the supervisor',
        'enumerate.visit_returned' => 'Revisit scheduled',
        'enumerate.location_confirmed' => 'Location confirmed',
        'enumerate.day_logged' => 'Daily log added',
        'enumerate.day_missed' => 'No visit recorded',
        'enumerate.monitoring_reassigned' => 'Agent changed',
        'enumerate.monitoring_closed' => 'Monitoring complete',
    ];

    /** @return array<string, mixed> */
    public function row(EnumerateRequest $request): array
    {
        return [
            'reference' => $request->reference,
            'business' => $request->subject_name,
            'tier' => $request->tier->value,
            'tierLabel' => $request->tier->short(),
            'monitoringDays' => $request->monitoring_days,
            'status' => $request->status->value,
            'statusLabel' => $request->status->label(),
            'statusNote' => $this->note($request),
            'requestedAt' => $request->paid_at->toIso8601String(),
            'amountMinor' => $request->price_minor,
        ];
    }

    /** @return array<string, mixed> */
    public function page(EnumerateRequest $request): array
    {
        $checks = $request->latestChecks();
        $all = $request->visits()->with(['agent', 'ward', 'lga'])->orderBy('id')->get();
        $visits = $all->where('kind', EnumerateVisit::KIND_SITE)->values();
        $days = $all->where('kind', EnumerateVisit::KIND_MONITORING)->values();

        return $this->row($request) + [
            'rcNumber' => $request->rc_number,
            'companyType' => $request->company_type,
            'registeredAddress' => $request->registered_address,
            'requestedBy' => $request->requester?->name,
            'steps' => $this->steps($request, $visits),
            'location' => $this->location($visits),
            'monitoring' => $request->tier === Tier::Activity ? $this->monitoring($request, $days) : null,
            'registry' => [
                'outcome' => $request->registry_outcome,
                'reason' => $request->registry_reason,
                'decidedAt' => $request->desk_checked_at?->toIso8601String(),
                'cac' => $this->check($checks['cac'] ?? null),
                'tin' => $this->check($checks['tin'] ?? null),
            ],
            'returnedMinor' => $request->status === RequestStatus::Failed
                ? $request->price_minor - $request->registry_fee_minor
                : 0,
            'updates' => $this->updates($request),
            'result' => $this->result($request),
        ];
    }

    /**
     * Tier 3's log, to board 30: a calendar of the period and the accepted
     * daily logs. A day joins the log once a supervisor accepts it; until
     * then it shows as filed. Photographs are counted, never shown: the
     * storefront the requester needed was on the site visit.
     *
     * @param  Collection<int, EnumerateVisit>  $days
     * @return array<string, mixed>|null
     */
    private function monitoring(EnumerateRequest $request, Collection $days): ?array
    {
        if ($request->monitoring_starts_on === null || $request->monitoring_ends_on === null) {
            return null;
        }

        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $byDate = $days->reject(static fn (EnumerateVisit $v): bool => $v->status === EnumerateVisit::RETURNED)
            ->keyBy(static fn (EnumerateVisit $v): string => (string) $v->visit_date?->toDateString());
        $calendar = [];
        $day = 0;

        for ($d = $request->monitoring_starts_on->copy(); $d->lte($request->monitoring_ends_on); $d->addDay()) {
            $day++;
            $visit = $byDate->get($d->toDateString());

            $state = match (true) {
                $visit?->status === EnumerateVisit::ACCEPTED => (string) ($visit->log['state'] ?? 'open'),
                $visit?->status === EnumerateVisit::SUBMITTED, $visit?->status === EnumerateVisit::ASSIGNED => 'pending',
                ! ManageMonitoring::isTradingDay($d) => 'no_visit',
                $d->gte($today) => 'upcoming',
                default => 'missed',
            };

            $calendar[] = [
                'day' => $day,
                'date' => $d->toDateString(),
                'weekday' => ['Su', 'M', 'T', 'W', 'Th', 'F', 'S'][$d->dayOfWeek],
                'state' => $state,
                'today' => $d->equalTo($today),
            ];
        }

        $accepted = $days->where('status', EnumerateVisit::ACCEPTED)->sortByDesc('visit_date');

        return [
            'startsOn' => $request->monitoring_starts_on->toDateString(),
            'endsOn' => $request->monitoring_ends_on->toDateString(),
            'days' => (int) $request->monitoring_days,
            'dayToday' => $today->betweenIncluded($request->monitoring_starts_on, $request->monitoring_ends_on)
                ? (int) $request->monitoring_starts_on->diffInDays($today) + 1
                : null,
            'calendar' => $calendar,
            'missedDays' => count(array_filter($calendar, static fn (array $c): bool => $c['state'] === 'missed')),
            'entries' => $accepted->map(static fn (EnumerateVisit $v): array => [
                'day' => (int) $v->day_number,
                'date' => (string) $v->visit_date?->toDateString(),
                'state' => (string) ($v->log['state'] ?? 'open'),
                'opens' => $v->log['opens'] ?? null,
                'closes' => $v->log['closes'] ?? null,
                'staff' => $v->log['staff'] ?? null,
                'customers' => $v->log['customers'] ?? null,
                'activity' => (string) ($v->log['activity'] ?? ''),
                'photos' => $v->photos()->count(),
                'agentRef' => $v->agent?->staff_ref,
                'at' => $v->submitted_at?->toIso8601String(),
            ])->values()->all(),
            'returnedMinor' => $request->status === RequestStatus::Completed && ($request->monitoring_missed_days ?? 0) > 0
                ? self::returned($request)
                : 0,
        ];
    }

    /** What closing gave back, read from the event that recorded it. */
    private static function returned(EnumerateRequest $request): int
    {
        $event = VerificationEvent::query()
            ->where('subject_type', $request->getMorphClass())
            ->where('subject_id', $request->id)
            ->where('event', 'enumerate.monitoring_closed')
            ->latest('id')
            ->first();

        return (int) ($event?->evidence['returned_minor'] ?? 0);
    }

    /**
     * The score and finding, once there is something to summarise: a Tier 1
     * when the desk check is decided, a Tier 2 or 3 once the location is
     * confirmed. Live until the request finishes, then as stamped.
     *
     * @return array{score: int, finding: string, final: bool}|null
     */
    private function result(EnumerateRequest $request): ?array
    {
        $ready = $request->status->finished() || $request->status === RequestStatus::Monitoring;

        if (! $ready) {
            return null;
        }

        $result = ($this->score)($request);

        return ['score' => $result['score'], 'finding' => $result['finding'], 'final' => $request->status->finished()];
    }

    /** The line under a status in the tables: "TIN does not match CAC name". */
    private function note(EnumerateRequest $request): ?string
    {
        return match ($request->status) {
            RequestStatus::Failed => $request->registry_reason,
            RequestStatus::Passed, RequestStatus::Completed => 'Report ready',
            RequestStatus::AwaitingAgent => 'An officer is being assigned',
            RequestStatus::AgentAssigned => 'An officer is on the way',
            RequestStatus::OnSite => 'The officer is at the premises',
            RequestStatus::Monitoring => $request->monitoring_starts_on !== null && $request->monitoring_starts_on->lte(Carbon::today(config('app.timezone')))
                ? sprintf('Day %d of %d', min((int) $request->monitoring_days, (int) $request->monitoring_starts_on->diffInDays(Carbon::today(config('app.timezone'))) + 1), (int) $request->monitoring_days)
                : 'Daily visits start tomorrow',
            RequestStatus::Paid, RequestStatus::RegistryCheck => 'Checking the registers',
        };
    }

    /**
     * The site visit, as the requester may see it.
     *
     * The officer's code and times from the moment one is sent. The findings,
     * the distance and the photographs only once a supervisor has accepted
     * the report: a report that is sent back was never a finding. Photographs
     * are the storefront and the signage, by name, through links that expire;
     * the interior is counted, never shown. No coordinate of any kind.
     *
     * @param  Collection<int, EnumerateVisit>  $visits
     * @return array<string, mixed>|null
     */
    private function location(Collection $visits): ?array
    {
        $visit = $visits->reject(static fn (EnumerateVisit $v): bool => $v->status === EnumerateVisit::RETURNED)->last()
            ?? $visits->last();

        if ($visit === null) {
            return null;
        }

        $accepted = $visit->status === EnumerateVisit::ACCEPTED;

        return [
            'status' => $visit->status,
            'agentRef' => $visit->agent?->staff_ref,
            'assignedAt' => $visit->assigned_at->toIso8601String(),
            'arrivedAt' => $visit->arrived_at?->toIso8601String(),
            'submittedAt' => $visit->submitted_at?->toIso8601String(),
            'confirmedAt' => $accepted ? $visit->reviewed_at?->toIso8601String() : null,
            'revisits' => $visits->where('status', EnumerateVisit::RETURNED)->count(),
            'area' => $accepted ? $visit->area() : null,
            'distanceM' => $accepted ? $visit->arrival_distance_m : null,
            'atAddress' => $accepted && $visit->arrival_distance_m !== null
                ? $visit->arrival_distance_m <= EnumerateVisit::AT_ADDRESS_M
                : null,
            'minutesOnSite' => $accepted && $visit->arrived_at !== null && $visit->submitted_at !== null
                ? max(1, (int) round($visit->arrived_at->diffInMinutes($visit->submitted_at)))
                : null,
            'checklist' => $accepted ? $visit->checklist : null,
            'photos' => $accepted
                ? $visit->exteriorPhotos()->orderBy('captured_at')->orderBy('id')->get()->map(static fn (Media $m): array => [
                    'id' => $m->id,
                    'kind' => $m->kind === Media::KIND_VISIT_SIGNAGE ? 'Signage' : 'Storefront',
                    'at' => $m->captured_at?->toIso8601String(),
                    'url' => $m->temporaryUrl(15),
                ])->values()->all()
                : [],
            'otherPhotos' => $accepted
                ? $visit->photos()->whereIn('kind', [Media::KIND_VISIT_INTERIOR, Media::KIND_VISIT_OTHER])->count()
                : 0,
        ];
    }

    /**
     * The progress bar: every step this tier goes through, with when it
     * happened. Steps the milestones after E1 fill in show as still to come.
     *
     * @param  Collection<int, EnumerateVisit>  $visits
     * @return list<array{key: string, label: string, at: string|null, state: 'done'|'current'|'todo'|'failed'}>
     */
    private function steps(EnumerateRequest $request, Collection $visits): array
    {
        $decided = $request->desk_checked_at;
        $failed = $request->status === RequestStatus::Failed;
        $confirmed = $visits->firstWhere('status', EnumerateVisit::ACCEPTED);

        $steps = [
            ['key' => 'paid', 'label' => 'Paid', 'at' => $request->paid_at],
            ['key' => 'registry', 'label' => 'Registry check', 'at' => $decided],
        ];

        if ($request->tier === Tier::Registry) {
            $steps[] = ['key' => 'result', 'label' => $failed ? 'Failed' : 'Result', 'at' => $request->completed_at];
        } else {
            $steps[] = ['key' => 'agent', 'label' => 'Agent assigned', 'at' => $visits->first()?->assigned_at];
            // Done when a supervisor has accepted it, stamped when the officer got there.
            $steps[] = ['key' => 'visit', 'label' => 'Site visit', 'at' => $confirmed?->arrived_at];

            if ($request->tier === Tier::Activity) {
                $steps[] = ['key' => 'monitoring', 'label' => 'Daily monitoring', 'at' => $request->completed_at];
            }

            $steps[] = ['key' => 'report', 'label' => 'Final report', 'at' => $request->completed_at];
        }

        $out = [];
        $current = false;

        foreach ($steps as $step) {
            $done = $step['at'] instanceof Carbon;
            $state = match (true) {
                $done && $failed && $step['key'] === 'registry' => 'failed',
                $done => 'done',
                ! $current && ! $failed => 'current',
                default => 'todo',
            };

            if ($state === 'current') {
                $current = true;
            }

            $out[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'at' => $step['at']?->toIso8601String(),
                'state' => $state,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function check(?RegistryCheck $check): ?array
    {
        if ($check === null) {
            return null;
        }

        return [
            'outcome' => $check->outcome,
            'note' => $check->note,
            'facts' => $check->facts,
            'checkedAt' => $check->checked_at->toIso8601String(),
        ];
    }

    /** @return list<array{label: string, at: string}> */
    private function updates(EnumerateRequest $request): array
    {
        return VerificationEvent::query()
            ->where('subject_type', $request->getMorphClass())
            ->where('subject_id', $request->id)
            ->whereIn('event', array_keys(self::UPDATES))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static fn (VerificationEvent $e): array => [
                'label' => self::UPDATES[$e->event].(isset($e->evidence['day']) ? ' · day '.(int) $e->evidence['day'] : ''),
                'at' => $e->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
