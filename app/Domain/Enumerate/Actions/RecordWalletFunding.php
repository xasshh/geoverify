<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\WalletFunding;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The provider says a top-up arrived. The wallet grows by exactly that much.
 *
 * Reachable from HandlePaymentWebhook and nowhere else. Idempotent under
 * redelivery: a funding that is no longer pending is returned as it stands.
 * An amount that differs from the one asked for is not credited, and is left
 * for somebody to look at, as a product order's would be.
 */
final class RecordWalletFunding
{
    public function __construct(private readonly PostTransaction $post) {}

    public function __invoke(WalletFunding $funding, string $paymentReference, int $amountMinor, ?Carbon $paidAt = null): WalletFunding
    {
        return DB::transaction(function () use ($funding, $paymentReference, $amountMinor, $paidAt): WalletFunding {
            /** @var WalletFunding $fresh */
            $fresh = WalletFunding::query()->whereKey($funding->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== WalletFunding::PENDING) {
                return $fresh;
            }

            if ($amountMinor !== $fresh->amount_minor) {
                Log::error('A wallet top-up arrived at the wrong amount.', [
                    'reference' => $fresh->reference,
                    'expected_minor' => $fresh->amount_minor,
                    'received_minor' => $amountMinor,
                ]);

                VerificationEvent::record($fresh, 'wallet.funding_mismatched', null, [
                    'payment_reference' => $paymentReference,
                    'expected_minor' => $fresh->amount_minor,
                    'received_minor' => $amountMinor,
                ], VerificationEvent::ACTOR_EXTERNAL);

                return $fresh;
            }

            $when = $paidAt ?? Carbon::now(config('app.timezone'));

            // Cash in, and an obligation of the same size to the person who
            // now holds it as credit.
            $transaction = ($this->post)(
                [
                    LedgerAccount::CASH => $fresh->amount_minor,
                    LedgerAccount::REQUESTER_WALLETS => -$fresh->amount_minor,
                ],
                LedgerEntry::REASON_PAYMENT_RECEIVED,
                null,
                "Wallet funding {$fresh->reference}",
                $when,
                walletFundingId: $fresh->id,
                walletId: $fresh->wallet_id,
            );

            $fresh->update(['status' => WalletFunding::PAID, 'paid_at' => $when]);

            VerificationEvent::record($fresh, 'wallet.funded', null, [
                'reference' => $fresh->reference,
                'payment_reference' => $paymentReference,
                'amount_minor' => $fresh->amount_minor,
                'channel' => $fresh->channel,
                'transaction_uuid' => $transaction,
            ], VerificationEvent::ACTOR_EXTERNAL);

            return $fresh;
        });
    }
}
