<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loaded reference geography. The authority on which ward, LGA and state a
 * captured point falls in.
 *
 * The boundary column is deliberately absent from $fillable and from ordinary
 * selects: geometry is written and read through PostGIS expressions, never marshalled
 * through PHP.
 */
final class AdminBoundary extends Model
{
    public const LEVEL_STATE = 'state';

    public const LEVEL_LGA = 'lga';

    public const LEVEL_WARD = 'ward';

    protected $fillable = [
        'level', 'code', 'name', 'alt_names', 'parent_id',
        'source', 'source_ref', 'source_vintage',
    ];

    protected function casts(): array
    {
        return [
            'alt_names' => 'array',
            'source_vintage' => 'date',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
