<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

/**
 * What one signal concluded.
 *
 * Unknown is not a soft fail. A device that never reported a network position
 * has told us nothing, and charging a capture for the hardware it was taken on
 * would punish the officer holding the older handset.
 */
enum Verdict: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Unknown = 'unknown';

    /** The share of a signal's weight this verdict takes off the score. */
    public function penalty(): float
    {
        return match ($this) {
            self::Ok, self::Unknown => 0.0,
            self::Warn => 0.5,
            self::Fail => 1.0,
        };
    }

    /** Whether this is worth showing in the review chrome as a flag. */
    public function isFlag(): bool
    {
        return $this === self::Warn || $this === self::Fail;
    }
}
