<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

/**
 * The verification score an investor sees, out of a hundred.
 *
 * Not a credit score and not an opinion: a weighted count of what has been
 * established about the business and how recently. Each rung carries a weight
 * in proportion to what it costs to fake, and an ageing or stale rung counts
 * for less, because an officer's visit two years ago says less about today than
 * one last month. Pending counts for nothing until it lands.
 *
 * Deterministic from the rungs alone, so the number on a dossier can always be
 * explained line by line and never drifts from the ladder the business sees.
 */
final class ScoreVerification
{
    /** @var array<string, int> */
    public const WEIGHTS = [
        'listed' => 10,
        'identity_verified' => 20,
        'location_verified' => 30,
        'operations_verified' => 25,
        'monitored' => 15,
    ];

    /** @var array<string, float> */
    public const FRESHNESS = [
        'current' => 1.0,
        'ageing' => 0.6,
        'stale' => 0.25,
        'pending' => 0.0,
        'not_established' => 0.0,
    ];

    /**
     * @param  list<array{tier: string, state: string}>  $rungs
     */
    public function __invoke(array $rungs): int
    {
        $score = 0.0;

        foreach ($rungs as $rung) {
            $score += (self::WEIGHTS[$rung['tier']] ?? 0) * (self::FRESHNESS[$rung['state']] ?? 0.0);
        }

        return (int) round(min(100.0, $score));
    }
}
