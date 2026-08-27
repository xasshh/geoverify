<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Models\PortalAccount;
use App\Domain\Party\Models\SignInCode;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Checks a one-time code and returns the account it belongs to, if any.
 *
 * Returns null for "correct code, no account yet", because the same code path
 * serves registration and sign-in: a person proves the number is theirs first,
 * and only then are they asked who they are. Asking for a name before proving
 * the phone means storing details for numbers nobody controls.
 */
final class VerifySignInCode
{
    public function __construct(private readonly NormalisePhone $phones) {}

    /**
     * @return array{phone: string, account: PortalAccount|null}
     */
    public function __invoke(string $rawPhone, string $code): array
    {
        $phone = ($this->phones)($rawPhone);

        /** @var SignInCode|null $pending */
        $pending = SignInCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $pending instanceof SignInCode || ! $pending->isUsable()) {
            throw new RuntimeException('That code has expired. Ask for a new one.');
        }

        // Counted before the comparison, so a wrong guess costs an attempt
        // whatever happens next.
        $pending->increment('attempts');

        if (! Hash::check($code, $pending->code_hash)) {
            $left = SignInCode::MAX_ATTEMPTS - $pending->attempts;

            throw new RuntimeException(
                $left > 0
                    ? "That code is not right. {$left} more ".($left === 1 ? 'try' : 'tries').'.'
                    : 'That code is now closed. Ask for a new one.',
            );
        }

        $pending->update(['consumed_at' => now()]);

        /** @var PortalAccount|null $account */
        $account = PortalAccount::query()->where('phone', $phone)->first();

        if ($account instanceof PortalAccount) {
            if (! $account->canSignIn()) {
                throw new RuntimeException('This account is suspended. Contact support.');
            }

            DB::transaction(function () use ($account): void {
                $account->forceFill([
                    'phone_verified_at' => $account->phone_verified_at ?? now(),
                    'last_signed_in_at' => now(),
                ])->save();

                // Every sign-in is appended, per the brief. The log is the
                // product here as much as it is in the field platform.
                VerificationEvent::record(
                    $account,
                    'portal.signed_in',
                    null,
                    ['phone' => $this->phones->masked($account->phone)],
                    VerificationEvent::ACTOR_PARTY,
                );
            });
        }

        return ['phone' => $phone, 'account' => $account];
    }
}
