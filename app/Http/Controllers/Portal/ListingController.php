<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A listing, as its own party sees it.
 *
 * There is no edit form here, and that is the design rather than an omission.
 * What an officer observed on a given morning is a record of that morning. A
 * party who could rewrite it would be able to turn a field observation into a
 * self-description while keeping the credibility the field visit gave it, which
 * is the single most valuable thing this platform sells.
 *
 * So the page says what was observed, says who observed it and when, and offers
 * a correction rather than a keystroke: a party states what is wrong and why, a
 * supervisor rules on it, and an accepted correction is appended beside the
 * officer's account rather than over it.
 */
final class ListingController
{
    public function __construct(
        private readonly ActingParty $acting,
        private readonly ResolveListingTier $tiers,
    ) {}

    public function show(Request $request, Enterprise $enterprise): Response
    {
        $membership = $this->membership($request);

        $control = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->first();

        // Control is checked against the live row, not against the claim that
        // produced it. A claim that was approved and later transferred by a
        // dispute must stop opening this page the moment it is transferred.
        if (! $control instanceof PartyBusiness) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }

        $enterprise->load(['structure.ward', 'structure.lga']);

        $observations = EnterpriseObservation::query()
            ->where('enterprise_id', $enterprise->id)
            ->orderByDesc('observed_at')
            ->get();

        return Inertia::render('portal/Listing', [
            'business' => [
                'id' => $enterprise->id,
                'tradingName' => $enterprise->trading_name,
                'sector' => $enterprise->sector_code,
                'structureType' => $enterprise->structure->structure_type,
                'ward' => $enterprise->structure->ward?->name,
                'lga' => $enterprise->structure->lga?->name,
                'enumeratedAt' => $enterprise->captured_at->toIso8601String(),
                'selfRegistered' => $enterprise->structure->isSelfRegistered(),
            ],
            // Built server side from the record rather than assembled in the
            // page, so the ladder can never say something the register does not.
            'rungs' => $this->tiers->rungs(
                (string) $enterprise->structure->origin,
                (string) $enterprise->structure->status,
                $enterprise->captured_at,
            ),
            'control' => [
                'relationship' => $control->relationship->noun(),
                'since' => $control->established_at->toIso8601String(),
                'via' => $control->established_via,
            ],
            // Read only, and shown as history rather than as fields. The phone
            // stays masked even here: nothing on this page needs it, and M5's
            // correction flow is where changing it will belong.
            'observations' => $observations->map(static fn (EnterpriseObservation $o): array => [
                'observedAt' => $o->observed_at->toIso8601String(),
                'tradingName' => $o->trading_name,
                'operatingStatus' => $o->operating_status,
                'signageObserved' => $o->signage_observed,
                'hasPhone' => $o->phone !== null,
            ])->all(),
            'party' => [
                'code' => $membership->party?->code,
                'displayName' => $membership->party?->display_name,
            ],

            // What this party has already asked to have changed, live and
            // settled alike. A decided correction stays on the page with the
            // reason it was decided: a business told no deserves to see why,
            // and one told yes deserves to see that it landed.
            'corrections' => CorrectionProposal::query()
                ->where('enterprise_id', $enterprise->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (CorrectionProposal $p): array => [
                    'id' => $p->id,
                    'field' => $p->field->value,
                    'fieldLabel' => $p->field->label(),
                    'currentValue' => $p->current_value,
                    'proposedValue' => $p->proposed_value,
                    'reason' => $p->reason,
                    'status' => $p->status->value,
                    'statusLabel' => $p->status->label(),
                    'proposedAt' => $p->created_at?->toIso8601String(),
                    'decidedAt' => $p->reviewed_at?->toIso8601String(),
                    'decisionNote' => $p->decision_note,
                ])->values()->all(),

            'correctableFields' => CorrectableField::options(),

            'publication' => [
                'state' => $enterprise->publication_state->value,
                'label' => $enterprise->publication_state->label(),
                'explanation' => $enterprise->publication_state->explanation(),
                'decidedAt' => $enterprise->publication_decided_at?->toIso8601String(),
            ],
        ]);
    }

    private function membership(Request $request): PartyUser
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Not signed in.');
        }

        $membership = $this->acting->forRequest($request, $account);

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('Choose which business you are acting for.');
        }

        return $membership;
    }
}
