<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * One testable reason to doubt a capture.
 *
 * Each signal is its own class so each can be tested on its own, in isolation
 * from the arithmetic that combines them. A signal reads assembled facts and
 * never touches the database: the spatial work is already done in PostGIS by the
 * time it is asked anything.
 */
interface Signal
{
    /** Stable identifier. It is stored, so it does not change once shipped. */
    public function key(): string;

    public function question(): ReviewQuestion;

    /** The most this signal can take off a score of 100. */
    public function weight(): int;

    public function evaluate(CaptureFacts $facts): SignalResult;
}
