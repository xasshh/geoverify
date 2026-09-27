<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One half of a movement.
 *
 * Append only, and the database enforces it: a mistake is corrected by posting
 * the opposite movement, never by editing this row. An adjustable ledger is a
 * spreadsheet.
 *
 * @property int $id
 * @property string $transaction_uuid
 * @property int $ledger_account_id
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $verification_order_id
 * @property int|null $purchase_order_id
 * @property int|null $payout_id
 * @property string $reason
 * @property Carbon $occurred_at
 */
final class LedgerEntry extends Model
{
    public const REASON_PAYMENT_RECEIVED = 'payment_received';

    public const REASON_WORK_COMPLETED = 'work_completed';

    public const REASON_REFUNDED = 'refunded';

    public const REASON_RELEASED = 'released';

    public const REASON_PAYOUT_REQUESTED = 'payout_requested';

    public const REASON_PAYOUT_SENT = 'payout_sent';

    public const REASON_PAYOUT_RETURNED = 'payout_returned';

    protected $fillable = [
        'transaction_uuid', 'ledger_account_id', 'amount_minor', 'currency',
        'verification_order_id', 'purchase_order_id', 'payout_id', 'reason', 'narrative', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
