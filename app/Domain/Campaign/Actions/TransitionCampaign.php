<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving a campaign from one state to the next.
 *
 * Named acts with guards, not a status field somebody edits. Activating is what
 * puts officers on the road and starts a clock a client is holding us to, so it
 * is not a thing to reach by picking a different option in a dropdown.
 *
 * Every move appends to verification_events, because a client asking why an
 * exercise paused for three weeks in October is asking a question the log
 * should already answer.
 */
final class TransitionCampaign
{
    public function __invoke(
        Campaign $campaign,
        CampaignStatus $to,
        User $actor,
        ?string $note = null,
        bool $overrideStartDate = false,
    ): Campaign {
        if (! $actor->administers()) {
            throw new RuntimeException('Only an administrator can move a campaign.');
        }

        $from = $campaign->status;

        if (! $from->allowsMoveTo($to)) {
            throw new RuntimeException(sprintf(
                'A campaign that is %s cannot become %s.',
                mb_strtolower($from->label()),
                mb_strtolower($to->label()),
            ));
        }

        if ($to === CampaignStatus::Active) {
            $this->guardActivation($campaign, $overrideStartDate);
        }

        return DB::transaction(function () use ($campaign, $from, $to, $actor, $note): Campaign {
            $campaign->status = $to;

            // Stamped once, on the move that earned it, and never rewritten by
            // a later pause and resume.
            if ($to === CampaignStatus::Approved && $campaign->approved_at === null) {
                $campaign->approved_by = $actor->id;
                $campaign->approved_at = Carbon::now(config('app.timezone'));
            }

            $campaign->save();

            VerificationEvent::record($campaign, 'campaign.'.$to->value, $actor, array_filter([
                'from' => $from->value,
                'to' => $to->value,
                'note' => $note,
            ], static fn (mixed $value): bool => $value !== null));

            return $campaign->refresh();
        });
    }

    /**
     * Two things have to be true before officers go out.
     *
     * The approval, which the state machine already enforces, and the start
     * date. Activating ahead of the contracted start is sometimes right, and it
     * is never accidental, so it takes a deliberate override rather than being
     * silently allowed or flatly refused.
     */
    private function guardActivation(Campaign $campaign, bool $override): void
    {
        if ($campaign->approved_at === null) {
            throw new RuntimeException('A campaign has to be approved before it can be activated.');
        }

        $startsOn = $campaign->starts_on;

        if ($startsOn === null || $override) {
            return;
        }

        if ($startsOn->startOfDay()->isAfter(Carbon::now(config('app.timezone'))->startOfDay())) {
            throw new RuntimeException(sprintf(
                'This campaign is not due to start until %s. Confirm the override to activate it early.',
                $startsOn->format('j M Y'),
            ));
        }
    }
}
