<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use RuntimeException;

/**
 * The gateway did not take the message. The text is what the person sees, so
 * it says nothing about providers, balances or sender IDs.
 */
final class SmsUndelivered extends RuntimeException
{
    public static function make(): self
    {
        return new self('We cannot send a code right now. Please try again shortly.');
    }
}
