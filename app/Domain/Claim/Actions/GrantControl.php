<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class GrantControl
{
    public function __construct(
        private readonly OpenDispute $disputes,
    ) {}

    /**
     * Hand a party the keys to a listing.
     *
     * Returns null when it could not, which happens when another party took
     * control between this claim being approved and this line running. That is
     * a real race rather than a theoretical one: two people claiming the same
     * shop on the same afternoon is the normal case for a market row, and both
     * of them may have valid-looking evidence.
     *
     * The unique index is what actually decides, and the loser becomes a
     * dispute rather than an exception. Checking first and then inserting would
     * read correctly and still produce two controllers under load.
     */
    public function grant(Claim $claim, string $via = PartyBusiness::VIA_CLAIM): ?PartyBusiness
    {
        try {
            return DB::transaction(function () use ($claim, $via): PartyBusiness {
                $control = PartyBusiness::query()->create([
                    'party_id' => $claim->party_id,
                    'enterprise_id' => $claim->enterprise_id,
                    'relationship' => $claim->relationship,
                    'established_via' => $via,
                    'established_at' => now(),
                    'claim_id' => $claim->id,
                    'status' => PartyBusiness::STATUS_ACTIVE,
                ]);

                VerificationEvent::recordForParty(
                    $claim->enterprise,
                    'claim.control_granted',
                    $claim->party,
                    ['claim_id' => $claim->id, 'via' => $via, 'decision' => $claim->decision],
                );

                return $control;
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $incumbent = PartyBusiness::query()
                ->where('enterprise_id', $claim->enterprise_id)
                ->where('status', PartyBusiness::STATUS_ACTIVE)
                ->firstOrFail();

            $this->disputes->open($claim, $incumbent);

            return null;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}
