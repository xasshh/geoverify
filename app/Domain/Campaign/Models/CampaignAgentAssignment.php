<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\DeploymentStatus;
use App\Domain\Coverage\Models\CoverageArea;
use App\Models\User;
use Database\Factories\Domain\Campaign\Models\CampaignAgentAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One officer, deployed to one campaign.
 *
 * A level above `assignments`, which hands out individual H3 cells and which the
 * field client, the map packs and the review queue are all built on. This
 * answers "who is on this exercise", which is the question a client asks and the
 * one a supervisor answers before any cell changes hands.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $user_id
 * @property int|null $coverage_area_id
 * @property Carbon $assigned_at
 * @property Carbon|null $unassigned_at
 * @property DeploymentStatus $status
 * @property-read User|null $officer
 */
final class CampaignAgentAssignment extends Model
{
    /** @use HasFactory<CampaignAgentAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'user_id', 'coverage_area_id', 'assigned_at',
        'unassigned_at', 'status', 'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<CoverageArea, $this> */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /**
     * Currently out, as opposed to ever having been.
     *
     * @param  Builder<CampaignAgentAssignment>  $query
     * @return Builder<CampaignAgentAssignment>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('unassigned_at')
            ->where('status', DeploymentStatus::Active->value);
    }
}
