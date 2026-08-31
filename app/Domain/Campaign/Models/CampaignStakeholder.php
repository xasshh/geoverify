<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\EngagementStatus;
use App\Domain\Campaign\Enums\StakeholderCategory;
use Database\Factories\Domain\Campaign\Models\CampaignStakeholderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who has to be engaged before officers walk the ground.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $name
 * @property StakeholderCategory $category
 * @property string|null $organisation
 * @property string|null $role_title
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property EngagementStatus $engagement_status
 * @property string|null $notes
 * @property bool $visible_to_client
 */
final class CampaignStakeholder extends Model
{
    /** @use HasFactory<CampaignStakeholderFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'name', 'category', 'organisation', 'role_title',
        'contact_person', 'phone', 'email', 'engagement_status', 'notes',
        'visible_to_client',
    ];

    protected function casts(): array
    {
        return [
            'category' => StakeholderCategory::class,
            'engagement_status' => EngagementStatus::class,
            'visible_to_client' => 'boolean',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Excluded at query level, not filtered after loading.
     *
     * A contact we are keeping internal must never be in the result set a
     * client screen is built from. Filtering a loaded collection leaves the row
     * in memory and one careless prop away from the page.
     *
     * @param  Builder<CampaignStakeholder>  $query
     * @return Builder<CampaignStakeholder>
     */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where('visible_to_client', true);
    }
}
