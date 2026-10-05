<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Claim\Events\ClaimCodeIssued;
use App\Domain\Sms\SmsGateway;

final class DeliverClaimCode
{
    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * The message a stranger might receive.
     *
     * Worded for the case where the claim is not legitimate, because that is
     * the case that matters: the real owner is the one holding this phone, and
     * this text is the only warning they will get that someone is trying to
     * take their listing.
     */
    public function handle(ClaimCodeIssued $event): void
    {
        $body = "GeoVerify: {$event->code} is the code to confirm you control "
            ."{$event->tradingName}. If you did not ask for this, ignore it and "
            .'tell nobody the code.';

        $this->sms->send($event->phone, $body);
    }
}
