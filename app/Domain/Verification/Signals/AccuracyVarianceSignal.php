<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * Whether the reported accuracy ever moved.
 *
 * A real receiver's accuracy wanders continuously: satellites rise and set, the
 * officer walks under a balcony, the sky opens up again. A number that repeats
 * exactly across a whole session was written down once, not measured seven times.
 *
 * Deliberately separate from PositionAccuracySignal. That one asks how good the
 * fixes were; this one asks whether they were measured at all, and a spoofed day
 * usually reports an excellent accuracy that never changes.
 */
final class AccuracyVarianceSignal implements Signal
{
    /** Below this many fixes, a repeated value is a coincidence. */
    private const ENOUGH_FIXES = 5;

    /** Metres of spread. Under this the receiver is not reporting, it is repeating. */
    private const FLAT_STDDEV = 0.05;

    public function key(): string
    {
        return 'accuracy_variance';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Presence;
    }

    public function weight(): int
    {
        return 5;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->fixCount < self::ENOUGH_FIXES || $facts->accuracyStdDev === null) {
            return SignalResult::unknown('Too few fixes to tell whether accuracy varied.');
        }

        $evidence = [
            'accuracy_stddev' => round($facts->accuracyStdDev, 3),
            'distinct_values' => $facts->distinctAccuracyValues,
            'fixes' => $facts->fixCount,
        ];

        if ($facts->distinctAccuracyValues === 1) {
            return SignalResult::fail(
                "Every one of {$facts->fixCount} fixes reported the same accuracy.",
                $evidence,
            );
        }

        if ($facts->accuracyStdDev < self::FLAT_STDDEV) {
            return SignalResult::warn(
                'Reported accuracy barely moved across the session.',
                $evidence,
            );
        }

        return SignalResult::ok('Reported accuracy varied as a receiver does.', $evidence);
    }
}
