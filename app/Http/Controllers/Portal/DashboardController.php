<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

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
            'parties' => $memberships->map(static fn (PartyUser $membership): array => [
                'id' => $membership->party_id,
                'code' => $membership->party?->code,
                'displayName' => $membership->party?->display_name,
                'kind' => $membership->party?->kind->label(),
                'role' => $membership->role->label(),
                'identityTier' => $membership->party?->identity_tier,
                // No listings exist until M3 claims them or M4 registers them.
                'listings' => 0,
            ])->all(),
        ]);
    }
}
