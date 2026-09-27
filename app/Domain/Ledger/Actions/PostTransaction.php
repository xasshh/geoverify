<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only way anything is written to the ledger.
 *
 * A movement is two or more entries whose signed amounts sum to zero, written
 * in one transaction or not at all. That sum is checked here rather than
 * trusted, because a ledger that can be left half posted is a ledger nobody can
 * defend, and the failure would be silent for months.
 *
 * Correcting a mistake means posting the opposite movement. There is no update
 * path and the database refuses one.
 */
final class PostTransaction
{
    /**
     * @param  array<string, int>  $legs  Account code to signed minor units.
     * @param  int|null  $purchaseOrderId  A product order this movement is about, instead of a verification order.
     * @param  int|null  $payoutId  A merchant withdrawal this movement is about.
     */
    public function __invoke(
        array $legs,
        string $reason,
        ?int $orderId = null,
        ?string $narrative = null,
        ?Carbon $occurredAt = null,
        ?int $purchaseOrderId = null,
        ?int $payoutId = null,
    ): string {
        if (count($legs) < 2) {
            throw new RuntimeException('A movement has at least two sides.');
        }

        if (array_sum($legs) !== 0) {
            // The whole point of double entry. Money that appears from nowhere
            // is the one thing this table exists to make impossible.
            throw new RuntimeException(sprintf(
                'That movement does not balance: the legs sum to %d rather than zero.',
                array_sum($legs),
            ));
        }

        if (in_array(0, $legs, true)) {
            throw new RuntimeException('A leg of zero moves nothing and should not be posted.');
        }

        $uuid = (string) Str::uuid7();
        $when = $occurredAt ?? Carbon::now(config('app.timezone'));

        return DB::transaction(function () use ($legs, $reason, $orderId, $narrative, $uuid, $when, $purchaseOrderId, $payoutId): string {
            foreach ($legs as $code => $amount) {
                $account = LedgerAccount::query()->where('code', $code)->first();

                if (! $account instanceof LedgerAccount) {
                    throw new RuntimeException("There is no ledger account called {$code}.");
                }

                LedgerEntry::query()->create([
                    'transaction_uuid' => $uuid,
                    'ledger_account_id' => $account->id,
                    'amount_minor' => $amount,
                    'currency' => $account->currency,
                    'verification_order_id' => $orderId,
                    'purchase_order_id' => $purchaseOrderId,
                    'payout_id' => $payoutId,
                    'reason' => $reason,
                    'narrative' => $narrative,
                    'occurred_at' => $when,
                ]);
            }

            return $uuid;
        });
    }
}
