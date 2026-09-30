<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Enumerate\Models\EnumerateOrganisation;
use App\Domain\Enumerate\Models\EnumerateWallet;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Http\Request;

/**
 * Who a signed-in person is acting as on Enumerate: themselves, or one of the
 * organisations they hold a seat in.
 *
 * Kept in the session and checked against a live, accepted seat on every
 * read, so a seat revoked while somebody is signed in stops working on their
 * next click rather than at their next sign-in. Everything that spends or
 * lists money asks this for the wallet, so the choice made in the switcher is
 * the only thing that decides whose money a request uses.
 */
final class EnumerateContext
{
    public const SESSION = 'enumerate.acting';

    public function __construct(private readonly ManageRequesterWallet $wallets) {}

    /** The seat being used, or null when acting as oneself. */
    public function member(Request $request, PortalAccount $account): ?EnumerateMember
    {
        $organisationId = $request->session()->get(self::SESSION);

        if (! is_int($organisationId)) {
            return null;
        }

        $member = EnumerateMember::query()
            ->where('organisation_id', $organisationId)
            ->where('portal_account_id', $account->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('organisation')
            ->first();

        if ($member === null || $member->organisation?->status === EnumerateOrganisation::SUSPENDED) {
            $request->session()->forget(self::SESSION);

            return null;
        }

        return $member;
    }

    public function wallet(Request $request, PortalAccount $account): EnumerateWallet
    {
        $member = $this->member($request, $account);

        return $member === null
            ? $this->wallets->walletFor($account)
            : $this->wallets->walletForOrganisation($member->organisation_id);
    }

    /** Switch to an organisation the account has a live seat in, or back to oneself. */
    public function switch(Request $request, PortalAccount $account, ?int $organisationId): void
    {
        if ($organisationId === null) {
            $request->session()->forget(self::SESSION);

            return;
        }

        $seat = EnumerateMember::query()
            ->where('organisation_id', $organisationId)
            ->where('portal_account_id', $account->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->exists();

        if ($seat) {
            $request->session()->put(self::SESSION, $organisationId);
        }
    }

    /**
     * The organisations the account can switch to.
     *
     * @return list<array{id: int, name: string, role: string, status: string}>
     */
    public function organisations(PortalAccount $account): array
    {
        return EnumerateMember::query()
            ->where('portal_account_id', $account->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('organisation')
            ->get()
            ->filter(static fn (EnumerateMember $m): bool => $m->organisation !== null && $m->organisation->status !== EnumerateOrganisation::SUSPENDED)
            ->map(static fn (EnumerateMember $m): array => [
                'id' => $m->organisation_id,
                'name' => (string) $m->organisation?->name,
                'role' => $m->role,
                'status' => (string) $m->organisation?->status,
            ])
            ->values()
            ->all();
    }

    /**
     * Invitations waiting for this person, found by the phone they proved.
     *
     * @return list<array{id: int, organisation: string, role: string}>
     */
    public function invitations(PortalAccount $account): array
    {
        return EnumerateMember::query()
            ->where('phone', $account->phone)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('organisation')
            ->get()
            ->map(static fn (EnumerateMember $m): array => [
                'id' => $m->id,
                'organisation' => (string) $m->organisation?->name,
                'role' => EnumerateMember::ROLES[$m->role] ?? $m->role,
            ])
            ->values()
            ->all();
    }
}
