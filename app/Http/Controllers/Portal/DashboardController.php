<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Actions\ReadPartyActivity;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Actions\ResolveNextRung;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a party sees when they arrive.
 *
 * At M2 there are no listings yet, so this is the empty case, and the empty
 * case is designed rather than left over: it states the party's code, says
 * plainly that nothing is claimed, and offers the two things that can be done
 * about it. Nothing apologises and nothing is dressed up as progress.
 */
final class DashboardController
{
    public function __construct(
        private readonly ResolveListingTier $tiers,
        private readonly ResolveNextRung $nextRung,
        private readonly ReadPartyActivity $activity,
    ) {}

    public function __invoke(Request $request, NormalisePhone $phones): Response
    {
        /** @var PortalAccount $account */
        $account = Auth::guard('portal')->user();

        $memberships = PartyUser::query()
            ->with('party')
            ->where('portal_account_id', $account->id)
            ->whereNull('revoked_at')
            ->whereNotNull('accepted_at')
            ->get();

        return Inertia::render('portal/Dashboard', [
            'account' => [
                'name' => $account->name,
                'phone' => $phones->forDisplay($account->phone),
            ],
            'listings' => $this->listingsFor($memberships->pluck('party_id')->all()),
            'openClaims' => $this->openClaimsFor($memberships->pluck('party_id')->all()),
            'parties' => $memberships->map(static fn (PartyUser $membership): array => [
                'id' => $membership->party_id,
                'code' => $membership->party?->code,
                'displayName' => $membership->party?->display_name,
                'kind' => $membership->party?->kind->label(),
                'role' => $membership->role->label(),
                'identityTier' => $membership->party?->identity_tier,
                'listings' => PartyBusiness::query()
                    ->where('party_id', $membership->party_id)
                    ->where('status', PartyBusiness::STATUS_ACTIVE)
                    ->count(),
            ])->all(),

            // Businesses that have added this person and are waiting for a yes.
            'invitations' => PartyUser::query()
                ->with(['party:id,display_name,code'])
                ->where('portal_account_id', $account->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->get()
                ->map(static fn (PartyUser $m): array => [
                    'id' => $m->id,
                    'business' => $m->party?->display_name,
                    'role' => $m->role->label(),
                ])
                ->all(),

            // The business the dashboard is actually about. A party with four
            // shops still opens on one of them: a page that summarises
            // everything equally is a page that answers nothing, and the list
            // above is how they reach the others.
            'focus' => $this->focusFor($memberships->pluck('party_id')->all()),
        ]);
    }

    /**
     * Everything the dashboard says about one business.
     *
     * The most recently established listing, because that is the one somebody
     * just did something about. Null when this account holds none, which is the
     * empty case the page was designed around first.
     *
     * @param  list<int>  $partyIds
     * @return array<string, mixed>|null
     */
    private function focusFor(array $partyIds): ?array
    {
        if ($partyIds === []) {
            return null;
        }

        $control = PartyBusiness::query()
            ->with(['enterprise.structure.ward', 'enterprise.structure.lga'])
            ->whereIn('party_id', $partyIds)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->orderByDesc('established_at')
            ->first();

        if (! $control instanceof PartyBusiness) {
            return null;
        }

        /** @var Enterprise $enterprise */
        $enterprise = $control->enterprise;
        $structure = $enterprise->structure;

        return [
            'id' => $enterprise->id,
            'tradingName' => $enterprise->trading_name,
            'ward' => $structure->ward?->name,
            'lga' => $structure->lga?->name,
            'structureType' => $structure->structure_type,
            'enumeratedAt' => $enterprise->captured_at->toIso8601String(),
            'selfRegistered' => $structure->isSelfRegistered(),

            // Built server side from the record, so the ladder can never say
            // something the register does not.
            'rungs' => $this->tiers->rungs(
                (string) $structure->origin,
                (string) $structure->status,
                $enterprise->captured_at,
            ),
            'nextRung' => ($this->nextRung)($enterprise),
            'inFlight' => $this->inFlightFor($enterprise),
            'orders' => $this->ordersFor($enterprise),
            'activity' => $this->activity->forEnterprise($enterprise->id, $enterprise->getMorphClass()),
            'receipts' => ConsentReceipt::query()
                ->where('subject_type', $enterprise->getMorphClass())
                ->where('subject_id', $enterprise->id)
                ->orderByDesc('agreed_at')
                ->limit(2)
                ->get()
                ->map(static fn (ConsentReceipt $r): array => [
                    'token' => $r->token,
                    'granted' => $r->granted,
                    'agreedOn' => $r->agreed_at->toDateString(),
                ])->values()->all(),
            'publication' => [
                'state' => $enterprise->publication_state->value,
                'label' => $enterprise->publication_state->label(),
            ],
        ];
    }

    /**
     * Every verification order on this business, newest first, for the table
     * under the cards. The certificate link is only offered on a completed
     * order, which is the only kind that has one.
     *
     * @return list<array<string, mixed>>
     */
    private function ordersFor(Enterprise $enterprise): array
    {
        return VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (VerificationOrder $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'tier' => str_replace('_', ' ', $order->tier),
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'feeNaira' => (int) round($order->amount_minor / 100),
                'orderedAt' => $order->created_at?->toIso8601String(),
                'completedAt' => $order->completed_at?->toDateString(),
                'hasCertificate' => $order->status === OrderStatus::Completed,
                // A visit an investor paid for. The business sees it, because an
                // officer at the door should never be a surprise, but not who
                // asked: that is the investor's to disclose.
                'byInvestor' => $order->isCommissionedByInvestor(),
            ])
            ->values()
            ->all();
    }

    /**
     * The order that has not finished, if there is one.
     *
     * Only one is shown. Two visits in flight on the same business is rare and
     * the second one is not what somebody opened this page to find out about.
     *
     * @return array<string, mixed>|null
     */
    private function inFlightFor(Enterprise $enterprise): ?array
    {
        // The business's own orders only. One an investor commissioned is
        // listed in the table below but is not the business's to follow or pay.
        $order = VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->whereNotNull('party_id')
            ->orderByDesc('created_at')
            ->get()
            ->first(static fn (VerificationOrder $o): bool => ! $o->status->isSettled());

        if (! $order instanceof VerificationOrder) {
            return null;
        }

        // The tracker's four beats, each with the date it happened or nothing.
        // Derived from the order's own timestamps rather than from its status
        // string, so a step cannot claim a date the record does not hold.
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'tier' => str_replace('_', ' ', $order->tier),
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'feeNaira' => (int) round($order->amount_minor / 100),
            'dueBy' => $order->due_by?->toDateString(),
            'held' => $order->status !== OrderStatus::AwaitingPayment,
            'steps' => [
                ['label' => 'Ordered', 'at' => $order->created_at?->toDateString()],
                ['label' => 'Paid', 'at' => $order->paid_at?->toDateString()],
                // There is no assigned_at column: assignment is a state, and
                // the date it happened lives in the audit log rather than on
                // the order. The step reports reached or not reached, which is
                // what the tracker needs, and does not invent a date for it.
                ['label' => 'Officer assigned', 'at' => null,
                    'reached' => $order->assignment_id !== null],
                ['label' => 'Report accepted', 'at' => $order->completed_at?->toDateString()],
            ],
        ];
    }

    /**
     * The businesses this account can act on, across every party it holds.
     *
     * Flattened rather than nested under each party, because the person reading
     * this wants their shops, not an org chart. The party a listing belongs to
     * matters when acting on it and rarely when looking for it.
     *
     * @param  list<int>  $partyIds
     * @return list<array<string, mixed>>
     */
    private function listingsFor(array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        return PartyBusiness::query()
            ->with(['enterprise.structure.ward'])
            ->whereIn('party_id', $partyIds)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->orderByDesc('established_at')
            ->get()
            ->map(static fn (PartyBusiness $control): array => [
                'enterpriseId' => $control->enterprise_id,
                'tradingName' => $control->enterprise->trading_name,
                'ward' => $control->enterprise->structure->ward?->name,
                'since' => $control->established_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $partyIds
     * @return list<array<string, mixed>>
     */
    private function openClaimsFor(array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        return Claim::query()
            ->with('enterprise')
            ->whereIn('party_id', $partyIds)
            ->whereIn('status', [ClaimStatus::Submitted, ClaimStatus::Disputed])
            ->orderBy('asserted_at')
            ->get()
            ->map(static fn (Claim $claim): array => [
                'id' => $claim->id,
                'tradingName' => $claim->enterprise->trading_name,
                'status' => $claim->status->value,
                'statusLabel' => $claim->status->label(),
                'assertedAt' => $claim->asserted_at->toIso8601String(),
            ])
            ->all();
    }
}
