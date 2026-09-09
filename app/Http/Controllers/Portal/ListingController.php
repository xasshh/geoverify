<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Identity\Actions\ResolveConsentReceipt;
use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Media\Actions\PublishStorefrontPhoto;
use App\Domain\Media\Models\Media;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;
use App\Domain\Verification\Actions\ResolveServiceZone;
use App\Domain\Verification\Actions\ResolveVerificationPrice;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
        private readonly ResolveServiceZone $zones,
        private readonly ResolveVerificationPrice $prices,
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

            // The next rung, and what it costs, or nothing at all. A listing
            // with an order already in flight is not offered the same thing
            // again: the sell is over and what the party wants now is to know
            // where their visit has got to.
            'nextRung' => $this->nextRung($enterprise),

            'orders' => VerificationOrder::query()
                ->where('enterprise_id', $enterprise->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(static fn (VerificationOrder $o): array => [
                    'id' => $o->id,
                    'reference' => $o->reference,
                    'tier' => str_replace('_', ' ', $o->tier),
                    'status' => $o->status->value,
                    'statusLabel' => $o->status->label(),
                    'dueBy' => $o->due_by?->toDateString(),
                    'feeNaira' => (int) round($o->amount_minor / 100),
                ])->values()->all(),

            // The photographs this business shows of itself. Officer evidence
            // is in the same table and is not asked for here: the query names
            // storefront photographs with a party author, which is the same
            // discipline the directory uses to publish them.
            'photos' => Media::query()
                ->where('mediable_type', $enterprise->getMorphClass())
                ->where('mediable_id', $enterprise->id)
                ->where('kind', Media::KIND_STOREFRONT)
                ->whereNotNull('uploaded_by_party_id')
                ->where('status', Media::STATUS_STORED)
                ->orderBy('id')
                ->get()
                ->map(static fn (Media $media): array => [
                    'id' => $media->id,
                    'url' => $media->temporaryUrl(30),
                ])->values()->all(),

            'photoLimit' => PublishStorefrontPhoto::MAX_PER_BUSINESS,

            'publication' => [
                'state' => $enterprise->publication_state->value,
                'label' => $enterprise->publication_state->label(),
                'explanation' => $enterprise->publication_state->explanation(),
                'decidedAt' => $enterprise->publication_decided_at?->toIso8601String(),

                // The consent history, and the tokens that open it. The token
                // is the whole authorisation on those pages, which is exactly
                // why it belongs here and nowhere else: this screen is already
                // behind the check that the party controls this business, and
                // handing somebody their own receipt is the point of keeping
                // one. Both directions are listed, because "they agreed in
                // March and withdrew in September" is two facts and a page
                // showing only the second has hidden half the history from the
                // person it belongs to.
                'receipts' => ConsentReceipt::query()
                    ->where('subject_type', $enterprise->getMorphClass())
                    ->where('subject_id', $enterprise->id)
                    ->orderByDesc('agreed_at')
                    ->get()
                    ->map(static fn (ConsentReceipt $r): array => [
                        'token' => $r->token,
                        'reference' => ResolveConsentReceipt::reference($r),
                        'granted' => $r->granted,
                        'agreedOn' => $r->agreed_at->toDateString(),
                        'withdrawnOn' => $r->withdrawn_at?->toDateString(),
                    ])->values()->all(),
            ],
        ]);
    }

    /**
     * What this listing could establish next, and what that costs today.
     *
     * Priced here rather than on the money screen so the offer on the listing
     * is the offer on the next page. A figure that changes when you click it is
     * the fastest way to lose somebody who was about to pay.
     *
     * Null when something is already in flight for that tier, which the unique
     * index would refuse anyway. Better to not offer it than to offer it and
     * then explain.
     *
     * @return array<string, mixed>|null
     */
    private function nextRung(Enterprise $enterprise): ?array
    {
        $established = $this->tiers->forOrigin(
            (string) $enterprise->structure->origin,
            (string) $enterprise->structure->status,
        );

        $next = $established === 'location_verified'
            ? 'operations_verified'
            : 'location_verified';

        $live = VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('tier', $next)
            ->get()
            ->contains(static fn (VerificationOrder $o): bool => ! $o->status->isSettled());

        if ($live) {
            return null;
        }

        try {
            $price = ($this->prices)(
                $next,
                OrderUrgency::Standard,
                ($this->zones)($enterprise->structure),
            );
        } catch (RuntimeException) {
            // No live price for that rung means we are not selling it today.
            // Saying nothing is the honest surface for that.
            return null;
        }

        return [
            'tier' => $next,
            'label' => ucfirst(str_replace('_', ' ', $next)),
            'feeNaira' => (int) round($price->amount_minor / 100),
            'within' => "{$price->sla_working_days} working days",
        ];
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
