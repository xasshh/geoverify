<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Models;

use App\Domain\Coverage\Models\CoverageArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One arrival of features in bulk: a client's file, or the land cover seed.
 *
 * @property int $id
 * @property int $coverage_area_id
 * @property string $kind
 * @property string $status
 * @property string|null $source_name
 * @property string|null $source_path
 * @property array<string, mixed>|null $mapping
 * @property int $created_count
 * @property int $refused_count
 * @property list<array{index: int, message: string}>|null $refusals
 * @property string|null $error
 * @property int|null $requested_by
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
final class AreaFeatureBatch extends Model
{
    public const KIND_IMPORT = 'import';

    public const KIND_LANDCOVER = 'landcover';

    protected $fillable = [
        'coverage_area_id', 'kind', 'status', 'source_name', 'source_path', 'mapping',
        'created_count', 'refused_count', 'refusals', 'error', 'requested_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'refusals' => 'array',
            'created_count' => 'integer',
            'refused_count' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }
}
