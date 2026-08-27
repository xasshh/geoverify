<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The threshold table, in one place.
 *
 * Every path that could settle a claim goes through here, so the rule cannot
 * drift between the automatic route and the console one. Two combinations
 * approve on their own; everything else waits for a person.
 *
 * The rule is a table, not a score. A score would let three weak signals add up
 * to control of somebody's business, and the whole point of the settled
 * thresholds is that they cannot: proximity plus a plausible name plus a
 * confident tone is what a fraudulent claim looks like.
 */
final class DecideClaim
{
    public function __construct(
        private readonly GrantControl $control,
    ) {}

    /**
     * Look at what the claim now holds and settle it if the bar is met.
     *
     * Called after each signal resolves rather than once at submission,
     * because signals arrive at different times and the claimant should not
     * have to come back and press something to collect a decision that was
     * already earned.
     */
    public function reassess(Claim $claim): Claim
    {
        if ($claim->status->isSettled()) {
            return $claim;
        }

        // Contested listings are never settled automatically, however strong
        // the evidence. Two parties with good evidence is precisely the case a
        // person has to look at: an automatic rule here would simply hand the
        // listing to whoever submitted second.
        if ($claim->status === ClaimStatus::Disputed) {
            return $claim;
        }

        if ($claim->confirms(ClaimEvidence::PhoneMatch)) {
            return $this->settle($claim, Claim::DECISION_PHONE_MATCH);
        }

        if ($this->meetsCacDirectorBar($claim)) {
            return $this->settle($claim, Claim::DECISION_CAC_DIRECTOR);
        }

        return $claim;
    }

    /**
     * CAC verified, identity verified, and the claimant on the roster returned.
     *
     * All three, or none of it. A CAC number proves a company exists; an
     * identity check proves the claimant is who they say; only the roster
     * connects the two. Any two of the three describe a person who has read a
     * public register, which is not evidence of anything.
     */
    private function meetsCacDirectorBar(Claim $claim): bool
    {
        if (! $claim->relationship->checkableAgainstRoster()) {
            return false;
        }

        $entry = $claim->evidence[ClaimEvidence::CacDirector->value] ?? null;

        if (! is_array($entry) || ($entry['result'] ?? null) !== 'confirmed') {
            return false;
        }

        return ($entry['cac_verified'] ?? false) === true
            && ($entry['identity_verified'] ?? false) === true
            && ($entry['on_roster'] ?? false) === true;
    }

    /** A supervisor approving a claim the automatic rules would not. */
    public function approveByReview(Claim $claim, User $reviewer, string $note): Claim
    {
        if ($claim->status->isSettled()) {
            throw new RuntimeException('That claim is already settled.');
        }

        return $this->settle($claim, Claim::DECISION_REVIEWED, $reviewer, $note);
    }

    public function reject(Claim $claim, User $reviewer, string $note): Claim
    {
        if ($claim->status->isSettled()) {
            throw new RuntimeException('That claim is already settled.');
        }

        return DB::transaction(function () use ($claim, $reviewer, $note): Claim {
            $claim->forceFill([
                'status' => ClaimStatus::Rejected,
                'decision' => Claim::DECISION_REVIEWED,
                'decided_by' => $reviewer->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            VerificationEvent::record($claim->enterprise, 'claim.rejected', $reviewer, [
                'claim_id' => $claim->id,
                'party' => $claim->party->code,
                'note' => $note,
            ]);

            return $claim;
        });
    }

    private function settle(Claim $claim, string $decision, ?User $reviewer = null, ?string $note = null): Claim
    {
        $claim->forceFill([
            'status' => ClaimStatus::Approved,
            'decision' => $decision,
            'decided_by' => $reviewer?->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        // Approval and control are separate steps because the grant can still
        // lose a race. When it does, grant() turns this into a dispute and the
        // claim's approval stands as a thing that happened, which is what the
        // person resolving the dispute needs to see.
        $this->control->grant($claim);

        return $claim->refresh();
    }
}
