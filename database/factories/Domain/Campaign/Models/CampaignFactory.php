<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientOrganisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Campaign> */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $starts = $this->faker->dateTimeBetween('-3 months', '+1 month');

        return [
            'client_organisation_id' => ClientOrganisation::factory(),
            'code' => Str::upper(Str::random(3)).'-'.Str::upper(Str::random(3)).'-2026-'.$this->faker->unique()->numerify('##'),
            'name' => 'Enumeration of '.$this->faker->word(),
            'subject_type' => $this->faker->randomElement([
                'Mining companies', 'Private clinics', 'Hair extension traders', 'Landlords',
            ]),
            'about' => $this->faker->paragraphs(3, true),
            'objective' => $this->faker->sentence(12),
            'status' => CampaignStatus::Draft,
            'starts_on' => $starts,
            'ends_on' => (clone $starts)->modify('+90 days'),
            'target_record_count' => $this->faker->numberBetween(500, 20_000),
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::Active,
            'approved_at' => now()->subWeeks(2),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::Completed,
            'approved_at' => now()->subMonths(6),
        ]);
    }

    public function forClient(ClientOrganisation $client): self
    {
        return $this->state(fn (): array => ['client_organisation_id' => $client->id]);
    }
}
