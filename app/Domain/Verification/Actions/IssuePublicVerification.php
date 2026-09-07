<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Models\PublicVerification;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Carbon;

/**
 * Mints the token a certificate's QR code carries.
 *
 * Issued for every completed order, including the ones that found nothing. A
 * register that only handed out a checkable token when the answer was flattering
 * would be selling a conclusion rather than a visit, and the negative findings
 * are the ones a buyer most needs to be able to check. A business that does not
 * like its answer simply does not print the certificate.
 *
 * The validity window comes from the same configured freshness thresholds the
 * tier ladder reads, so a scanned code and a listing page cannot disagree about
 * whether a check is still current.
 */
final class IssuePublicVerification
{
    public function __invoke(VerificationOrder $order): PublicVerification
    {
        // One live token per order. Completion is guarded by a status
        // transition that cannot run twice, but an order that is somehow
        // completed again must not scatter a second checkable code into the
        // world while the first is still valid.
        $existing = PublicVerification::query()
            ->where('verification_order_id', $order->id)
            ->whereNull('revoked_at')
            ->first();

        if ($existing instanceof PublicVerification) {
            return $existing;
        }

        $issuedAt = Carbon::now(config('app.timezone'));

        $months = (int) config('geoverify.tier_freshness.stale_months', 24);

        $verification = PublicVerification::query()->create([
            'token' => PublicVerification::mintToken(),
            'verification_order_id' => $order->id,
            'enterprise_id' => $order->enterprise_id,
            'issued_at' => $issuedAt,
            'valid_until' => $issuedAt->copy()->addMonths($months)->toDateString(),
        ]);

        VerificationEvent::record($order, 'verification.published', null, [
            'reference' => $order->reference,
            'valid_until' => $verification->valid_until?->toDateString(),
        ], VerificationEvent::ACTOR_SYSTEM);

        return $verification;
    }
}
