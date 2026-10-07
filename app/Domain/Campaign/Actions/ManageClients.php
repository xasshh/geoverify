<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The commissioning bodies, and the people at them who read their campaigns.
 *
 * Kept by administrators only. A client does not self register: who may read a
 * government ministry's campaign is decided here, not by whoever finds the
 * sign-in page.
 *
 * Three rules:
 *
 *  - A short code is fixed once a campaign carries it. It is printed in the
 *    campaign code (TEST-LAN-2026-01), on invoices and on paper briefs, so
 *    changing it later would leave every one of those pointing at nothing.
 *  - Nothing is deleted. An organisation or a login is suspended, which the
 *    client guard already refuses (ClientUser::canSignIn).
 *  - A login's first password is generated, as for staff, and shown once to
 *    the administrator who hands it over. So is a reset.
 */
final class ManageClients
{
    /**
     * @param  array{name: string, short_code: string, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null}  $data
     */
    public function createOrganisation(User $admin, array $data): ClientOrganisation
    {
        $this->guard($admin);
        $code = $this->shortCode($data['short_code']);

        if (ClientOrganisation::query()->where('short_code', $code)->exists()) {
            throw ValidationException::withMessages(['short_code' => "Another client already uses {$code}."]);
        }

        return DB::transaction(function () use ($admin, $data, $code): ClientOrganisation {
            $organisation = ClientOrganisation::query()->create([
                'name' => trim($data['name']),
                'short_code' => $code,
                'contact_name' => self::blank($data['contact_name'] ?? null),
                'contact_email' => self::email($data['contact_email'] ?? null),
                'contact_phone' => self::blank($data['contact_phone'] ?? null),
                'status' => ClientOrganisation::STATUS_ACTIVE,
            ]);

            VerificationEvent::record($organisation, 'client.created', $admin, ['short_code' => $code]);

            return $organisation;
        });
    }

    /**
     * @param  array{name: string, short_code: string, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null}  $data
     */
    public function updateOrganisation(User $admin, ClientOrganisation $organisation, array $data): ClientOrganisation
    {
        $this->guard($admin);
        $code = $this->shortCode($data['short_code']);

        if ($code !== $organisation->short_code) {
            if ($organisation->campaigns()->exists()) {
                throw ValidationException::withMessages([
                    'short_code' => "{$organisation->short_code} is printed in this client's campaign codes, so it cannot change.",
                ]);
            }

            if (ClientOrganisation::query()->where('short_code', $code)->whereKeyNot($organisation->id)->exists()) {
                throw ValidationException::withMessages(['short_code' => "Another client already uses {$code}."]);
            }
        }

        return DB::transaction(function () use ($admin, $organisation, $data, $code): ClientOrganisation {
            $organisation->fill([
                'name' => trim($data['name']),
                'short_code' => $code,
                'contact_name' => self::blank($data['contact_name'] ?? null),
                'contact_email' => self::email($data['contact_email'] ?? null),
                'contact_phone' => self::blank($data['contact_phone'] ?? null),
            ]);

            $changed = array_keys($organisation->getDirty());
            $organisation->save();

            if ($changed !== []) {
                VerificationEvent::record($organisation, 'client.updated', $admin, ['changed' => $changed]);
            }

            return $organisation;
        });
    }

    public function setOrganisationStatus(User $admin, ClientOrganisation $organisation, string $status, ?string $reason): void
    {
        $this->guard($admin);
        $this->status($status);

        if ($organisation->status === $status) {
            return;
        }

        DB::transaction(function () use ($admin, $organisation, $status, $reason): void {
            $from = $organisation->status;
            $organisation->update(['status' => $status]);

            VerificationEvent::record($organisation, 'client.'.$status, $admin, array_filter([
                'from' => $from,
                'reason' => self::blank($reason),
            ], static fn (mixed $v): bool => $v !== null));
        });
    }

    /** @return array{user: ClientUser, password: string} */
    public function addUser(User $admin, ClientOrganisation $organisation, string $name, string $email): array
    {
        $this->guard($admin);
        $email = mb_strtolower(trim($email));

        if (ClientUser::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'A client login already uses that address.']);
        }

        return DB::transaction(function () use ($admin, $organisation, $name, $email): array {
            $password = Str::password(14, symbols: false);

            $user = ClientUser::query()->create([
                'client_organisation_id' => $organisation->id,
                'name' => trim($name),
                'email' => $email,
                'password' => $password,
                'status' => ClientUser::STATUS_ACTIVE,
            ]);

            VerificationEvent::record($organisation, 'client.user_added', $admin, [
                'client_user_id' => $user->id,
                'email' => $email,
            ]);

            return ['user' => $user, 'password' => $password];
        });
    }

    public function setUserStatus(User $admin, ClientUser $user, string $status): void
    {
        $this->guard($admin);
        $this->status($status);

        if ($user->status === $status) {
            return;
        }

        DB::transaction(function () use ($admin, $user, $status): void {
            $user->update(['status' => $status]);

            if ($status === ClientUser::STATUS_SUSPENDED) {
                // Signed out everywhere: a suspended login with a live
                // remember-me cookie is not suspended.
                $user->forceFill(['remember_token' => Str::random(60)])->save();
            }

            VerificationEvent::record($user, 'client_user.'.$status, $admin);
        });
    }

    /** @return string the new password, shown once */
    public function resetPassword(User $admin, ClientUser $user): string
    {
        $this->guard($admin);

        return DB::transaction(function () use ($admin, $user): string {
            $password = Str::password(14, symbols: false);

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            VerificationEvent::record($user, 'client_user.password_reset', $admin);

            return $password;
        });
    }

    private function guard(User $admin): void
    {
        if (! $admin->administers()) {
            throw ValidationException::withMessages(['client' => 'Only an administrator manages clients.']);
        }
    }

    private function shortCode(string $code): string
    {
        $code = strtoupper(trim($code));

        // Upper case letters and digits, starting with a letter: it leads a
        // campaign code that is read down a phone and typed into invoices.
        if (preg_match('/^[A-Z][A-Z0-9]{1,7}$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'short_code' => 'A short code is 2 to 8 letters or digits, starting with a letter.',
            ]);
        }

        return $code;
    }

    private function status(string $status): void
    {
        if (! in_array($status, [ClientOrganisation::STATUS_ACTIVE, ClientOrganisation::STATUS_SUSPENDED], true)) {
            throw ValidationException::withMessages(['status' => 'Active or suspended.']);
        }
    }

    private static function blank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private static function email(?string $value): ?string
    {
        $value = self::blank($value);

        return $value === null ? null : mb_strtolower($value);
    }
}
