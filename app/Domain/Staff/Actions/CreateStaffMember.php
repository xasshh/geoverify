<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An admin adds somebody to the staff.
 *
 * The only way in. Fortify's registration is off and stays off: officers,
 * supervisors and admins exist because an administrator created them, and the
 * `users` table is never widened to admit a member of the public.
 *
 * The first password is generated rather than chosen, and returned once to the
 * person doing the creating so they can hand it over. Letting an admin set a
 * colleague's password invites one memorable password across a field team.
 */
final class CreateStaffMember
{
    /** @return array{user: User, password: string} */
    public function __invoke(
        User $admin,
        string $name,
        string $email,
        Role $role,
        ?string $phone = null,
    ): array {
        if (! $admin->administers()) {
            throw new RuntimeException('Only an administrator can add a staff member.');
        }

        $email = mb_strtolower(trim($email));

        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException('Somebody already works here under that address.');
        }

        return DB::transaction(function () use ($admin, $name, $email, $role, $phone): array {
            $password = Str::password(14, symbols: false);

            $user = User::query()->create([
                'name' => trim($name),
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'status' => User::STATUS_ACTIVE,
                'staff_ref' => $this->nextStaffRef($role),
                'phone' => $phone,
            ]);

            VerificationEvent::record($user, 'staff.created', $admin, [
                'role' => $role->value,
                'staff_ref' => $user->staff_ref,
            ]);

            return ['user' => $user, 'password' => $password];
        });
    }

    /**
     * The next reference in this role's series.
     *
     * Officers run from 1042 because the field team was numbered before this
     * system existed and the references are on lanyards. Continuing the series
     * beats renumbering people who already know their own number.
     */
    private function nextStaffRef(Role $role): string
    {
        [$prefix, $start] = match ($role) {
            Role::Officer => ['FO', 1042],
            Role::Supervisor => ['SUP', 1],
            Role::Admin => ['ADM', 1],
        };

        $highest = DB::scalar(
            "select max(substring(staff_ref from '[0-9]+$')::int) from users where staff_ref like ?",
            [$prefix.'-%'],
        );

        $next = $highest === null ? $start : ((int) $highest) + 1;

        return $role === Role::Officer
            ? sprintf('%s-%d', $prefix, $next)
            : sprintf('%s-%03d', $prefix, $next);
    }
}
