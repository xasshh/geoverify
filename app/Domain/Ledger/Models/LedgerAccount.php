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
