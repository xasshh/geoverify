<?php

declare(strict_types=1);

namespace App\Domain\Imagery\Models;

use App\Domain\Coverage\Models\CoverageArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satellite or aerial imagery of a mandate, built into a raster PMTiles archive.
 *
 * Sits beside MapPack rather than inside it. The vector pack is the street map
 * every officer already carries; this is a second, optional view of the same
 * ground for the land between and beyond the buildings.
 *
 * @property int $id
 * @property int $coverage_area_id
 * @property string $name
 * @property string $source
 * @property string $status
 * @property int $progress
 * @property string|null $stage
 * @property string|null $error
 * @property list<array{id: string, date: string, cloud: float}>|null $scenes
 * @property Carbon|null $captured_from
 * @property Carbon|null $captured_to
 * @property string|null $cloud_pct
 * @property int|null $resolution_cm
 * @property string|null $licence_note
 * @property string|null $path
 * @property int|null $bytes
 * @property string|null $checksum
 * @property int|null $min_zoom
 * @property int|null $max_zoom
 * @property string|null $west
 * @property string|null $south
 * @property string|null $east
 * @property string|null $north
 * @property int|null $requested_by
 * @property Carbon|null $built_at
 * @property Carbon|null $superseded_at
 * @property Carbon $created_at
 * @property-read CoverageArea $coverageArea
 */
final class BasemapLayer extends Model
{
    public const SOURCE_SENTINEL2 = 'sentinel2';

    public const SOURCE_UPLOAD = 'upload';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'coverage_area_id', 'name', 'source', 'status', 'progress', 'stage', 'error',
        'scenes', 'captured_from', 'captured_to', 'cloud_pct', 'resolution_cm', 'licence_note',
        'path', 'bytes', 'checksum', 'min_zoom', 'max_zoom', 'west', 'south', 'east', 'north',
        'requested_by', 'built_at', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'scenes' => 'array',
            'captured_from' => 'date',
            'captured_to' => 'date',
            'resolution_cm' => 'integer',
            'bytes' => 'integer',
            'min_zoom' => 'integer',
            'max_zoom' => 'integer',
            'built_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The imagery an officer should be holding: built, and not replaced.
     *
     * @param  Builder<BasemapLayer>  $query
     * @return Builder<BasemapLayer>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY)->whereNull('superseded_at');
    }

    public function isWorking(): bool
    {
        return $this->status === self::STATUS_QUEUED || $this->status === self::STATUS_PROCESSING;
    }

    public function megabytes(): float
    {
        return round(($this->bytes ?? 0) / 1_048_576, 1);
    }

    /** "19 Nov 2025", or a range when the mosaic spans several passes. */
    public function capturedLabel(): ?string
    {
        if ($this->captured_from === null) {
            return null;
        }

        $from = $this->captured_from->format('j M Y');
        $to = $this->captured_to?->format('j M Y');

        return $to === null || $to === $from ? $from : "{$from} to {$to}";
    }
}
