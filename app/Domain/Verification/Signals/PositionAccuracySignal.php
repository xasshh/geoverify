<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * How well the receiver actually knew where it was.
 *
 * Not an anti-spoofing signal. This is the honest one: a capture taken under a
 * canopy or between two storey buildings is worth less as evidence than one taken
 * in the open, and the supervisor should see that without it being an accusation.
 */
final class PositionAccuracySignal implements Signal
{
    /** A fix worse than this is worth pointing at. */
    private const LOOSE_M = 5.0;

    /** A capture placed with this little certainty cannot site a building. */
    private const UNUSABLE_M = 30.0;

    public function key(): string
    {
        return 'position_accuracy';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Presence;
    }

    public function weight(): int
    {
        return 6;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->fixCount === 0 || $facts->meanAccuracyM === null) {
            return SignalResult::unknown('No accuracy was reported for this capture.');
        }

        $evidence = [
            'mean_accuracy_m' => round($facts->meanAccuracyM, 1),
            'worst_accuracy_m' => $facts->worstAccuracyM === null ? null : round($facts->worstAccuracyM, 1),
            'fixes_over_5m' => $facts->fixesOverFiveMetres,
            'fixes' => $facts->fixCount,
        ];

        if ($facts->meanAccuracyM >= self::UNUSABLE_M) {
            return SignalResult::fail(
                'Mean accuracy was '.round($facts->meanAccuracyM, 1).' m. That will not site a building.',
                $evidence,
            );
        }

        if ($facts->fixesOverFiveMetres > 0) {
            return SignalResult::warn(
                sprintf(
                    'Accuracy was worse than %d m on %d of %d fixes.',
                    (int) self::LOOSE_M,
                    $facts->fixesOverFiveMetres,
                    $facts->fixCount,
                ),
                $evidence,
            );
        }

        return SignalResult::ok(
            'Accuracy held under '.(int) self::LOOSE_M.' m throughout.',
            $evidence,
        );
    }
}
