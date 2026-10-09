<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use App\Domain\Party\Enums\PartyRole;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who may act for a business.
 *
 * Only an owner changes access. A person is invited by phone number and holds
 * nothing until they prove that number with a code and accept: an invitation
 * to a number somebody mistyped gives the stranger holding it nothing they did
 * not first prove. The owner is never invited, demoted or revoked here, so a
 * business always has exactly one owner able to act, which the partial unique
 * index on party_users holds in the database too.
 */
final class ManageTeam
{
    public function __construct(
        private readonly NormalisePhone $phones,
        private readonly SendPortalPasswordLink $links,
    ) {}

    public function invite(PartyUser $owner, string $rawPhone, string $name, PartyRole $role): PartyUser
    {
        $this->assertOwner($owner);

        if ($role === PartyRole::Owner) {
            throw new RuntimeException('A business has one owner. Invite a manager or a viewer.');
        }

        $phone = ($this->phones)($rawPhone);

        return DB::transaction(function () use ($owner, $phone, $name, $role): PartyUser {
            $account = PortalAccount::query()->firstOrCreate(
                ['phone' => $phone],
                ['name' => $name, 'status' => 'active'],
            );

            $membership = PartyUser::query()->firstOrNew([
                'party_id' => $owner->party_id,
                'portal_account_id' => $account->id,
            ]);

            if ($membership->exists && $membership->revoked_at === null) {
                throw new RuntimeException($membership->accepted_at === null
                    ? 'That number already has an invitation waiting.'
                    : 'That person already has access.');
            }

            $membership->fill([
                'role' => $role,
                'invited_by' => $owner->portal_account_id,
                'invited_at' => now(),
                'accepted_at' => null,
                'revoked_at' => null,
            ])->save();

            VerificationEvent::recordForParty($owner->party, 'team.invited', $owner->party, [
                'membership_id' => $membership->id,
                'role' => $role->value,
                'phone_masked' => $this->phones->masked($phone),
            ]);

            return $membership;
        });
    }

    /**
     * The same, to an email address. Somebody with no account yet gets one,
     * passwordless, and an email with a link to choose a password; following
     * it proves the address. Somebody who already has an account sees the
     * invitation the next time they sign in.
     */
    public function inviteByEmail(PartyUser $owner, string $email, string $name, PartyRole $role): PartyUser
    {
        $this->assertOwner($owner);

        if ($role === PartyRole::Owner) {
            throw new RuntimeException('A business has one owner. Invite a manager or a viewer.');
        }

        $email = mb_strtolower(trim($email));
        $created = false;

        $membership = DB::transaction(function () use ($owner, $email, $name, $role, &$created): PartyUser {
            $account = PortalAccount::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($account === null) {
                $account = PortalAccount::query()->create(['name' => $name, 'email' => $email, 'status' => PortalAccount::STATUS_ACTIVE]);
                $created = true;
            }

            $membership = PartyUser::query()->firstOrNew([
                'party_id' => $owner->party_id,
                'portal_account_id' => $account->id,
            ]);

            if ($membership->exists && $membership->revoked_at === null) {
                throw new RuntimeException($membership->accepted_at === null
                    ? 'That email already has an invitation waiting.'
                    : 'That person already has access.');
            }

            $membership->fill([
                'role' => $role,
                'invited_by' => $owner->portal_account_id,
                'invited_at' => now(),
                'accepted_at' => null,
                'revoked_at' => null,
            ])->save();

            VerificationEvent::recordForParty($owner->party, 'team.invited', $owner->party, [
                'membership_id' => $membership->id,
                'role' => $role->value,
                'by' => 'email',
            ]);

            return $membership;
        });

        if ($created) {
            $this->links->invite($membership->account()->firstOrFail(), (string) $owner->party?->display_name);
        }

        return $membership;
    }

    /** The invitee, signed in on the invited number, says yes. */
    public function accept(PartyUser $membership, PortalAccount $account): void
    {
        if ($membership->portal_account_id !== $account->id || $membership->revoked_at !== null) {
            throw new RuntimeException('That invitation is not yours.');
        }

        $membership->forceFill(['accepted_at' => now()])->save();

        VerificationEvent::recordForParty($membership->party, 'team.accepted', $membership->party, [
            'membership_id' => $membership->id,
        ]);
    }

    public function changeRole(PartyUser $owner, PartyUser $member, PartyRole $role): void
    {
        $this->assertOwner($owner);
        $this->assertSameParty($owner, $member);

        if ($member->role === PartyRole::Owner || $role === PartyRole::Owner) {
            throw new RuntimeException('Ownership is not changed here.');
        }

        $member->forceFill(['role' => $role])->save();

        VerificationEvent::recordForParty($owner->party, 'team.role_changed', $owner->party, [
            'membership_id' => $member->id,
            'role' => $role->value,
        ]);
    }

    public function revoke(PartyUser $owner, PartyUser $member): void
    {
        $this->assertOwner($owner);
        $this->assertSameParty($owner, $member);

        if ($member->role === PartyRole::Owner) {
            throw new RuntimeException('The owner cannot be removed.');
        }

        $member->forceFill(['revoked_at' => now()])->save();

        VerificationEvent::recordForParty($owner->party, 'team.revoked', $owner->party, [
            'membership_id' => $member->id,
        ]);
    }

    private function assertOwner(PartyUser $owner): void
    {
        if (! $owner->role->managesAccess() || $owner->revoked_at !== null) {
            throw new RuntimeException('Only the owner can change who has access.');
        }
    }

    private function assertSameParty(PartyUser $owner, PartyUser $member): void
    {
        if ($owner->party_id !== $member->party_id) {
            throw new RuntimeException('That person is not on this business.');
        }
    }
}
