<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use App\Domain\Campaign\Models\Campaign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A mandate's geography. Everything downstream is bounded by one of these. */
/**
 * @property int $id
 * @property string $client_name
 * @property string|null $contract_ref
 * @property string $name
 * @property string|null $state_code
 * @property string|null $lga_code
 * @property int|null $admin_boundary_id
 * @property int|null $campaign_id
 * @property int|null $target_record_count
 * @property string $status
 * @property int $accuracy_threshold_m
 * @property int $default_h3_resolution
 * @property int $grid_cells_count
 */
final class CoverageArea extends Model
{
    protected $fillable = [
        'client_name', 'contract_ref', 'name', 'state_code', 'lga_code',
        'admin_boundary_id', 'status', 'starts_on', 'ends_on',
        'accuracy_threshold_m', 'default_h3_resolution',
        'campaign_id', 'target_record_count', 'boundary_source', 'boundary_file',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'accuracy_threshold_m' => 'integer',
            'default_h3_resolution' => 'integer',
            'target_record_count' => 'integer',
        ];
    }

    /** @return BelongsTo<AdminBoundary, $this> */
    public function adminBoundary(): BelongsTo
    {
        return $this->belongsTo(AdminBoundary::class);
    }

    /** @return HasMany<GridCell, $this> */
    public function gridCells(): HasMany
    {
        return $this->hasMany(GridCell::class);
    }

    /**
     * The exercise this ground was contracted under.
     *
     * Nullable, and stays so. Mandates predate campaigns, the field platform
     * has never needed one, and a null here means exactly what it says rather
     * than standing in for a campaign nobody created.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
