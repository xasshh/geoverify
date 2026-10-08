<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An officer sent to ground-truth a feature drawn at the desk or imported.
 *
 * @property int $id
 * @property int $area_feature_id
 * @property int|null $assigned_to
 * @property int|null $assigned_by
 * @property string $status
 * @property string|null $outcome
 * @property int|null $resolved_revision_id
 * @property string|null $notes
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read AreaFeature $feature
 */
final class AreaVerificationTask extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const OUTCOMES = ['verified', 'reclassified', 'rejected', 'needs_revisit'];

    protected $fillable = [
        'area_feature_id', 'assigned_to', 'assigned_by', 'status', 'outcome',
        'resolved_revision_id', 'notes', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<AreaFeature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(AreaFeature::class, 'area_feature_id');
    }

    /** @return BelongsTo<User, $this> */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @param  Builder<AreaVerificationTask>  $query
     * @return Builder<AreaVerificationTask>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
