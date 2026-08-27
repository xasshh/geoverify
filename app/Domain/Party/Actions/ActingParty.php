<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Which party a person is acting for right now.
 *
 * One person can hold access to several: an agent who manages three shops, a
 * family with a business each. So "who is signed in" and "on whose behalf" are
 * different questions, and every action that touches a listing needs the second
 * one answered rather than assumed.
 *
 * Resolved from the session when a choice has been made, and from the
 * membership when there is only one thing it could be, which is the common
 * case and should not require a click.
 */
final class ActingParty
{
    public const SESSION_KEY = 'portal.acting_party';

    public function forRequest(Request $request, PortalAccount $account): ?PartyUser
    {
        $memberships = $this->membershipsFor($account);

        if ($memberships->isEmpty()) {
            return null;
        }

        $chosen = $request->session()->get(self::SESSION_KEY);

        if (is_int($chosen)) {
            $match = $memberships->firstWhere('party_id', $chosen);

            if ($match instanceof PartyUser) {
                return $match;
            }

            // The stored choice no longer resolves, which happens when access
            // is revoked while somebody is signed in. Fall through to the
            // default rather than failing: revocation should quietly reduce
            // what they can do, not break the page.
            $request->session()->forget(self::SESSION_KEY);
        }

        return $memberships->count() === 1 ? $memberships->first() : null;
    }

    /** @return Collection<int, PartyUser> */
    public function membershipsFor(PortalAccount $account): Collection
    {
        return PartyUser::query()
            ->with('party')
            ->where('portal_account_id', $account->id)
            ->whereNull('revoked_at')
            ->whereNotNull('accepted_at')
            ->orderBy('id')
            ->get();
    }
}
