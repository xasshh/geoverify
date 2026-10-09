<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;

/**
 * Marks an account's email proved, from a signed link.
 *
 * The signature is checked by the route; this checks the link was minted for
 * the address the account holds now. Following a link twice is harmless.
 */
final class ConfirmPortalEmail
{
    public function __invoke(PortalAccount $account, string $hash): bool
    {
        if ($account->email === null || ! hash_equals(SendPortalEmailVerification::hashOf($account->email), $hash)) {
            return false;
        }

        if ($account->email_verified_at === null) {
            $account->forceFill(['email_verified_at' => now()])->save();
            VerificationEvent::record($account, 'portal.email_verified', null, [], VerificationEvent::ACTOR_EXTERNAL);
        }

        return true;
    }
}
