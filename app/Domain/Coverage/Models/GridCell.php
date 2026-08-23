<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One H3 cell: the unit of work an officer is assigned. */
/**
 * @property int $id
 * @property int $coverage_area_id
 * @property int $h3_index
 * @property int $h3_resolution
 * @property int|null $parent_h3_index
 * @property int $footprint_count
 * @property int $structures_captured
 * @property string $status
 * @property float $coverage_pct
 * @property-read CoverageArea|null $coverageArea
 */
final class GridCell extends Model
{
    public const STATUS_UNASSIGNED = 'unassigned';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'coverage_area_id', 'h3_index', 'h3_resolution', 'parent_h3_index',
        'footprint_count', 'structures_captured', 'status', 'coverage_pct',
    ];

    protected function casts(): array
    {
        return [
            'h3_index' => 'integer',
            'parent_h3_index' => 'integer',
            'h3_resolution' => 'integer',
            'footprint_count' => 'integer',
            'structures_captured' => 'integer',
            'coverage_pct' => 'float',
        ];
    }

    /** The H3 index as the canonical hex string officers and auditors see. */
    public function h3(): string
    {
        return dechex((int) $this->h3_index);
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /** @return HasMany<ExternalFootprint, $this> */
    public function footprints(): HasMany
    {
        return $this->hasMany(ExternalFootprint::class);
    }
}
