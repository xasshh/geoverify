<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

/**
 * How quickly we have promised to attend.
 *
 * Express is priced at roughly 1.7x rather than double, because it does not
 * cost much more to perform: it costs queue priority, which is a scheduling
 * concession rather than a labour one. Pricing it at double would be charging
 * for urgency we are not actually incurring.
 */
enum OrderUrgency: string
{
    case Standard = 'standard';
    case Express = 'express';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Express => 'Express',
        };
    }

    /** Where a visit sorts on the officer's board. Higher goes first. */
    public function priority(): int
    {
        return $this === self::Express ? 20 : 10;
    }
}
