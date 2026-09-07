<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Verification\Enums\OrderOutcome;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A supervisor accepts the officer's work, and the fee becomes ours.
 *
 * Income is recognised here and nowhere earlier. Not when the money arrived,
 * which bought a promise rather than a service, and not when the officer filed,
 * which is a claim nobody has checked yet. The obligation we have been carrying
 * since payment is discharged at the moment the work is accepted, which is the
 * moment it is actually delivered.
 *
 * Recognised whatever was found. An officer who attends and reports that the
 * business is not at that address has done the work and delivered the answer,
 * and it is a good answer: it is often the answer the customer most needed. The
 * outcome decides whether a tier is established, never whether we are paid.
 */
final class CompleteOrder
{
    public function __construct(
        private readonly PostTransaction $post,
        private readonly SyncOrderToVisit $visits,
    ) {}

    public function __invoke(
        VerificationOrder $order,
        User $supervisor,
        OrderOutcome $outcome,
        ?string $note = null,
    ): VerificationOrder {
        if (! $supervisor->role->supervises()) {
            throw new RuntimeException('Only a supervisor accepts a verification visit.');
        }

        // Catch the order up with the visit first. A supervisor accepting work
        // an officer synced this morning should not be told the order is still
        // sitting at assigned, which is a state machine detail and not their
        // problem.
        $order = ($this->visits)($order);

        return DB::transaction(function () use ($order, $supervisor, $outcome, $note): VerificationOrder {
            /** @var VerificationOrder $fresh */
            $fresh = VerificationOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->allowsMoveTo(OrderStatus::Completed)) {
                throw new RuntimeException(sprintf(
                    'An order that is %s cannot be completed.',
                    $fresh->status->label(),
                ));
            }

            // The debt is discharged and the fee is recognised. Two legs, one
            // movement: the liability cannot fall without the income rising by
            // the same amount, which is what makes the accounts defensible.
            $transaction = ($this->post)(
                [
                    LedgerAccount::CUSTOMER_FUNDS_HELD => $fresh->amount_minor,
                    LedgerAccount::VERIFICATION_INCOME => -$fresh->amount_minor,
                ],
                LedgerEntry::REASON_WORK_COMPLETED,
                $fresh->id,
                "Visit accepted for {$fresh->reference}",
            );

            $fresh->update([
                'status' => OrderStatus::Completed,
                'outcome' => $outcome,
                'completed_by' => $supervisor->id,
                'completed_at' => now(),
            ]);

            VerificationEvent::record($fresh, 'order.completed', $supervisor, array_filter([
                'reference' => $fresh->reference,
                'outcome' => $outcome->value,
                'establishes_tier' => $outcome->establishesTier() ? $fresh->tier : null,
                'amount_minor' => $fresh->amount_minor,
                'transaction_uuid' => $transaction,
                'note' => $note,
            ], static fn (mixed $v): bool => $v !== null));

            return $fresh;
        });
    }
}
