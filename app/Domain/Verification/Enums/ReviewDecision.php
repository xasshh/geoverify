<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

use App\Domain\Registry\Models\Structure;

/**
 * What a supervisor decided about a capture.
 *
 * Three outcomes, not two. Escalation exists because the middle case is real: a
 * supervisor who suspects fabrication should not be the person who decides it
 * alone, and forcing that judgement into "accept" or "return" is how a false
 * accusation against an officer gets made quietly.
 */
enum ReviewDecision: string
{
    /** The record stands. It enters the register. */
    case Accept = 'accept';

    /** Back to the officer, with a reason, to be done again. */
    case Return = 'return';

    /** Held, and raised. Nobody's word against anybody's. */
    case Escalate = 'escalate';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Accepted',
            self::Return => 'Returned',
            self::Escalate => 'Escalated',
        };
    }

    /** The status the observation lands on. */
    public function observationStatus(): string
    {
        return match ($this) {
            self::Accept => Structure::STATUS_ACCEPTED,
            self::Return => Structure::STATUS_REJECTED,
            self::Escalate => Structure::STATUS_FLAGGED,
        };
    }

    /** The event written to the log. The log is the product. */
    public function event(): string
    {
        return match ($this) {
            self::Accept => 'observation.accepted',
            self::Return => 'observation.returned',
            self::Escalate => 'observation.escalated',
        };
    }

    /** Whether the officer has to be told, and the work handed back. */
    public function returnsToOfficer(): bool
    {
        return $this === self::Return;
    }

    /** A reason is not optional when work is sent back or raised. */
    public function requiresReason(): bool
    {
        return $this !== self::Accept;
    }
}
