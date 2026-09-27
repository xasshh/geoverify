<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Concerns;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who is acting, for which business, and whether their role allows it.
 *
 * The merchant hub's controllers share this rather than each carrying its own
 * copy of the control check, which is how one of them eventually forgets the
 * status clause.
 */
trait ActsForBusiness
{
    protected function account(Request $request): PortalAccount
    {
        $account = $request->user('portal');

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Sign in first.');
        }

        return $account;
    }

    protected function membership(Request $request): PartyUser
    {
        $membership = app(ActingParty::class)->forRequest($request, $this->account($request));

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('You do not act for a business yet.');
        }

        return $membership;
    }

    /** The acting membership, if it controls this business. */
    protected function controlling(Request $request, Enterprise $enterprise): PartyUser
    {
        $membership = $this->membership($request);

        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }

        return $membership;
    }

    /** Controls it, and may change what it says: owners and managers, not viewers. */
    protected function editing(Request $request, Enterprise $enterprise): PartyUser
    {
        $membership = $this->controlling($request, $enterprise);

        if (! $membership->role->proposesChanges()) {
            throw new AccessDeniedHttpException('Your role can view this business but not change it.');
        }

        return $membership;
    }
}
