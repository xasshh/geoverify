<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Models;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Coverage\Models\CoverageArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A piece of the land worth recording: a farm, a river, a water point.
 *
 * The identity only. What it looks like and what was said about it live in
 * its revisions, which are never edited; this row points at the current one.
 *
 * @property int $id
 * @property string $client_uuid
 * @property int $campaign_id
 * @property int $coverage_area_id
 * @property int $feature_class_id
 * @property int|null $current_revision_id
 * @property string $verification_status
 * @property string $status
 * @property int|null $confidence_score
 * @property Carbon $created_at
 * @property-read AreaFeatureRevision|null $currentRevision
 * @property-read FeatureClass $featureClass
 */
final class AreaFeature extends Model
{
    public const VERIFICATION_UNVERIFIED = 'unverified';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_REJECTED = 'rejected';

    public const VERIFICATION_NEEDS_REVISIT = 'needs_revisit';

    public const STATUS_LIVE = 'live';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'client_uuid', 'campaign_id', 'coverage_area_id', 'feature_class_id',
        'current_revision_id', 'verification_status', 'status', 'confidence_score',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /** @return BelongsTo<FeatureClass, $this> */
    public function featureClass(): BelongsTo
    {
        return $this->belongsTo(FeatureClass::class);
    }

    /** @return BelongsTo<AreaFeatureRevision, $this> */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(AreaFeatureRevision::class, 'current_revision_id');
    }

    /** @return HasMany<AreaFeatureRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AreaFeatureRevision::class)->orderBy('captured_at');
    }
}
