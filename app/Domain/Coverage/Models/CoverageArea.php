<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A mandate's geography. Everything downstream is bounded by one of these. */
final class CoverageArea extends Model
{
    protected $fillable = [
        'client_name', 'contract_ref', 'name', 'state_code', 'lga_code',
        'admin_boundary_id', 'status', 'starts_on', 'ends_on',
        'accuracy_threshold_m', 'default_h3_resolution',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'accuracy_threshold_m' => 'integer',
            'default_h3_resolution' => 'integer',
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
}
