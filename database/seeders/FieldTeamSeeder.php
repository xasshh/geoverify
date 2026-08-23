<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A working team for local development.
 *
 * Passwords are fixed and obvious on purpose: this seeder is for a developer's
 * machine, and it refuses to run anywhere else.
 */
final class FieldTeamSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->error('FieldTeamSeeder is for local development only.');

            return;
        }

        $people = [
            ['Adaeze Nwosu', 'supervisor@geoverify.test', Role::Supervisor, 'SUP-001'],
            ['Ibrahim Danjuma', 'admin@geoverify.test', Role::Admin, 'ADM-001'],
            ['A. Bello', 'bello@geoverify.test', Role::Officer, 'FO-1042'],
            ['C. Okafor', 'okafor@geoverify.test', Role::Officer, 'FO-1043'],
            ['H. Suleiman', 'suleiman@geoverify.test', Role::Officer, 'FO-1044'],
            ['M. Adeyemi', 'adeyemi@geoverify.test', Role::Officer, 'FO-1045'],
        ];

        foreach ($people as [$name, $email, $role, $staffRef]) {
            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'staff_ref' => $staffRef,
                    'status' => User::STATUS_ACTIVE,
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                ],
            );
        }

        $this->command?->info('Seeded '.count($people).' people. Password for all of them: '.self::PASSWORD);
    }
}
