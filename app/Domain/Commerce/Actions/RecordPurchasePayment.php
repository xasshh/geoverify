<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The provider says a buyer's money cleared. It is held, and the merchant is
 * told to pack.
 *
 * Reachable from HandlePaymentWebhook and nowhere else, like RecordPayment and
 * for the same reason. Idempotent under redelivery: an order that is no longer
 * awaiting payment is returned as it stands and nothing is posted.
 *
 * One check RecordPayment does not need. The amount the provider says it
 * collected must be the amount on the order; if it is not, the money is not
 * recorded against the order and somebody is told, because a buyer who paid
 * less has not paid for what the merchant is about to send.
 */
final class RecordPurchasePayment
{
    public function __construct(private readonly PostTransaction $post) {}

    public function __invoke(
        PurchaseOrder $order,
        string $paymentReference,
        int $amountMinor,
        ?Carbon $paidAt = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($order, $paymentReference, $amountMinor, $paidAt): PurchaseOrder {
            /** @var PurchaseOrder $fresh */
            $fresh = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PurchaseStatus::AwaitingPayment) {
                return $fresh;
            }

            if ($amountMinor !== $fresh->amount_minor) {
                Log::error('A payment arrived for a product order at the wrong amount.', [
                    'reference' => $fresh->reference,
                    'expected_minor' => $fresh->amount_minor,
                    'received_minor' => $amountMinor,
                ]);

                VerificationEvent::record($fresh, 'purchase.payment_mismatched', null, [
                    'payment_reference' => $paymentReference,
                    'expected_minor' => $fresh->amount_minor,
                    'received_minor' => $amountMinor,
                ], VerificationEvent::ACTOR_EXTERNAL);

                return $fresh;
            }

            $when = $paidAt ?? Carbon::now(config('app.timezone'));

            // Cash in, and an obligation to the buyer of the same size. Not the
            // merchant's yet, and not ours at all.
            $transaction = ($this->post)(
                [
                    LedgerAccount::CASH => $fresh->amount_minor,
                    LedgerAccount::BUYER_FUNDS_HELD => -$fresh->amount_minor,
                ],
                LedgerEntry::REASON_PAYMENT_RECEIVED,
                null,
                "Payment for {$fresh->reference}",
                $when,
                purchaseOrderId: $fresh->id,
            );

            $fresh->update(['status' => PurchaseStatus::Held, 'paid_at' => $when]);

            VerificationEvent::record($fresh, 'purchase.paid', null, [
                'reference' => $fresh->reference,
                'payment_reference' => $paymentReference,
                'amount_minor' => $fresh->amount_minor,
                'channel' => $fresh->channel->value,
                'transaction_uuid' => $transaction,
            ], VerificationEvent::ACTOR_EXTERNAL);

            return $fresh;
        });
    }
}
