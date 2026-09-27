<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Support\Facades\Hash;

/**
 * The second way into the portal: a business ID or an email, and a password.
 *
 * Phone and code stay the first way, and the only way to open an account. A
 * password exists only once somebody has set one from a session that already
 * proved the phone, so this can never be the easier route to an account nobody
 * proved.
 *
 * A business ID is the party's code (NBD-XXXX-XXXX-C), printed on its
 * certificate. Every person with live access to that business signs in with the
 * same ID and their own password, so the password is what says which of them it
 * is. Checked against each member in turn, a handful at most, and the answer is
 * the same for "no such business" as for "wrong password", so the form cannot be
 * used to learn which IDs exist.
 */
final class SignInWithPassword
{
    private const MAX_MEMBERS = 25;

    public function __invoke(string $identifier, string $password): ?PortalAccount
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            $account = PortalAccount::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($identifier)])
                ->whereNotNull('password')
                ->first();

            return $account !== null && $this->usable($account) && Hash::check($password, (string) $account->password)
                ? $account
                : $this->spend($password);
        }

        $party = Party::query()->where('code', mb_strtoupper($identifier))->first();

        if ($party === null) {
            return $this->spend($password);
        }

        $accounts = PartyUser::query()
            ->where('party_id', $party->id)
            ->whereNull('revoked_at')
            ->whereNotNull('accepted_at')
            ->with('account')
            ->limit(self::MAX_MEMBERS)
            ->get()
            ->map(static fn (PartyUser $m): ?PortalAccount => $m->account)
            ->filter(static fn (?PortalAccount $a): bool => $a !== null && $a->password !== null);

        foreach ($accounts as $account) {
            if ($this->usable($account) && Hash::check($password, (string) $account->password)) {
                return $account;
            }
        }

        return $this->spend($password);
    }

    private function usable(PortalAccount $account): bool
    {
        return $account->status === 'active';
    }

    /**
     * Hash once on a miss, so a wrong business ID takes as long to refuse as a
     * wrong password and the timing says nothing either.
     */
    private function spend(string $password): null
    {
        // Made with the configured hasher, so the check cannot refuse it for
        // being the wrong algorithm and the cost matches a real account's.
        static $dummy = null;
        $dummy ??= Hash::make('not a password anybody holds');

        Hash::check($password, $dummy);

        return null;
    }
}
