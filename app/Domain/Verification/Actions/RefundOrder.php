<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The money goes back.
 *
 * Two roads reach here and they post differently. An order still holding funds
 * has an obligation to cancel: the liability falls and the cash falls with it,
 * and no income was ever recognised. An order already completed has income we
 * have to give back, which is an expense rather than an un-earning, because a
 * ledger that let recognised income quietly decrease could be made to say
 * anything about a past quarter.
 *
 * The refund is not a favour and does not wait to be asked for. An SLA breach
 * is our failure, the sweep finds it the morning after it happens, and the
 * customer hears about it from us.
 */
final class RefundOrder
{
    public function __construct(private readonly PostTransaction $post) {}

    public function __invoke(
        VerificationOrder $order,
        string $reason,
        ?User $decidedBy = null,
    ): VerificationOrder {
        return DB::transaction(function () use ($order, $reason, $decidedBy): VerificationOrder {
            /** @var VerificationOrder $fresh */
            $fresh = VerificationOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status === OrderStatus::Refunded) {
                // Already returned. Posting again would hand the money back
                // twice, and a sweep that runs every morning would do it every
                // morning.
                return $fresh;
            }

            $wasCompleted = $fresh->status === OrderStatus::Completed;

            if (! $wasCompleted && ! $fresh->status->holdsFunds()) {
                throw new RuntimeException(sprintf(
                    'An order that is %s is holding nothing to return.',
                    $fresh->status->label(),
                ));
            }

            $legs = $wasCompleted
                ? [
                    LedgerAccount::REFUNDS => $fresh->amount_minor,
                    LedgerAccount::CASH => -$fresh->amount_minor,
                ]
                : [
                    LedgerAccount::CUSTOMER_FUNDS_HELD => $fresh->amount_minor,
                    LedgerAccount::CASH => -$fresh->amount_minor,
                ];

            $transaction = ($this->post)(
                $legs,
                LedgerEntry::REASON_REFUNDED,
                $fresh->id,
                "Refund of {$fresh->reference}: {$reason}",
            );

            $fresh->update([
                'status' => OrderStatus::Refunded,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            VerificationEvent::record(
                $fresh,
                'order.refunded',
                $decidedBy,
                [
                    'reference' => $fresh->reference,
                    'reason' => $reason,
                    'amount_minor' => $fresh->amount_minor,
                    'against' => $wasCompleted ? 'recognised income' : 'funds held',
                    'transaction_uuid' => $transaction,
                ],
                $decidedBy instanceof User
                    ? VerificationEvent::ACTOR_USER
                    : VerificationEvent::ACTOR_SYSTEM,
            );

            return $fresh;
        });
    }
}
