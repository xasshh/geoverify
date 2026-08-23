<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A detected building. A work list entry, not a record of a building. */
/**
 * @property int $id
 * @property string $source
 * @property string|null $source_id
 * @property float|null $confidence
 * @property float|null $area_m2
 * @property int|null $h3_index
 * @property int|null $grid_cell_id
 * @property int|null $matched_structure_id
 * @property bool $dismissed
 */
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
