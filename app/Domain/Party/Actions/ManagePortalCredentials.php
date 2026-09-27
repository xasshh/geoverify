<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accounts and their second credential.
 *
 * A buyer is a portal account with no party membership: somebody who proved a
 * phone to buy from or save businesses, and owns none. They become a party on
 * the same account the day they claim or add one, and nobody re-registers.
 *
 * A password is set only from a session that proved the phone, either signed in
 * or through the reset flow, which proves it again by code. The password never
 * replaces the phone; it is a faster way back for somebody at a desk.
 */
final class ManagePortalCredentials
{
    public function registerBuyer(string $verifiedPhone, string $name, ?string $email): PortalAccount
    {
        return DB::transaction(function () use ($verifiedPhone, $name, $email): PortalAccount {
            if (PortalAccount::query()->where('phone', $verifiedPhone)->exists()) {
                throw new RuntimeException('That number already has an account. Sign in instead.');
            }

            if ($email !== null && PortalAccount::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
                throw new RuntimeException('That email is already used by another account.');
            }

            $account = PortalAccount::query()->create([
                'name' => $name,
                'phone' => $verifiedPhone,
                'email' => $email === null ? null : mb_strtolower($email),
                'status' => 'active',
            ]);

            $account->forceFill(['phone_verified_at' => now()])->save();

            VerificationEvent::record($account, 'portal.buyer_registered', null, [], VerificationEvent::ACTOR_EXTERNAL);

            return $account;
        });
    }

    public function setPassword(PortalAccount $account, string $password, string $how): void
    {
        $account->forceFill(['password' => $password])->save();

        VerificationEvent::record($account, 'portal.password_set', null, ['how' => $how], VerificationEvent::ACTOR_EXTERNAL);
    }

    public function setEmail(PortalAccount $account, ?string $email): void
    {
        $email = $email === null || $email === '' ? null : mb_strtolower($email);

        if ($email !== null && PortalAccount::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('id', '<>', $account->id)
            ->exists()) {
            throw new RuntimeException('That email is already used by another account.');
        }

        $account->forceFill(['email' => $email])->save();
    }
}
