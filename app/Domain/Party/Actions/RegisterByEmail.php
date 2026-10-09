<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Opens a portal account with an email and a password.
 *
 * The account exists from this moment but opens nothing until the email is
 * proved (EnsurePortalAccount sends it to the "check your inbox" page). A
 * business registration also creates its party, so the person who verifies
 * lands on their business ID rather than a second form.
 *
 * An address that already has an account is refused in the same words
 * whether or not it is verified, and the verification email goes out after
 * the transaction commits: an account rolled back must not have been mailed.
 */
final class RegisterByEmail
{
    public function __construct(
        private readonly RegisterParty $parties,
        private readonly SendPortalEmailVerification $verify,
    ) {}

    /**
     * @param  array{display_name: string, kind: PartyKind, legal_name?: string|null, phone?: string|null}|null  $business
     * @return array{account: PortalAccount, party: Party|null, mailed: bool}
     */
    public function __invoke(string $name, string $email, string $password, ?array $business): array
    {
        $email = mb_strtolower(trim($email));

        $result = DB::transaction(function () use ($name, $email, $password, $business): array {
            if (PortalAccount::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw new RuntimeException('That email already has an account. Sign in, or use "Forgot password".');
            }

            $account = PortalAccount::query()->create([
                'name' => trim($name),
                'email' => $email,
                'password' => $password,
                'status' => PortalAccount::STATUS_ACTIVE,
            ]);

            VerificationEvent::record($account, 'portal.registered_by_email', null, [], VerificationEvent::ACTOR_EXTERNAL);

            $party = $business === null ? null : $this->parties->forAccount(
                $account,
                trim($business['display_name']),
                $business['kind'],
                $business['legal_name'] ?? null,
                $business['phone'] ?? null,
            );

            return ['account' => $account, 'party' => $party];
        });

        // A mail service that refuses is reported, not thrown: the account is
        // made, and the notice page sends the link again.
        try {
            ($this->verify)($result['account']);
            $mailed = true;
        } catch (Throwable $e) {
            report($e);
            $mailed = false;
        }

        return $result + ['mailed' => $mailed];
    }
}
