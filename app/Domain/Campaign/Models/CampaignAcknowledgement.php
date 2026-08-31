<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Somebody has read this campaign's brief.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $acknowledged_by_type
 * @property int $acknowledged_by_id
 * @property Carbon $acknowledged_at
 */
final class CampaignAcknowledgement extends Model
{
    protected $table = 'campaign_user_acknowledgements';

    protected $fillable = [
        'campaign_id', 'acknowledged_by_type', 'acknowledged_by_id', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return MorphTo<Model, $this> */
    public function acknowledgedBy(): MorphTo
    {
        return $this->morphTo('acknowledged_by');
    }

    /**
     * Whether this acknowledgement still stands.
     *
     * A brief revised after somebody read it is a brief they have not read. The
     * row is kept either way, so "did they ever see the first version" stays
     * answerable, which is the question that matters if a campaign is disputed.
     */
    public function stillStands(Campaign $campaign): bool
    {
        if ($campaign->definition_revised_at === null) {
            return true;
        }

        // Strictly after, not at or after. These columns hold whole seconds, so
        // a revision made in the same second as an acknowledgement compares as
        // equal, and the two possible mistakes are not equal in cost: showing a
        // brief once more than necessary is a small annoyance, and quietly not
        // showing a revised one is somebody working to instructions that
        // changed.
        return $this->acknowledged_at->greaterThan($campaign->definition_revised_at);
    }
}
