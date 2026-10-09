<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a party, the account that owns it, and the membership between them.
 *
 * Called only after the phone has been proved by a one-time code. The number is
 * therefore already known to be reachable and controlled, which is why it is
 * the party's primary contact without further ceremony.
 *
 * A party starts at `listed`: it has told us it exists and we reached it.
 * Nothing more is claimed, and the ladder says so.
 */
final class RegisterParty
{
    public function __construct(
        private readonly PartyCode $codes,
        private readonly NormalisePhone $phones,
    ) {}

    public function __invoke(
        string $verifiedPhone,
        string $personName,
        string $displayName,
        PartyKind $kind,
        ?string $legalName = null,
        ?string $email = null,
    ): Party {
        $phone = ($this->phones)($verifiedPhone);

        if (PortalAccount::query()->where('phone', $phone)->exists()) {
            throw new RuntimeException('That number already has an account. Sign in instead.');
        }

        return DB::transaction(function () use ($phone, $personName, $displayName, $kind, $legalName, $email): Party {
            $party = Party::query()->create([
                'code' => $this->uniqueCode(),
                'kind' => $kind,
                'display_name' => $displayName,
                'legal_name' => $legalName,
                'primary_phone' => $phone,
                'primary_email' => $email,
                'status' => Party::STATUS_ACTIVE,
                'identity_tier' => 'listed',
            ]);

            $account = PortalAccount::query()->create([
                'name' => $personName,
                'phone' => $phone,
                'email' => $email,
                'status' => PortalAccount::STATUS_ACTIVE,
            ]);

            $account->forceFill(['phone_verified_at' => now()])->save();

            PartyUser::query()->create([
                'party_id' => $party->id,
                'portal_account_id' => $account->id,
                'role' => PartyRole::Owner,
                // The person who registers a party has already accepted: there
                // was nobody to invite them.
                'accepted_at' => now(),
            ]);

            VerificationEvent::record($party, 'party.registered', null, [
                'code' => $party->code,
                'kind' => $kind->value,
                'phone' => $this->phones->masked($phone),
            ], VerificationEvent::ACTOR_PARTY);

            return $party;
        });
    }

    /**
     * A party for an account that already exists and proved its email: the
     * email-first registration. The account becomes the party's owner.
     */
    public function forAccount(
        PortalAccount $account,
        string $displayName,
        PartyKind $kind,
        ?string $legalName = null,
        ?string $phone = null,
    ): Party {
        $phone = $phone === null || trim($phone) === '' ? null : ($this->phones)($phone);

        return DB::transaction(function () use ($account, $displayName, $kind, $legalName, $phone): Party {
            $party = Party::query()->create([
                'code' => $this->uniqueCode(),
                'kind' => $kind,
                'display_name' => $displayName,
                'legal_name' => $legalName,
                'primary_phone' => $phone,
                'primary_email' => $account->email,
                'status' => Party::STATUS_ACTIVE,
                'identity_tier' => 'listed',
            ]);

            PartyUser::query()->create([
                'party_id' => $party->id,
                'portal_account_id' => $account->id,
                'role' => PartyRole::Owner,
                'accepted_at' => now(),
            ]);

            VerificationEvent::record($party, 'party.registered', null, [
                'code' => $party->code,
                'kind' => $kind->value,
                'by' => 'email',
            ], VerificationEvent::ACTOR_PARTY);

            return $party;
        });
    }

    /**
     * A code no party already holds.
     *
     * The alphabet gives 31^8 bodies, so a collision is vanishingly unlikely
     * and the retry is cheap. Vanishingly unlikely is not never, and a
     * duplicate key on the accountability spine is not a thing to discover in
     * production.
     */
    private function uniqueCode(): string
    {
        foreach (range(1, 5) as $ignored) {
            $code = $this->codes->issue();

            if (! Party::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not issue a party code. Try again.');
    }
}
