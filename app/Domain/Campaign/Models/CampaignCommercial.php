<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\PaymentStatus;
use Database\Factories\Domain\Campaign\Models\CampaignCommercialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Contract value, payment state and internal notes. Super admin only.
 *
 * Never loaded by a client-facing query, and never eager loaded anywhere. The
 * separation is the safeguard: this cannot be serialised by accident because
 * nothing a client screen builds ever reaches for the relationship.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string|null $contract_value
 * @property string $currency
 * @property PaymentStatus $payment_status
 * @property Carbon|null $paid_at
 * @property string|null $internal_notes
 */
final class CampaignCommercial extends Model
{
    /** @use HasFactory<CampaignCommercialFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'contract_value', 'currency', 'payment_status',
        'paid_at', 'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            // Decimal rather than float. Money that has been through a float is
            // money somebody will eventually have to explain.
            'contract_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
