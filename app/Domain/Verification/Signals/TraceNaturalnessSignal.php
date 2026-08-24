<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * Whether the walk looks walked.
 *
 * Two independent tells, because either alone has honest explanations. A trace
 * that is both perfectly straight and perfectly evenly spaced is not a person on
 * a street: it is an interpolation between two points. A long straight road with
 * uneven steps is just a long straight road.
 *
 * This is the number behind the Presence Mark. The mark makes the same fact
 * pre-attentive; this makes it sortable.
 */
final class TraceNaturalnessSignal implements Signal
{
    /** Below this the path is indistinguishable from the straight line under it. */
    private const STRAIGHT_SINUOSITY = 1.02;

    /** Step sizes varying by less than this share of the mean are generated. */
    private const UNIFORM_SPACING = 0.05;

    /** Fewer vertices than this and neither measure means anything. */
    private const ENOUGH_VERTICES = 6;

    public function key(): string
    {
        return 'trace_naturalness';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Presence;
    }

    public function weight(): int
    {
        return 14;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->traceVertexCount < self::ENOUGH_VERTICES) {
            return SignalResult::unknown('The trace is too short to judge.');
        }

        $sinuosity = $facts->sinuosity();
        $regularity = $facts->segmentRegularity();

        if ($sinuosity === null && $regularity === null) {
            return SignalResult::unknown('The trace is too short to judge.');
        }

        $straight = $sinuosity !== null && $sinuosity < self::STRAIGHT_SINUOSITY;
        $uniform = $regularity !== null && $regularity < self::UNIFORM_SPACING;

        $evidence = [
            'sinuosity' => $sinuosity === null ? null : round($sinuosity, 3),
            'segment_regularity' => $regularity === null ? null : round($regularity, 3),
            'vertices' => $facts->traceVertexCount,
        ];

        if ($straight && $uniform) {
            return SignalResult::fail(
                'The trace is a straight line walked at a constant step. No turns, no dwell.',
                $evidence,
            );
        }

        if ($straight) {
            return SignalResult::warn('The trace runs almost perfectly straight.', $evidence);
        }

        if ($uniform) {
            return SignalResult::warn('Every step in the trace is the same length.', $evidence);
        }

        return SignalResult::ok('The trace turns and varies like a walk.', $evidence);
    }
}
