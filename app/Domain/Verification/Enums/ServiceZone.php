<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

/**
 * A multiplier, not bespoke pricing.
 *
 * Two zones rather than a distance formula, because a customer can be told
 * which zone they are in and why, where a formula produces a number nobody can
 * argue with or predict.
 *
 * Resolved server side from the structure's ward by the same ST_Contains
 * hierarchy the field platform already uses, and never client supplied.
 */
enum ServiceZone: string
{
    /** A ward with an active mandate or a serviced cluster. */
    case A = 'A';

    /** Everywhere else inside a covered LGA. */
    case B = 'B';

    public function label(): string
    {
        return match ($this) {
            self::A => 'Zone A, ground we already work',
            self::B => 'Zone B, a dedicated trip',
        };
    }
}
