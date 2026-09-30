<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A CSV of businesses checked at one tier, paid from the organisation wallet.
 *
 * @property int $id
 * @property string $reference
 * @property int $organisation_id
 * @property int $created_by
 * @property int $tier
 * @property int|null $monitoring_days
 * @property int $rows_total
 * @property int $rows_placed
 * @property int $rows_refused
 * @property int $total_minor
 * @property Carbon|null $created_at
 */
final class EnumerateBatch extends Model
{
    protected $fillable = [
        'reference', 'organisation_id', 'created_by', 'tier', 'monitoring_days',
        'rows_total', 'rows_placed', 'rows_refused', 'total_minor',
    ];

    protected function casts(): array
    {
        return ['tier' => 'integer', 'total_minor' => 'integer'];
    }

    /** @return HasMany<EnumerateBatchRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(EnumerateBatchRow::class)->orderBy('line');
    }
}
