<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A built offline map pack.
 *
 * @property int $id
 * @property int $coverage_area_id
 * @property string $path
 * @property int $bytes
 * @property string $checksum
 * @property int $min_zoom
 * @property int $max_zoom
 * @property array<string, int> $layer_counts
 * @property string $west
 * @property string $south
 * @property string $east
 * @property string $north
 * @property int|null $built_by
 * @property Carbon $built_at
 * @property Carbon|null $superseded_at
 */
final class MapPack extends Model
{
    protected $fillable = [
        'coverage_area_id', 'path', 'bytes', 'checksum', 'min_zoom', 'max_zoom',
        'layer_counts', 'west', 'south', 'east', 'north', 'built_by', 'built_at',
        // Fillable on purpose: a pack is retired by writing this, and leaving it
        // off means the retirement is silently discarded and two packs read as
        // current.
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'min_zoom' => 'integer',
            'max_zoom' => 'integer',
            'layer_counts' => 'array',
            'built_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /**
     * The pack a handset should be holding.
     *
     * @param  Builder<MapPack>  $query
     * @return Builder<MapPack>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->latest('built_at');
    }

    /** Rounded the way it is spoken about, so the officer sees one number. */
    public function megabytes(): float
    {
        return round($this->bytes / 1_048_576, 1);
    }

    /**
     * Seconds to fetch this on a connection an officer might actually have.
     *
     * 2 Mbps is a working assumption for a hotel or office wifi in Abuja, not a
     * promise. It exists so the size is a decision rather than a surprise.
     */
    public function secondsAt2Mbps(): int
    {
        return (int) ceil($this->bytes * 8 / 2_000_000);
    }
}
