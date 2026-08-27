<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimEvidence;
use App\Domain\Claim\Enums\ClaimRelationship;
use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Somebody says a listing is theirs.
 *
 * Submitting settles nothing on its own. It opens a claim, records what was
 * asserted and when, and attaches whatever supporting signals are already known
 * at that moment. The decision comes later, from DecideClaim, once the signals
 * that can be checked have been.
 */
final class SubmitClaim
{
    public function __construct(
        private readonly OpenDispute $disputes,
    ) {}

    public function run(
        Party $party,
        PortalAccount $submitter,
        Enterprise $enterprise,
        ClaimRelationship $relationship,
        ?float $lat = null,
        ?float $lng = null,
    ): Claim {
        $enterprise->loadMissing('structure');

        // A capture a supervisor threw out is not a listing. Letting it be
        // claimed would quietly restore a record the review process rejected.
        if ($enterprise->structure->status === 'rejected') {
            throw new RuntimeException('That record was not accepted into the register.');
        }

        return DB::transaction(function () use ($party, $submitter, $enterprise, $relationship, $lat, $lng): Claim {
            $incumbent = PartyBusiness::query()
                ->where('enterprise_id', $enterprise->id)
                ->where('status', PartyBusiness::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($incumbent?->party_id === $party->id) {
                throw new RuntimeException('You already manage this business.');
            }

            $claim = new Claim([
                'party_id' => $party->id,
                'enterprise_id' => $enterprise->id,
                'submitted_by' => $submitter->id,
                'relationship' => $relationship,
                'asserted_at' => now(),
                'evidence' => [],
                'status' => ClaimStatus::Submitted,
            ]);

            // Proximity is recorded because it is genuinely useful context for
            // whoever reviews this, and because a claimant standing in the shop
            // should not have to type that fact into a notes field. It is
            // recorded as measured metres, and it never decides anything.
            if ($lat !== null && $lng !== null) {
                $metres = $this->metresFromStructure($enterprise, $lat, $lng);

                $claim->recordEvidence(
                    ClaimEvidence::Proximity,
                    $metres !== null && $metres <= 250 ? 'confirmed' : 'weak',
                    ['metres' => $metres],
                );
            }

            $claim->save();

            VerificationEvent::recordForParty($enterprise, 'claim.submitted', $party, [
                'claim_id' => $claim->id,
                'relationship' => $relationship->value,
                'contested' => $incumbent !== null,
            ]);

            // Already claimed by somebody else. This is not a rejection and not
            // an error: it is the case where two people both believe the same
            // shop is theirs, and exactly one of them is probably right. The
            // claim stands and goes to a dispute with a route out.
            if ($incumbent !== null) {
                $this->disputes->open($claim, $incumbent);
            }

            return $claim;
        });
    }

    /**
     * Distance in PostGIS, because distance is always in PostGIS.
     *
     * Null when the claimant's browser gave us nothing usable, which is
     * ordinary: location permission is declined more often than it is granted.
     */
    private function metresFromStructure(Enterprise $enterprise, float $lat, float $lng): ?int
    {
        $metres = DB::scalar(<<<'SQL'
            SELECT round(ST_Distance(
                s.centroid::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            ))
            FROM structures s
            WHERE s.id = ?
        SQL, [$lng, $lat, $enterprise->structure_id]);

        return $metres === null ? null : (int) $metres;
    }
}
