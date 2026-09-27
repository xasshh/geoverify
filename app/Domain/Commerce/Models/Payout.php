<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A withdrawal from a merchant's balance.
 *
 * Reserved out of the balance when asked for, and settled one way or the other
 * only by the provider's signed transfer webhook.
 *
 * @property int $id
 * @property string $reference
 * @property int $party_id
 * @property int $payout_account_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string|null $transfer_code
 * @property string|null $failure_reason
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property-read PayoutAccount|null $account
 */
final class Payout extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PAID = 'paid';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'reference', 'party_id', 'payout_account_id', 'amount_minor', 'currency', 'status',
        'transfer_code', 'failure_reason', 'requested_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'settled_at' => 'datetime'];
    }

    /** @return BelongsTo<PayoutAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id');
    }
}
