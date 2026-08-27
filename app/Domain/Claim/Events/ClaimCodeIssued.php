<?php

declare(strict_types=1);

namespace App\Domain\Claim\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A code is on its way to the number an officer recorded at a business.
 *
 * Deliberately not SignInCodeIssued. A sign-in code goes to a phone the
 * recipient already proved they hold; this one goes to a phone they are trying
 * to prove they hold, and it may well reach a stranger. That changes what the
 * message has to say: it names the business and tells the reader to ignore it
 * if they did not ask, because for some fraction of these the reader is the
 * real owner and the sender is not.
 */
final class ClaimCodeIssued
{
    use Dispatchable;

    public function __construct(
        public readonly string $phone,
        public readonly string $code,
        public readonly string $maskedPhone,
        public readonly string $tradingName,
        public readonly int $claimId,
    ) {}
}
