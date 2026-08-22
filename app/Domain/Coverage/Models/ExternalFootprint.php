<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A detected building. A work list entry, not a record of a building. */
final class ExternalFootprint extends Model
{
    public const SOURCE_GOOGLE = 'google_open_buildings';

    public const SOURCE_MICROSOFT = 'microsoft_global_ml';

    protected $fillable = [
        'source', 'source_id', 'confidence', 'area_m2',
        'h3_index', 'grid_cell_id', 'matched_structure_id', 'dismissed',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'area_m2' => 'float',
            'h3_index' => 'integer',
            'dismissed' => 'boolean',
        ];
    }

    /** @return BelongsTo<GridCell, $this> */
    public function gridCell(): BelongsTo
    {
        return $this->belongsTo(GridCell::class);
    }
}
