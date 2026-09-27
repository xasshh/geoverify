<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything that happens to a product order after it is placed, by whoever
 * is entitled to make it happen.
 *
 * Who may do what is checked here rather than in a controller, because a
 * second caller (the sweep, a console command, M3's inspection) must meet the
 * same rule:
 *
 *   the merchant dispatches;
 *   the buyer confirms, raises an issue, or abandons an unpaid order;
 *   the window releases what nobody spoke up about;
 *   an admin rules on an issue.
 *
 * Every move appends to verification_events, and every move that touches money
 * posts its movement in the same transaction.
 */
final class ManagePurchase
{
    public function __construct(
        private readonly ReleasePurchase $release,
        private readonly PostTransaction $post,
    ) {}

    public function dispatch(PurchaseOrder $order, PartyUser $membership): PurchaseOrder
    {
        $this->assertSeller($order, $membership);

        return $this->move($order, PurchaseStatus::Dispatched, function (PurchaseOrder $fresh) use ($membership): void {
            // Until M3, nothing but an unprotected order can exist. When
            // inspections do, an inspected order ships only once the buyer has
            // approved the report, and this is where that will be enforced.
            if ($fresh->protection->value !== 'none') {
                throw new RuntimeException('This order waits for its inspection before it can be sent.');
            }

            $fresh->update(['status' => PurchaseStatus::Dispatched, 'dispatched_at' => now()]);

            VerificationEvent::recordForParty($fresh, 'purchase.dispatched', $membership->party()->firstOrFail(), [
                'reference' => $fresh->reference,
                'by_account' => $membership->portal_account_id,
            ]);
        });
    }

    public function confirm(PurchaseOrder $order, PortalAccount $buyer): PurchaseOrder
    {
        $this->assertBuyer($order, $buyer);

        return ($this->release)($order, PurchaseOrder::RELEASED_BY_BUYER, static function (PurchaseOrder $fresh, string $tx) use ($buyer): void {
            VerificationEvent::recordForBuyer($fresh, 'purchase.confirmed', $buyer, [
                'reference' => $fresh->reference,
                'transaction_uuid' => $tx,
            ]);
        });
    }

    /** The window closed on a dispatched order nobody spoke up about. */
    public function releaseByWindow(PurchaseOrder $order): PurchaseOrder
    {
        return ($this->release)($order, PurchaseOrder::RELEASED_BY_WINDOW, static function (PurchaseOrder $fresh, string $tx): void {
            VerificationEvent::record($fresh, 'purchase.released_by_window', null, [
                'reference' => $fresh->reference,
                'dispatched_at' => $fresh->dispatched_at?->toIso8601String(),
                'days' => (int) config('geoverify.commerce.release_after_days'),
                'transaction_uuid' => $tx,
            ], VerificationEvent::ACTOR_SYSTEM);
        });
    }

    public function raiseIssue(PurchaseOrder $order, PortalAccount $buyer, string $reason): PurchaseOrder
    {
        $this->assertBuyer($order, $buyer);

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new RuntimeException('Tell us what went wrong, in a sentence or two.');
        }

        return $this->move($order, PurchaseStatus::Disputed, static function (PurchaseOrder $fresh) use ($buyer, $reason): void {
            $fresh->update([
                'status' => PurchaseStatus::Disputed,
                'disputed_at' => now(),
                'dispute_reason' => mb_substr($reason, 0, 500),
            ]);

            VerificationEvent::recordForBuyer($fresh, 'purchase.disputed', $buyer, [
                'reference' => $fresh->reference,
                'reason' => mb_substr($reason, 0, 500),
            ]);
        });
    }

    /** An unpaid order the buyer no longer wants. No money exists to move. */
    public function cancel(PurchaseOrder $order, PortalAccount $buyer): PurchaseOrder
    {
        $this->assertBuyer($order, $buyer);

        return $this->move($order, PurchaseStatus::Cancelled, static function (PurchaseOrder $fresh) use ($buyer): void {
            $fresh->update(['status' => PurchaseStatus::Cancelled, 'cancelled_at' => now()]);

            VerificationEvent::recordForBuyer($fresh, 'purchase.cancelled', $buyer, ['reference' => $fresh->reference]);
        });
    }

    /**
     * An admin decides an issue: pay the merchant, or return the buyer's money.
     *
     * A refund posts the held amount back out of cash, the same movement
     * RefundOrder posts for a verification order, and the provider-side refund
     * is made by the person who ruled, as it is there.
     */
    public function rule(PurchaseOrder $order, User $admin, bool $forBuyer, string $note): PurchaseOrder
    {
        if (! $admin->role->administers()) {
            throw new RuntimeException('Only an admin rules on an issue.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('Write down why. The buyer and the merchant will both ask.');
        }

        // Read as it is now, not as the caller last saw it. Release accepts an
        // order that is merely held or dispatched, so a stale copy saying
        // "disputed" would otherwise let a ruling land where there is no issue.
        $order = PurchaseOrder::query()->findOrFail($order->id);

        if ($order->status !== PurchaseStatus::Disputed) {
            throw new RuntimeException('There is no open issue on that order.');
        }

        if (! $forBuyer) {
            return ($this->release)($order, PurchaseOrder::RELEASED_BY_RULING, static function (PurchaseOrder $fresh, string $tx) use ($admin, $note): void {
                VerificationEvent::record($fresh, 'purchase.ruled_for_merchant', $admin, [
                    'reference' => $fresh->reference,
                    'note' => $note,
                    'transaction_uuid' => $tx,
                ]);
            });
        }

        return $this->move($order, PurchaseStatus::Refunded, function (PurchaseOrder $fresh) use ($admin, $note): void {
            $transaction = ($this->post)(
                [
                    LedgerAccount::BUYER_FUNDS_HELD => $fresh->amount_minor,
                    LedgerAccount::CASH => -$fresh->amount_minor,
                ],
                LedgerEntry::REASON_REFUNDED,
                null,
                "Refund of {$fresh->reference}: {$note}",
                purchaseOrderId: $fresh->id,
            );

            $fresh->update(['status' => PurchaseStatus::Refunded, 'refunded_at' => now()]);

            VerificationEvent::record($fresh, 'purchase.ruled_for_buyer', $admin, [
                'reference' => $fresh->reference,
                'note' => $note,
                'amount_minor' => $fresh->amount_minor,
                'transaction_uuid' => $transaction,
            ]);
        });
    }

    /** @param  callable(PurchaseOrder): void  $apply */
    private function move(PurchaseOrder $order, PurchaseStatus $to, callable $apply): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $to, $apply): PurchaseOrder {
            /** @var PurchaseOrder $fresh */
            $fresh = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === $to) {
                return $fresh;
            }

            if (! $fresh->status->allowsMoveTo($to)) {
                throw new RuntimeException("An order that is {$fresh->status->label()} cannot become {$to->label()}.");
            }

            $apply($fresh);

            return $fresh;
        });
    }

    private function assertBuyer(PurchaseOrder $order, PortalAccount $buyer): void
    {
        if ($order->buyer_account_id !== $buyer->id) {
            throw new RuntimeException('That order is not yours.');
        }
    }

    private function assertSeller(PurchaseOrder $order, PartyUser $membership): void
    {
        if ($order->seller_party_id !== $membership->party_id || ! $membership->isLive()) {
            throw new RuntimeException('That order is not for your business.');
        }

        if (! $membership->role->fulfils()) {
            throw new RuntimeException('Your role can see orders but not send them.');
        }
    }
}
