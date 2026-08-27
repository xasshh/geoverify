<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimDispute;

/**
 * What a supervisor has to look at, worst first.
 *
 * Disputes lead, ahead of ordinary claims and regardless of age. A disputed
 * listing has two parties who each believe it is theirs, and one of them is
 * being kept out of their own business every day it sits here. An ordinary
 * claim in the queue is somebody waiting; a dispute is somebody blocked.
 *
 * Within each group, oldest first. A queue that surfaces the interesting cases
 * first is a queue where the boring ones are never done.
 */
final class BuildClaimQueue
{
    /**
     * @return array{disputes: list<array<string, mixed>>, claims: list<array<string, mixed>>}
     */
    public function __invoke(): array
    {
        $disputes = ClaimDispute::query()
            ->with([
                'enterprise',
                'challengerClaim.party',
                'incumbent.party',
            ])
            ->whereNull('resolution')
            ->orderBy('opened_at')
            ->get()
            ->map(fn (ClaimDispute $dispute): array => [
                'id' => $dispute->id,
                'openedAt' => $dispute->opened_at->toIso8601String(),
                'tradingName' => $dispute->enterprise->trading_name,
                'challenger' => [
                    'code' => $dispute->challengerClaim->party->code,
                    'name' => $dispute->challengerClaim->party->display_name,
                    // The noun here, not the verb phrase: this line reads "says they
                    // are the owner", and "says they owns it" is what you get
                    // from reusing the third-person assertion in a clause that
                    // already has its own subject.
                    'relationship' => $dispute->challengerClaim->relationship->noun(),
                    'signals' => $this->signals($dispute->challengerClaim),
                ],
                'incumbent' => [
                    'code' => $dispute->incumbent->party->code,
                    'name' => $dispute->incumbent->party->display_name,
                    'heldSince' => $dispute->incumbent->established_at->toIso8601String(),
                    'via' => $dispute->incumbent->established_via,
                ],
            ])
            ->all();

        $claims = Claim::query()
            ->with(['enterprise.structure.ward', 'party'])
            ->where('status', ClaimStatus::Submitted)
            ->orderBy('asserted_at')
            ->get()
            ->map(fn (Claim $claim): array => [
                'id' => $claim->id,
                'assertedAt' => $claim->asserted_at->toIso8601String(),
                'tradingName' => $claim->enterprise->trading_name,
                'ward' => $claim->enterprise->structure->ward?->name,
                'party' => [
                    'code' => $claim->party->code,
                    'name' => $claim->party->display_name,
                ],
                'relationship' => $claim->relationship->assertion(),
                'signals' => $this->signals($claim),
            ])
            ->all();

        return ['disputes' => $disputes, 'claims' => $claims];
    }

    /**
     * The evidence as a readable list, with what each is worth said out loud.
     *
     * A reviewer looking at "proximity: 30 m" needs to know that 30 metres
     * decides nothing, and needs to know it on the screen rather than from
     * training. Supporting signals are labelled supporting.
     *
     * @return list<array<string, mixed>>
     */
    private function signals(Claim $claim): array
    {
        $signals = [];

        foreach (ClaimEvidence::cases() as $signal) {
            $entry = $claim->evidence[$signal->value] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $signals[] = [
                'kind' => $signal->value,
                'label' => $signal->label(),
                'result' => $entry['result'] ?? 'unknown',
                'supportingOnly' => $signal->isSupportingOnly(),
                'detail' => $signal === ClaimEvidence::Proximity && isset($entry['metres'])
                    ? $entry['metres'].' m from the recorded position'
                    : null,
            ];
        }

        return $signals;
    }
}
