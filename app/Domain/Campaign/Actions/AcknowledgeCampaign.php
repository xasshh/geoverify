<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignAcknowledgement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Has this person read the brief, and recording that they have.
 *
 * The modal shows once. Not once per session and not once per device: a client
 * administrator who has read what was commissioned should not be shown it again
 * every Monday, and an officer who has read their instructions should not have
 * to dismiss them at the start of every shift.
 *
 * A revised brief is a brief nobody has read. That is decided by comparing the
 * acknowledgement against the campaign's `definition_revised_at` rather than by
 * deleting rows, so the record of who read which version survives the revision.
 */
final class AcknowledgeCampaign
{
    /** Whether the brief still needs to be put in front of this person. */
    public function outstandingFor(Campaign $campaign, ?Model $person): bool
    {
        if ($person === null) {
            return false;
        }

        $acknowledgement = $this->existing($campaign, $person);

        return $acknowledgement === null || ! $acknowledgement->stillStands($campaign);
    }

    public function record(Campaign $campaign, Model $person): CampaignAcknowledgement
    {
        return CampaignAcknowledgement::query()->updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'acknowledged_by_type' => $person->getMorphClass(),
                'acknowledged_by_id' => $person->getKey(),
            ],
            ['acknowledged_at' => Carbon::now(config('app.timezone'))],
        );
    }

    /**
     * Marks the definition as materially revised.
     *
     * Called when the scope, schema, dates or brief change in a way people need
     * to see again. Not on every save: re-showing a modal because somebody fixed
     * a typo teaches everybody to dismiss it without reading, which costs more
     * than the typo did.
     */
    public function requireReacknowledgement(Campaign $campaign): void
    {
        $campaign->forceFill([
            'definition_revised_at' => Carbon::now(config('app.timezone')),
        ])->save();
    }

    private function existing(Campaign $campaign, Model $person): ?CampaignAcknowledgement
    {
        return CampaignAcknowledgement::query()
            ->where('campaign_id', $campaign->id)
            ->where('acknowledged_by_type', $person->getMorphClass())
            ->where('acknowledged_by_id', $person->getKey())
            ->first();
    }
}
