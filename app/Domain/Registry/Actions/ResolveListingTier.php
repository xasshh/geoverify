<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Structure;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * What a listing has actually established, and on what evidence.
 *
 * One place, because two screens disagreeing about a business's tier is worse
 * than either being wrong: the tier is the product, and a search result that
 * says "location verified" over a listing page that says "listed" destroys the
 * only thing the register is selling.
 *
 * The rule is short and refuses to be generous. An officer standing at the door
 * with a GPS fix is location_verified, which is exactly what that tier means. A
 * business that typed its own address is listed, and nothing more, however
 * complete the form was and however honest the person filling it in. Buying the
 * next rung requires a visit, which is the load bearing rule of the whole
 * platform: verification cannot be bought without one.
 */
final class ResolveListingTier
{
    /** The highest tier this listing has established. */
    public function forOrigin(string $origin, string $status): string
    {
        if ($origin === Structure::ORIGIN_SELF_REGISTERED) {
            return 'listed';
        }

        // A rejected capture establishes nothing. It should not be reachable
        // here at all, and if it is, saying "listed" is the honest answer
        // rather than crediting work a supervisor threw out.
        return $status === Structure::STATUS_REJECTED ? 'listed' : 'location_verified';
    }

    /**
     * The same answer, said the way the ladder component wants it.
     *
     * Every established rung carries the date it was established and how the
     * register feels about that date now. A tier with no date beside it invites
     * the reader to assume it was checked recently, and on a register whose
     * whole value is knowing how well it knows things, that assumption is the
     * one thing it must never encourage.
     *
     * @return list<array{tier: string, state: string, establishedOn?: string, elapsed?: string}>
     */
    public function rungs(string $origin, string $status, DateTimeInterface $establishedOn): array
    {
        $reached = $this->forOrigin($origin, $status);
        $freshness = $this->freshness($establishedOn);

        $rungs = [
            ['tier' => 'listed', ...$freshness],
            ['tier' => 'identity_verified', 'state' => 'not_established'],
            ['tier' => 'location_verified', 'state' => 'not_established'],
            ['tier' => 'operations_verified', 'state' => 'not_established'],
            ['tier' => 'monitored', 'state' => 'not_established'],
        ];

        if ($reached === 'location_verified') {
            $rungs[2] = ['tier' => 'location_verified', ...$freshness];
        }

        return $rungs;
    }

    /**
     * How the register feels about a date.
     *
     * Two thresholds out of config, because this is a commercial judgement
     * rather than a fact: a mandate over an industrial estate and one over a
     * market where stalls turn over quarterly do not age at the same rate.
     *
     * `ageing` and `stale` are both still established. The difference is how
     * long ago, and that is a fact rather than a warning: nothing here expires,
     * and a tier that quietly stopped counting after two years would be the
     * register deleting evidence it had gathered.
     *
     * @return array{state: string, establishedOn: string, elapsed: string}
     */
    public function freshness(DateTimeInterface $establishedOn): array
    {
        $established = Carbon::instance(
            $establishedOn instanceof Carbon ? $establishedOn : Carbon::parse($establishedOn->format('c')),
        )->startOfDay();

        $months = (int) $established->diffInMonths(Carbon::now(config('app.timezone'))->startOfDay());

        $current = (int) config('geoverify.tier_freshness.current_months', 12);
        $stale = (int) config('geoverify.tier_freshness.stale_months', 24);

        return [
            'state' => match (true) {
                $months >= $stale => 'stale',
                $months >= $current => 'ageing',
                default => 'current',
            },
            'establishedOn' => $established->format('F Y'),
            'elapsed' => $this->elapsed($months),
        ];
    }

    /**
     * How long ago, in the reader's words.
     *
     * Months up to two years and then years, because "31 months" is a number a
     * person has to convert before it means anything, and the point of this
     * line is that it lands without arithmetic.
     */
    private function elapsed(int $months): string
    {
        if ($months < 1) {
            return 'this month';
        }

        if ($months < 24) {
            return $months === 1 ? '1 month' : "{$months} months";
        }

        $years = intdiv($months, 12);

        return "{$years} years";
    }
}
