<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PortalAccount;
use App\Mail\PortalActionMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Sends the link that proves an account's email reaches its owner.
 *
 * The link is signed and lives a day. It names the account and a hash of the
 * address it was sent to, so a link sent before the address was changed does
 * not verify the new one. Five a day per account: enough for a lost email,
 * not enough to use us to fill a stranger's inbox.
 */
final class SendPortalEmailVerification
{
    private const PER_ACCOUNT_DAILY = 5;

    public function __invoke(PortalAccount $account): void
    {
        if ($account->email === null) {
            throw new RuntimeException('Add an email address first.');
        }

        $key = "portal-verify-email:{$account->id}";

        if (RateLimiter::tooManyAttempts($key, self::PER_ACCOUNT_DAILY)) {
            throw new RuntimeException('We have sent several links already today. Check your spam folder, or try again tomorrow.');
        }

        RateLimiter::hit($key, 86_400);

        $url = URL::temporarySignedRoute('portal.email.verify', now()->addDay(), [
            'account' => $account->id,
            'hash' => self::hashOf((string) $account->email),
        ]);

        Mail::to($account->email)->send(new PortalActionMail(
            heading: 'Verify your email for GeoVerify',
            lines: [
                "Hello {$account->name},",
                'Confirm this is your email address and your account is ready to use.',
            ],
            buttonLabel: 'Verify my email',
            url: $url,
            footnote: 'The link works for 24 hours. If you did not create a GeoVerify account, ignore this email and nothing will happen.',
        ));
    }

    public static function hashOf(string $email): string
    {
        return sha1(mb_strtolower(trim($email)));
    }
}
