<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds or opens the portal account for a Google sign in.
 *
 * Google has proved the address, so an account found by it is marked verified
 * and one opened by it starts verified, with no password. The Google id is
 * looked for first, then the email, so a person who registered with a
 * password and later presses "Continue with Google" lands in the same
 * account. Only a Google answer that says the email is verified is accepted:
 * an unverified Google address would let anybody claim any account.
 */
final class SignInWithGoogle
{
    public function __invoke(string $googleId, string $email, bool $emailVerified, string $name): PortalAccount
    {
        if (! $emailVerified) {
            throw new RuntimeException('Google has not confirmed that email address, so we cannot use it to sign you in.');
        }

        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($googleId, $email, $name): PortalAccount {
            $account = PortalAccount::query()->where('google_id', $googleId)->lockForUpdate()->first()
                ?? PortalAccount::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

            if ($account === null) {
                $account = PortalAccount::query()->create([
                    'name' => trim($name) === '' ? $email : mb_substr(trim($name), 0, 120),
                    'email' => $email,
                    'status' => PortalAccount::STATUS_ACTIVE,
                ]);
                $account->forceFill(['google_id' => $googleId, 'email_verified_at' => now()])->save();

                VerificationEvent::record($account, 'portal.registered_by_google', null, [], VerificationEvent::ACTOR_EXTERNAL);

                return $account;
            }

            if (! $account->canSignIn()) {
                throw new RuntimeException('This account is suspended.');
            }

            if ($account->google_id !== null && $account->google_id !== $googleId) {
                throw new RuntimeException('That email belongs to an account linked to a different Google account.');
            }

            $account->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => $account->email_verified_at ?? now(),
                'last_signed_in_at' => now(),
            ])->save();

            return $account;
        });
    }

    /** Whether "Continue with Google" is set up on this server. */
    public static function configured(): bool
    {
        return (string) config('services.google.client_id') !== '' && (string) config('services.google.client_secret') !== '';
    }
}
