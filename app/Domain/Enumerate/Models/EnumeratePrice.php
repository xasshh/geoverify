<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The price of a tier, and for Tier 3 of a monitoring period. A change is a new
 * row with the old one closed, so a request keeps the price it was sold at.
 *
 * @property int $id
 * @property int $tier
 * @property int|null $days
 * @property int $amount_minor
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
final class EnumeratePrice extends Model
{
    protected $fillable = ['tier', 'days', 'amount_minor', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'days' => 'integer',
            'amount_minor' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }
}
