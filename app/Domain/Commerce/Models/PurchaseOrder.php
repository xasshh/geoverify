<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\PayChannel;
use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One buyer, one seller, the lines they agreed on, and where the money is.
 *
 * Status is moved only by the actions in App\Domain\Commerce\Actions, each of
 * which posts the ledger movement that goes with it in the same transaction.
 *
 * @property int $id
 * @property string $reference
 * @property int $enterprise_id
 * @property int $seller_party_id
 * @property int $buyer_account_id
 * @property PurchaseStatus $status
 * @property Protection $protection
 * @property PayChannel $channel
 * @property int $items_minor
 * @property int $delivery_minor
 * @property int $service_fee_minor
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $commission_minor
 * @property string $delivery_name
 * @property string $delivery_phone
 * @property string $delivery_address
 * @property string|null $delivery_note
 * @property Carbon|null $paid_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $released_at
 * @property string|null $released_by
 * @property Carbon|null $disputed_at
 * @property string|null $dispute_reason
 * @property Carbon|null $refunded_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property-read Enterprise|null $enterprise
 * @property-read PortalAccount|null $buyer
 * @property-read Party|null $seller
 */
final class PurchaseOrder extends Model
{
    public const RELEASED_BY_BUYER = 'buyer';

    public const RELEASED_BY_WINDOW = 'window';

    public const RELEASED_BY_RULING = 'ruling';

    protected $fillable = [
        'reference', 'enterprise_id', 'seller_party_id', 'buyer_account_id', 'status', 'protection', 'channel',
        'items_minor', 'delivery_minor', 'service_fee_minor', 'amount_minor', 'currency', 'commission_minor',
        'delivery_name', 'delivery_phone', 'delivery_address', 'delivery_note',
        'paid_at', 'dispatched_at', 'released_at', 'released_by', 'disputed_at', 'dispute_reason',
        'refunded_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'protection' => Protection::class,
            'channel' => PayChannel::class,
            'items_minor' => 'integer',
            'delivery_minor' => 'integer',
            'service_fee_minor' => 'integer',
            'amount_minor' => 'integer',
            'commission_minor' => 'integer',
            'paid_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'released_at' => 'datetime',
            'disputed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'buyer_account_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'seller_party_id');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    /** @return MorphMany<VerificationEvent, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(VerificationEvent::class, 'subject')->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * What the merchant receives if this order is released now: the goods and
     * the delivery, less commission on the goods. The buyer's service fee was
     * paid to us for our agent and is never the merchant's.
     */
    public function netPayoutMinor(?int $commission = null): int
    {
        return $this->items_minor + $this->delivery_minor - ($commission ?? $this->commission_minor ?? $this->commissionDue());
    }

    /** Commission at the configured rate, rounded down to the kobo in the merchant's favour. */
    public function commissionDue(): int
    {
        $bps = (int) config('geoverify.commerce.commission_basis_points', 0);

        return intdiv($this->items_minor * max(0, $bps), 10_000);
    }
}
