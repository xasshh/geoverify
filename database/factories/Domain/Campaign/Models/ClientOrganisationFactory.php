<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Models\ClientOrganisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ClientOrganisation> */
final class ClientOrganisationFactory extends Factory
{
    protected $model = ClientOrganisation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'short_code' => Str::upper(Str::random(4)),
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->safeEmail(),
            'contact_phone' => '+23480'.$this->faker->numerify('########'),
            'status' => ClientOrganisation::STATUS_ACTIVE,
        ];
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => ClientOrganisation::STATUS_SUSPENDED]);
    }
}
