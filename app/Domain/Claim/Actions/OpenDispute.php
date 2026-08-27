<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\ClaimDispute;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Verification\Models\VerificationEvent;

final class OpenDispute
{
    /**
     * Put a contested claim in front of a person, and say so to both sides.
     *
     * The incumbent keeps control while the dispute runs. Suspending the
     * listing on an accusation would make a denial-of-service out of the claim
     * form: anyone could park a competitor's listing by challenging it. The
     * cost of being wrong has to fall on the challenger's time, not on the
     * incumbent's ability to trade.
     */
    public function open(Claim $challenger, PartyBusiness $incumbent, ?string $grounds = null): ClaimDispute
    {
        $challenger->status = ClaimStatus::Disputed;
        $challenger->save();

        $dispute = ClaimDispute::query()->create([
            'enterprise_id' => $challenger->enterprise_id,
            'incumbent_party_business_id' => $incumbent->id,
            'challenger_claim_id' => $challenger->id,
            'opened_at' => now(),
            'grounds' => $grounds,
        ]);

        VerificationEvent::recordForParty(
            $challenger->enterprise,
            'claim.disputed',
            $challenger->party,
            [
                'dispute_id' => $dispute->id,
                'claim_id' => $challenger->id,
                // The incumbent party's code, not its name. An audit row has to
                // stay meaningful after a display name changes.
                'incumbent' => $incumbent->party->code,
            ],
        );

        return $dispute;
    }
}
