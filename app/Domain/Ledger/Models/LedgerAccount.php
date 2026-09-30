<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in the ledger.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string $currency
 */
final class LedgerAccount extends Model
{
    /** Money sitting in the payment provider's account, ours to draw on. */
    public const CASH = 'cash.paystack';

    /**
     * What we owe customers for work not yet done.
     *
     * Named for the obligation it is. Money a customer has paid for a visit
     * that has not happened is theirs until it has, and this account says so.
     */
    public const CUSTOMER_FUNDS_HELD = 'liability.customer_funds_held';

    /** Recognised when a supervisor accepts the work, and not before. */
    public const VERIFICATION_INCOME = 'income.verification_fees';

    /** Money handed back, whether for an SLA breach or a withdrawal. */
    public const REFUNDS = 'expense.refunds';

    /**
     * What buyers have paid for goods they have not yet confirmed receiving.
     *
     * Theirs until they confirm, or until a ruling says otherwise. Kept apart
     * from CUSTOMER_FUNDS_HELD because the two are released by different
     * events and a balance that mixed them could not say which it was waiting on.
     */
    public const BUYER_FUNDS_HELD = 'liability.buyer_funds_held';

    /** What we owe merchants for delivered orders: their available balance. */
    public const MERCHANT_BALANCES = 'liability.merchant_balances';

    /** A withdrawal asked of the provider and not yet confirmed by it. */
    public const PAYOUTS_IN_TRANSIT = 'liability.payouts_in_transit';

    /** Commission and service fees on a released order. */
    public const COMMERCE_INCOME = 'income.commerce_fees';

    /**
     * Prepaid credit Enumerate requesters hold with us (E1).
     *
     * Theirs until spent on a request, when it moves to CUSTOMER_FUNDS_HELD
     * like any other payment for work not yet done. Each entry names the
     * wallet, and a wallet's balance is the negated sum of its entries here.
     */
    public const REQUESTER_WALLETS = 'liability.requester_wallets';

    protected $fillable = ['code', 'name', 'type', 'currency'];

    protected function casts(): array
    {
        return ['type' => AccountType::class];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
