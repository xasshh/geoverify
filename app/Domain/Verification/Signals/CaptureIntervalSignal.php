<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * Whether the captures came at a human rhythm.
 *
 * Real enumeration is lumpy. One shop takes two minutes because nobody is in,
 * the next takes eleven because the owner wants to talk about the last census.
 * Captures arriving every 180 seconds to the second were entered from a desk.
 *
 * Also catches the other end: a run of captures faster than a person can walk
 * between doorways and hold a conversation.
 */
final class CaptureIntervalSignal implements Signal
{
    /** Fewer than this and there is no rhythm to read. */
    private const ENOUGH_CAPTURES = 4;

    /** Spread as a share of the mean gap. Under this is a metronome. */
    private const METRONOMIC = 0.08;

    /** Nobody surveys a business properly in less than this. */
    private const TOO_QUICK_SECONDS = 25.0;

    public function key(): string
    {
        return 'capture_interval';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Plausibility;
    }

    public function weight(): int
    {
        return 10;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->captureCount < self::ENOUGH_CAPTURES
            || $facts->meanCaptureIntervalSeconds === null
            || $facts->captureIntervalStdDev === null) {
            return SignalResult::unknown('Too few captures in this session to read a rhythm.');
        }

        $mean = $facts->meanCaptureIntervalSeconds;
        $spread = $mean < 1.0 ? null : $facts->captureIntervalStdDev / $mean;

        $evidence = [
            'captures' => $facts->captureCount,
            'mean_interval_s' => round($mean, 1),
            'interval_stddev_s' => round($facts->captureIntervalStdDev, 1),
            'interval_spread' => $spread === null ? null : round($spread, 3),
        ];

        if ($spread !== null && $spread < self::METRONOMIC) {
            return SignalResult::fail(
                'Captures arrived every '.round($mean).' s, evenly, all session.',
                $evidence,
            );
        }

        if ($mean < self::TOO_QUICK_SECONDS) {
            return SignalResult::warn(
                'Captures averaged '.round($mean).' s apart, which is quick for a doorstep interview.',
                $evidence,
            );
        }

        return SignalResult::ok('Captures came at an uneven, human pace.', $evidence);
    }
}
