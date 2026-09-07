<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a rung costs today.
 *
 * @property int $id
 * @property string $tier
 * @property OrderUrgency $urgency
 * @property ServiceZone $zone
 * @property int $amount_minor
 * @property string $currency
 * @property int $sla_working_days
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
final class VerificationPrice extends Model
{
    protected $fillable = [
        'tier', 'urgency', 'zone', 'amount_minor', 'currency',
        'sla_working_days', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'urgency' => OrderUrgency::class,
            'zone' => ServiceZone::class,
            'amount_minor' => 'integer',
            'sla_working_days' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /**
     * The price in force. Superseded rows stay for the orders that used them.
     *
     * @param  Builder<VerificationPrice>  $query
     * @return Builder<VerificationPrice>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }
}
