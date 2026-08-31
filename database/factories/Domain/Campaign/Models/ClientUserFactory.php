<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientUser> */
final class ClientUserFactory extends Factory
{
    protected $model = ClientUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_organisation_id' => ClientOrganisation::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'password',
            'status' => ClientUser::STATUS_ACTIVE,
        ];
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => ClientUser::STATUS_SUSPENDED]);
    }
}
