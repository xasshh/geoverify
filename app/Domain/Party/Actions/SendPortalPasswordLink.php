<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PortalAccount;
use App\Mail\PortalActionMail;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * The link to choose a password: after "forgot password", or when somebody
 * has been invited onto a business or an organisation and has no account yet.
 *
 * Tokens come from the portal's own password broker (its own table), and
 * following the link proves the email, so an invited person is verified the
 * moment they set a password.
 */
final class SendPortalPasswordLink
{
    /** Mint a token and send it, for an invitation. */
    public function invite(PortalAccount $account, string $invitedTo): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker('portal_accounts');

        $this->deliver($account, $broker->createToken($account), 'invite', $invitedTo);
    }

    /** @param  'reset'|'invite'  $purpose */
    public function deliver(PortalAccount $account, string $token, string $purpose, ?string $invitedTo = null): void
    {
        $url = route('portal.reset-password', ['token' => $token, 'email' => $account->email]);

        Mail::to((string) $account->email)->send($purpose === 'invite'
            ? new PortalActionMail(
                heading: "You have been invited to {$invitedTo} on GeoVerify",
                lines: [
                    "Hello {$account->name},",
                    "You have been given access to {$invitedTo}. Choose a password to open your account.",
                ],
                buttonLabel: 'Choose my password',
                url: $url,
                footnote: 'The link works for 60 minutes. If it expires, use "Forgot password" on the sign in page with this email.',
            )
            : new PortalActionMail(
                heading: 'Reset your GeoVerify password',
                lines: [
                    "Hello {$account->name},",
                    'Somebody asked to reset the password for this account. If it was you, choose a new one.',
                ],
                buttonLabel: 'Choose a new password',
                url: $url,
                footnote: 'The link works for 60 minutes. If you did not ask for this, ignore this email: your password has not changed.',
            ));
    }
}
