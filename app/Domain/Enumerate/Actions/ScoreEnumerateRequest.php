<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Enumerate\Models\RegistryCheck;
use Illuminate\Support\Carbon;

/**
 * The score out of 100 and the finding in words, from what was found.
 *
 * Three parts, each worth what it costs us to establish:
 *
 *   Registry, 30: CAC holds it as described (20) and FIRS agrees (10).
 *   Location, 40: premises found (15), within 100 m of the registered address
 *                 (10), the name on the sign (8), open during the visit (7).
 *   Activity, 30: each trading day of the period, open and trading counts in
 *                 full, quiet as 0.6, shut as 0.2, and a day nobody logged as
 *                 nothing, averaged.
 *
 * A request is scored only against what its tier checks: a Tier 1 out of its
 * 30, a Tier 2 out of 70. A score is a summary for a reader in a hurry; the
 * report beside it says what each point was for.
 *
 * Read live while a request runs (the interim report), and stamped on the
 * request when it finishes, so the final figure never moves afterwards.
 */
final class ScoreEnumerateRequest
{
    private const DAY_WEIGHT = ['open' => 1.0, 'low' => 0.6, 'closed' => 0.2];

    /**
     * @return array{score: int, finding: string, parts: array{registry: array{earned: float, of: int}, location: array{earned: float, of: int}|null, activity: array{earned: float, of: int, days: int}|null}}
     */
    public function __invoke(EnumerateRequest $request): array
    {
        if ($request->score !== null && $request->finding !== null && $request->status->finished()) {
            return ['score' => $request->score, 'finding' => $request->finding, 'parts' => $this->parts($request)];
        }

        $parts = $this->parts($request);
        $earned = $parts['registry']['earned'] + ($parts['location']['earned'] ?? 0) + ($parts['activity']['earned'] ?? 0);
        $of = $parts['registry']['of'] + ($parts['location']['of'] ?? 0) + ($parts['activity']['of'] ?? 0);
        $score = $of === 0 ? 0 : (int) round($earned / $of * 100);

        return ['score' => $score, 'finding' => $this->finding($request, $score), 'parts' => $parts];
    }

    /** Written once, when the request finishes. */
    public function stamp(EnumerateRequest $request): void
    {
        $result = $this($request->refresh());
        $request->update(['score' => $result['score'], 'finding' => $result['finding']]);
    }

    /**
     * @return array{registry: array{earned: float, of: int}, location: array{earned: float, of: int}|null, activity: array{earned: float, of: int, days: int}|null}
     */
    private function parts(EnumerateRequest $request): array
    {
        $checks = $request->latestChecks();
        $registry = (($checks['cac'] ?? null)?->outcome === RegistryCheck::MATCHED ? 20 : 0)
            + (($checks['tin'] ?? null)?->outcome === RegistryCheck::MATCHED ? 10 : 0);

        $location = null;
        $activity = null;

        if ($request->tier->sendsAnOfficer()) {
            $site = $request->visits()
                ->where('kind', EnumerateVisit::KIND_SITE)
                ->where('status', EnumerateVisit::ACCEPTED)
                ->latest('id')
                ->first();

            $answers = collect($site->checklist ?? [])->mapWithKeys(static fn (array $c): array => [$c['key'] => $c['passed']]);

            $location = [
                'earned' => (float) (($answers['premises_found'] ?? false) ? 15 : 0)
                    + (($site?->arrival_distance_m !== null && $site->arrival_distance_m <= EnumerateVisit::AT_ADDRESS_M) ? 10 : 0)
                    + (($answers['signage_matches'] ?? false) ? 8 : 0)
                    + (($answers['open_during_visit'] ?? false) ? 7 : 0),
                'of' => 40,
            ];
        }

        if ($request->tier === Tier::Activity) {
            $activity = $this->activity($request);
        }

        return ['registry' => ['earned' => (float) $registry, 'of' => 30], 'location' => $location, 'activity' => $activity];
    }

    /**
     * Trading days so far (all of them once the period is over), each worth
     * its weight if logged and accepted, nothing if not.
     *
     * @return array{earned: float, of: int, days: int}
     */
    private function activity(EnumerateRequest $request): array
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $due = array_filter(
            ManageMonitoring::tradingDaysOf($request),
            static fn (Carbon $d): bool => $request->status === RequestStatus::Completed || $d->lt($today),
        );

        if ($due === []) {
            return ['earned' => 0.0, 'of' => 0, 'days' => 0];
        }

        $logged = $request->visits()
            ->where('kind', EnumerateVisit::KIND_MONITORING)
            ->where('status', EnumerateVisit::ACCEPTED)
            ->get()
            ->keyBy(static fn (EnumerateVisit $v): string => (string) $v->visit_date?->toDateString());

        $sum = 0.0;

        foreach ($due as $day) {
            $state = $logged->get($day->toDateString())?->log['state'] ?? null;
            $sum += is_string($state) ? (self::DAY_WEIGHT[$state] ?? 0.0) : 0.0;
        }

        return ['earned' => round($sum / count($due) * 30, 2), 'of' => 30, 'days' => count($due)];
    }

    private function finding(EnumerateRequest $request, int $score): string
    {
        if ($request->status === RequestStatus::Failed) {
            return 'Not as described';
        }

        if ($request->tier === Tier::Registry) {
            return $request->status === RequestStatus::Passed ? 'Registered as described' : 'Registry check under way';
        }

        // Until a supervisor has accepted the site visit there is nothing on
        // the ground to summarise, whatever the registers said.
        if (! in_array($request->status, [RequestStatus::Monitoring, RequestStatus::Completed], true)) {
            return 'Verification under way';
        }

        return match (true) {
            $score >= 85 => 'Operating as described',
            $score >= 70 => 'Operating, with minor gaps',
            $score >= 50 => 'Partly as described',
            default => 'Not as described',
        };
    }
}
