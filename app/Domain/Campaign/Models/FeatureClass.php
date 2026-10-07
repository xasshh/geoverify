<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\GeometryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A kind of thing an officer can draw: forest, a river, a water point.
 *
 * With no campaign it is a template, kept by the super admin. A campaign works
 * from its own copy, so a client's wording never reaches the next client's
 * catalogue. The attribute form lives in versions, never on this row: what a
 * feature was asked is fixed when it is captured.
 *
 * @property int $id
 * @property int|null $campaign_id
 * @property string $key
 * @property string $label
 * @property GeometryType $geometry_type
 * @property array{fill?: string, stroke?: string, icon?: string}|null $style
 * @property string|null $exclusivity_group
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $copied_from_id
 * @property-read FeatureClassVersion|null $latestVersion
 */
final class FeatureClass extends Model
{
    protected $fillable = [
        'campaign_id', 'key', 'label', 'geometry_type', 'style', 'exclusivity_group',
        'description', 'is_active', 'sort_order', 'copied_from_id',
    ];

    protected function casts(): array
    {
        return [
            'geometry_type' => GeometryType::class,
            'style' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<FeatureClassVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FeatureClassVersion::class)->orderBy('version');
    }

    /** @return HasOne<FeatureClassVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(FeatureClassVersion::class)->ofMany('version', 'max');
    }

    /** @return BelongsTo<FeatureClass, $this> */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    /**
     * @param  Builder<FeatureClass>  $query
     * @return Builder<FeatureClass>
     */
    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('campaign_id');
    }

    public function isTemplate(): bool
    {
        return $this->campaign_id === null;
    }
}
