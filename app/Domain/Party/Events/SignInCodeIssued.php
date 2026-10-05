<?php

declare(strict_types=1);

namespace App\Domain\Party\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A one-time code has been created and needs to reach a phone.
 *
 * Issuing and delivering are separated because they fail differently and will
 * be operated differently: issuing is ours, delivering belongs to the SMS
 * gateway (DeliverSignInCode), and changing gateway touches nothing in the
 * sign-in path.
 *
 * The code is carried in memory to the listener and is never written anywhere
 * readable. What is stored is its hash.
 */
final class SignInCodeIssued
{
    use Dispatchable;

    public function __construct(
        public readonly string $phone,
        public readonly string $code,
        public readonly string $maskedPhone,
    ) {}
}
