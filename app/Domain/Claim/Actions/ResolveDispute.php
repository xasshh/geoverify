<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimDispute;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResolveDispute
{
    /**
     * @param  ClaimDispute::UPHELD_INCUMBENT|ClaimDispute::TRANSFERRED|ClaimDispute::WITHDRAWN  $resolution
     */
    public function __invoke(ClaimDispute $dispute, User $reviewer, string $resolution, string $note): ClaimDispute
    {
        if (! $dispute->isOpen()) {
            throw new RuntimeException('That dispute is already resolved.');
        }

        return DB::transaction(function () use ($dispute, $reviewer, $resolution, $note): ClaimDispute {
            $challenger = $dispute->challengerClaim;

            match ($resolution) {
                ClaimDispute::UPHELD_INCUMBENT => $this->uphold($challenger, $reviewer, $note),
                ClaimDispute::TRANSFERRED => $this->transfer($dispute, $challenger, $reviewer, $note),
                ClaimDispute::WITHDRAWN => $this->withdraw($challenger),
                default => throw new RuntimeException('Unknown resolution.'),
            };

            $dispute->forceFill([
                'resolution' => $resolution,
                'resolved_by' => $reviewer->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            VerificationEvent::record($dispute->enterprise, 'claim.dispute_resolved', $reviewer, [
                'dispute_id' => $dispute->id,
                'resolution' => $resolution,
                'note' => $note,
            ]);

            return $dispute;
        });
    }

    private function uphold(Claim $challenger, User $reviewer, string $note): void
    {
        $challenger->forceFill([
            'status' => ClaimStatus::Rejected,
            'decision' => Claim::DECISION_DISPUTE,
            'decided_by' => $reviewer->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();
    }

    /**
     * Hand the listing over.
     *
     * The revoke has to land before the grant, in the same transaction, because
     * the partial unique index permits exactly one active controller and would
     * otherwise reject the new row. That ordering is the point rather than an
     * inconvenience: there is no instant where a listing has two controllers,
     * and none where a supervisor's transfer half happened.
     *
     * The old control record is revoked, never deleted. Whoever held this
     * listing, and for how long, and why they stopped, is part of what the
     * register knows.
     */
    private function transfer(ClaimDispute $dispute, Claim $challenger, User $reviewer, string $note): void
    {
        $incumbent = $dispute->incumbent;

        $incumbent->forceFill([
            'status' => PartyBusiness::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => 'Transferred by dispute resolution.',
        ])->save();

        VerificationEvent::record($dispute->enterprise, 'claim.control_revoked', $reviewer, [
            'party' => $incumbent->party->code,
            'dispute_id' => $dispute->id,
        ]);

        $challenger->forceFill([
            'status' => ClaimStatus::Approved,
            'decision' => Claim::DECISION_DISPUTE,
            'decided_by' => $reviewer->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        PartyBusiness::query()->create([
            'party_id' => $challenger->party_id,
            'enterprise_id' => $challenger->enterprise_id,
            'relationship' => $challenger->relationship,
            'established_via' => PartyBusiness::VIA_CLAIM,
            'established_at' => now(),
            'claim_id' => $challenger->id,
            'status' => PartyBusiness::STATUS_ACTIVE,
        ]);

        VerificationEvent::recordForParty($dispute->enterprise, 'claim.control_granted', $challenger->party, [
            'claim_id' => $challenger->id,
            'via' => PartyBusiness::VIA_CLAIM,
            'decision' => Claim::DECISION_DISPUTE,
        ]);
    }

    private function withdraw(Claim $challenger): void
    {
        $challenger->forceFill([
            'status' => ClaimStatus::Withdrawn,
            'decided_at' => now(),
        ])->save();
    }
}
