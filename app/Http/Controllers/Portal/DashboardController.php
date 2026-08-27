<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Enums\ClaimStatus;
use App\Domain\Claim\Models\Claim;
use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
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
        ]);
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
