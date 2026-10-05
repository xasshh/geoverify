<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Party\Events\SignInCodeIssued;
use App\Domain\Sms\SmsGateway;

/**
 * Puts a one-time code in front of the person who asked for it.
 *
 * Synchronous on purpose: they are on the code screen waiting, and a gateway
 * that refused must reach them as an error there (SmsUndelivered), not as a
 * text that never comes.
 */
final class DeliverSignInCode
{
    public function __construct(private readonly SmsGateway $sms) {}

    public function handle(SignInCodeIssued $event): void
    {
        $this->sms->send(
            $event->phone,
            "GeoVerify: {$event->code} is your sign-in code. It expires in 5 minutes. Never share it with anyone.",
        );
    }
}
