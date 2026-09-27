<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The buyer's money becomes the merchant's.
 *
 * Three roads reach here and they are recorded as different events: the buyer
 * confirmed, the window after dispatch closed with neither a confirmation nor
 * an issue, or an admin ruled for the merchant. Each caller records who did it;
 * this posts the movement and moves the state, once.
 *
 * The movement: the whole held amount leaves BUYER_FUNDS_HELD. The goods and
 * the delivery, less commission on the goods, go to the merchant's balance.
 * The commission and any service fee the buyer paid for our agent are ours.
 * Nothing leaves the provider here: a merchant's balance is still cash we hold,
 * and becomes a transfer only when they withdraw it.
 */
final class ReleasePurchase
{
    public function __construct(private readonly PostTransaction $post) {}

    /** @param  callable(PurchaseOrder, string): void  $record  Writes the event, inside the transaction. */
    public function __invoke(PurchaseOrder $order, string $by, callable $record): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by, $record): PurchaseOrder {
            /** @var PurchaseOrder $fresh */
            $fresh = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === PurchaseStatus::Released) {
                return $fresh;
            }

            if (! $fresh->status->allowsMoveTo(PurchaseStatus::Released)) {
                throw new RuntimeException("An order that is {$fresh->status->label()} cannot be released.");
            }

            $commission = $fresh->commissionDue();
            $toMerchant = $fresh->netPayoutMinor($commission);
            $ours = $commission + $fresh->service_fee_minor;

            $legs = [
                LedgerAccount::BUYER_FUNDS_HELD => $fresh->amount_minor,
                LedgerAccount::MERCHANT_BALANCES => -$toMerchant,
            ];

            if ($ours > 0) {
                $legs[LedgerAccount::COMMERCE_INCOME] = -$ours;
            }

            $transaction = ($this->post)(
                $legs,
                LedgerEntry::REASON_RELEASED,
                null,
                "Release of {$fresh->reference} ({$by})",
                purchaseOrderId: $fresh->id,
            );

            $fresh->update([
                'status' => PurchaseStatus::Released,
                'released_at' => now(),
                'released_by' => $by,
                'commission_minor' => $commission,
            ]);

            $record($fresh, $transaction);

            return $fresh;
        });
    }
}
