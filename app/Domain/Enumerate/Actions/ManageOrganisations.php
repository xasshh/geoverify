<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Enumerate\Models\EnumerateOrganisation;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use App\Mail\PortalActionMail;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * An organisation's life: opening it, an admin's ruling on it, and its team.
 *
 * The person who opens it holds its first admin seat. Every change to the
 * team is made by somebody holding the team ability and is written to the
 * audit log against the organisation. An organisation is never left without
 * an admin: the last one cannot be removed or demoted.
 */
final class ManageOrganisations
{
    public function __construct(
        private readonly ManageRequesterWallet $wallets,
        private readonly NormalisePhone $phones,
    ) {}

    public function open(PortalAccount $account, string $name, ?string $rcNumber, ?string $email): EnumerateOrganisation
    {
        $name = trim($name);

        if (mb_strlen($name) < 3) {
            throw new RuntimeException('Give the organisation’s registered name.');
        }

        return DB::transaction(function () use ($account, $name, $rcNumber, $email): EnumerateOrganisation {
            $organisation = EnumerateOrganisation::query()->create([
                'name' => mb_substr($name, 0, 160),
                'rc_number' => $rcNumber === null || trim($rcNumber) === '' ? null : mb_substr((string) preg_replace('/\s+/', '', $rcNumber), 0, 32),
                'contact_email' => $email === null || trim($email) === '' ? null : mb_substr(trim($email), 0, 180),
                'status' => EnumerateOrganisation::PENDING,
                'created_by' => $account->id,
            ]);

            EnumerateMember::query()->create([
                'organisation_id' => $organisation->id,
                'phone' => $account->phone,
                'email' => $account->phone === null ? $account->email : null,
                'portal_account_id' => $account->id,
                'role' => 'admin',
                'accepted_at' => now(),
            ]);

            $this->wallets->walletForOrganisation($organisation->id);

            VerificationEvent::recordForBuyer($organisation, 'enumerate.organisation_opened', $account, ['name' => $organisation->name]);

            return $organisation;
        });
    }

    /** An administrator approves an organisation, or suspends it, with a reason. */
    public function decide(EnumerateOrganisation $organisation, User $admin, bool $approve, ?string $note): EnumerateOrganisation
    {
        if (! $admin->administers()) {
            throw new RuntimeException('Only an administrator rules on an organisation.');
        }

        if (! $approve && ($note === null || mb_strlen(trim($note)) < 10)) {
            throw new RuntimeException('Say why, in a sentence the organisation will read.');
        }

        $organisation->update([
            'status' => $approve ? EnumerateOrganisation::APPROVED : EnumerateOrganisation::SUSPENDED,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'decision_note' => $note === null ? null : mb_substr(trim($note), 0, 500),
        ]);

        VerificationEvent::record($organisation, $approve ? 'enumerate.organisation_approved' : 'enumerate.organisation_suspended', $admin, ['note' => $note]);

        return $organisation;
    }

    public function assignManager(EnumerateOrganisation $organisation, User $admin, User $manager): void
    {
        if (! $admin->administers()) {
            throw new RuntimeException('Only an administrator assigns an account manager.');
        }

        if (! $manager->supervises()) {
            throw new RuntimeException('An account manager is a supervisor or an administrator.');
        }

        $organisation->update(['account_manager_id' => $manager->id]);
        VerificationEvent::record($organisation, 'enumerate.account_manager_assigned', $admin, ['manager_id' => $manager->id]);
    }

    /** A seat for a phone number. It waits there until that person accepts it. */
    public function invite(EnumerateMember $by, string $phone, string $role): EnumerateMember
    {
        $this->assertTeam($by);

        if (! array_key_exists($role, EnumerateMember::ROLES)) {
            throw new RuntimeException('Choose a role.');
        }

        try {
            $phone = ($this->phones)($phone);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }

        try {
            $seat = DB::transaction(fn (): EnumerateMember => EnumerateMember::query()->create([
                'organisation_id' => $by->organisation_id,
                'phone' => $phone,
                'portal_account_id' => PortalAccount::query()->where('phone', $phone)->value('id'),
                'role' => $role,
                'invited_by' => $by->portal_account_id,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('That number already has a seat here.');
        }

        VerificationEvent::recordForBuyer($seat->organisation()->firstOrFail(), 'enumerate.member_invited', $by->account()->firstOrFail(), [
            'member_id' => $seat->id,
            'role' => $role,
        ]);

        return $seat;
    }

    /**
     * A seat for an email address. The invitee is told by email: to sign in
     * if they have an account, or to open one with that address if not.
     */
    public function inviteByEmail(EnumerateMember $by, string $email, string $role): EnumerateMember
    {
        $this->assertTeam($by);

        if (! array_key_exists($role, EnumerateMember::ROLES)) {
            throw new RuntimeException('Choose a role.');
        }

        $email = mb_strtolower(trim($email));

        try {
            $seat = DB::transaction(fn (): EnumerateMember => EnumerateMember::query()->create([
                'organisation_id' => $by->organisation_id,
                'email' => $email,
                'portal_account_id' => PortalAccount::query()->whereRaw('lower(email) = ?', [$email])->value('id'),
                'role' => $role,
                'invited_by' => $by->portal_account_id,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('That email already has a seat here.');
        }

        $organisation = $seat->organisation()->firstOrFail();

        VerificationEvent::recordForBuyer($organisation, 'enumerate.member_invited', $by->account()->firstOrFail(), [
            'member_id' => $seat->id,
            'role' => $role,
            'by' => 'email',
        ]);

        $hasAccount = $seat->portal_account_id !== null;

        try {
            Mail::to($email)->send(new PortalActionMail(
                heading: "You have been invited to {$organisation->name} on Enumerate",
                lines: [
                    'Hello,',
                    "{$organisation->name} has given you a seat as ".EnumerateMember::ROLES[$role].' on Enumerate, GeoVerify\'s business verification service.',
                    $hasAccount
                        ? 'Sign in with this email address and accept the invitation on your Enumerate home.'
                        : 'Create your account with this email address, then accept the invitation on your Enumerate home.',
                ],
                buttonLabel: $hasAccount ? 'Sign in to Enumerate' : 'Create my account',
                url: $hasAccount ? route('enumerate.sign-in') : route('portal.register', ['as' => 'buyer', 'email' => $email, 'next' => 'enumerate']),
                footnote: 'If you were not expecting this, ignore this email.',
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return $seat;
    }

    public function accept(EnumerateMember $seat, PortalAccount $account): EnumerateMember
    {
        $byEmail = $seat->email !== null
            && $account->email !== null
            && $account->email_verified_at !== null
            && mb_strtolower($seat->email) === mb_strtolower($account->email);
        $byPhone = $seat->phone !== null && $seat->phone === $account->phone;

        if (! ($byEmail || $byPhone) || $seat->revoked_at !== null) {
            throw new RuntimeException('That invitation is not for you.');
        }

        $seat->update(['portal_account_id' => $account->id, 'accepted_at' => $seat->accepted_at ?? now()]);

        return $seat;
    }

    public function changeRole(EnumerateMember $by, EnumerateMember $seat, string $role): void
    {
        $this->assertTeam($by);
        $this->assertSameOrganisation($by, $seat);

        if (! array_key_exists($role, EnumerateMember::ROLES)) {
            throw new RuntimeException('Choose a role.');
        }

        if ($seat->role === 'admin' && $role !== 'admin') {
            $this->assertNotLastAdmin($seat);
        }

        $seat->update(['role' => $role]);
    }

    public function revoke(EnumerateMember $by, EnumerateMember $seat): void
    {
        $this->assertTeam($by);
        $this->assertSameOrganisation($by, $seat);

        if ($seat->role === 'admin') {
            $this->assertNotLastAdmin($seat);
        }

        $seat->update(['revoked_at' => now()]);

        VerificationEvent::recordForBuyer($seat->organisation()->firstOrFail(), 'enumerate.member_revoked', $by->account()->firstOrFail(), [
            'member_id' => $seat->id,
        ]);
    }

    private function assertTeam(EnumerateMember $by): void
    {
        if (! $by->may('team')) {
            throw new RuntimeException('Only an admin of the organisation manages its team.');
        }
    }

    private function assertSameOrganisation(EnumerateMember $by, EnumerateMember $seat): void
    {
        if ($by->organisation_id !== $seat->organisation_id) {
            throw new RuntimeException('That seat is in another organisation.');
        }
    }

    private function assertNotLastAdmin(EnumerateMember $seat): void
    {
        $admins = EnumerateMember::query()
            ->where('organisation_id', $seat->organisation_id)
            ->where('role', 'admin')
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->count();

        if ($admins <= 1) {
            throw new RuntimeException('An organisation always keeps one admin. Make somebody else admin first.');
        }
    }
}
