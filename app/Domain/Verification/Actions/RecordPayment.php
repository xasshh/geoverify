<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The provider says the money cleared. Three things follow, or none of them do.
 *
 * The money is recorded as ours to hold, the clock starts, and the order
 * becomes visible to a supervisor as work to give somebody. All three inside
 * one database transaction: an order that is paid but has no ledger entry is a
 * hole in the accounts, and one whose clock never started is a promise with no
 * deadline attached to it.
 *
 * Only reachable from the webhook. A browser returning from the payment page
 * carries no authority here: the customer's browser can be told anything, and
 * anybody who can read a URL could otherwise mark their own order paid.
 *
 * Idempotent, because a provider that does not get a 200 will send the event
 * again, and will sometimes send it again anyway. Called twice, the second call
 * changes nothing and posts nothing.
 */
final class RecordPayment
{
    public function __construct(
        private readonly PostTransaction $post,
        private readonly WorkingDays $workingDays,
    ) {}

    public function __invoke(
        VerificationOrder $order,
        string $paymentReference,
        ?Carbon $paidAt = null,
    ): VerificationOrder {
        return DB::transaction(function () use ($order, $paymentReference, $paidAt): VerificationOrder {
            /** @var VerificationOrder $fresh */
            $fresh = VerificationOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Already paid, by an earlier delivery of this same event or by a
            // replay of it. Returning the order as it stands is the correct
            // answer to "the money cleared" when the money has already cleared.
            if ($fresh->status !== OrderStatus::AwaitingPayment) {
                return $fresh;
            }

            $when = $paidAt ?? Carbon::now(config('app.timezone'));

            // Cash in, and an obligation of the same size out. The customer's
            // money is not income: we owe them a visit, and until an officer
            // has made it this is a debt we happen to be holding in cash.
            $transaction = ($this->post)(
                [
                    LedgerAccount::CASH => $fresh->amount_minor,
                    LedgerAccount::CUSTOMER_FUNDS_HELD => -$fresh->amount_minor,
                ],
                LedgerEntry::REASON_PAYMENT_RECEIVED,
                $fresh->id,
                "Payment for {$fresh->reference}",
                $when,
            );

            $fresh->update([
                'status' => OrderStatus::Paid,
                'paid_at' => $when,
                // The promise, computed from the day the money arrived rather
                // than the day the order was placed. A customer who takes a
                // week to pay has not spent a week of our ten days.
                'due_by' => $this->workingDays->after($when, $fresh->sla_working_days),
            ]);

            VerificationEvent::record($fresh, 'order.paid', null, [
                'reference' => $fresh->reference,
                'payment_reference' => $paymentReference,
                'amount_minor' => $fresh->amount_minor,
                'currency' => $fresh->currency,
                'transaction_uuid' => $transaction,
                'due_by' => $fresh->due_by?->toDateString(),
            ], VerificationEvent::ACTOR_EXTERNAL);

            return $fresh;
        });
    }
}
