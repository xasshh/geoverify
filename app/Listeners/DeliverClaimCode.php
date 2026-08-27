<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Claim\Events\ClaimCodeIssued;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class DeliverClaimCode
{
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

        if (app()->isLocal()) {
            Log::info("Claim code to {$event->phone}: {$body}");

            return;
        }

        Log::error('No SMS gateway is configured, so a claim code could not be delivered.', [
            'phone' => $event->maskedPhone,
            'claim_id' => $event->claimId,
        ]);

        throw new RuntimeException('We cannot send a code right now. Please try again shortly.');
    }
}
