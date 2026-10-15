<?php

declare(strict_types=1);

namespace App\Domain\Election\Models;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Coverage\Models\AdminBoundary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A polling unit as a list gave it to us, and what our officers found.
 *
 * The listed state, LGA and ward are the list's own words. The boundary ids
 * are resolved in PostGIS from a point, never from those words.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $inec_code
 * @property string $name
 * @property string|null $description
 * @property string|null $listed_state
 * @property string|null $listed_lga
 * @property string|null $listed_ward
 * @property int|null $registered_voters
 * @property string $status
 * @property float|null $confirmed_accuracy_m
 * @property float|null $listed_distance_m
 * @property Carbon|null $settled_at
 * @property int|null $settled_by_check_id
 * @property int|null $state_id
 * @property int|null $lga_id
 * @property int|null $ward_id
 * @property int $imported_by
 */
final class PollingUnit extends Model
{
    public const LISTED = 'listed';

    public const ASSIGNED = 'assigned';

    public const FILED = 'filed';

    public const CONFIRMED = 'confirmed';

    public const NOT_FOUND = 'not_found';

    public const STATUSES = [self::LISTED, self::ASSIGNED, self::FILED, self::CONFIRMED, self::NOT_FOUND];

    protected $fillable = [
        'campaign_id', 'inec_code', 'name', 'description', 'listed_state', 'listed_lga', 'listed_ward',
        'registered_voters', 'status', 'confirmed_accuracy_m', 'listed_distance_m', 'settled_at',
        'settled_by_check_id', 'state_id', 'lga_id', 'ward_id', 'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'registered_voters' => 'integer',
            'confirmed_accuracy_m' => 'float',
            'listed_distance_m' => 'float',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<PollingUnitCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(PollingUnitCheck::class);
    }

    /** @return BelongsTo<PollingUnitCheck, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(PollingUnitCheck::class, 'settled_by_check_id');
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'lga_id');
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class, 'ward_id');
    }

    /** "Ward, LGA, State" as the list gave it. */
    public function listedPlace(): ?string
    {
        $parts = array_filter([$this->listed_ward, $this->listed_lga, $this->listed_state], static fn (?string $p): bool => $p !== null && $p !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
