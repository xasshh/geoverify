<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * Whether the structure stands in the cell the officer was given.
 *
 * Answered by ST_Contains against the cell boundary, server side, like every
 * other geography question here. A capture just over an edge is ordinary: an H3
 * boundary runs through buildings and nobody surveys with a theodolite. A capture
 * a long way out was not made on the ground the officer was sent to.
 */
final class CellContainmentSignal implements Signal
{
    /** An H3 edge cuts through doorways. This much over is not a finding. */
    private const EDGE_TOLERANCE_M = 25.0;

    /** Past this the officer was working ground that was not theirs. */
    private const WRONG_GROUND_M = 250.0;

    public function key(): string
    {
        return 'cell_containment';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Identification;
    }

    public function weight(): int
    {
        return 8;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->insideAssignedCell === null) {
            return SignalResult::unknown('This capture is not tied to an assigned cell.');
        }

        if ($facts->insideAssignedCell) {
            return SignalResult::ok('The structure stands inside the assigned cell.');
        }

        $metres = $facts->metresOutsideCell ?? 0.0;
        $evidence = ['metres_outside_cell' => round($metres, 1)];

        if ($metres >= self::WRONG_GROUND_M) {
            return SignalResult::fail(
                'The structure stands '.round($metres).' m outside the assigned cell.',
                $evidence,
            );
        }

        if ($metres > self::EDGE_TOLERANCE_M) {
            return SignalResult::warn(
                'The structure sits '.round($metres).' m beyond the cell boundary.',
                $evidence,
            );
        }

        return SignalResult::ok('The structure sits on the cell boundary, within tolerance.', $evidence);
    }
}
