<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\EngagementStatus;
use App\Domain\Campaign\Enums\StakeholderCategory;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignStakeholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CampaignStakeholder> */
final class CampaignStakeholderFactory extends Factory
{
    protected $model = CampaignStakeholder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'name' => $this->faker->name(),
            'category' => $this->faker->randomElement(StakeholderCategory::cases()),
            'organisation' => $this->faker->company(),
            'role_title' => $this->faker->jobTitle(),
            'contact_person' => $this->faker->name(),
            'phone' => '+23480'.$this->faker->numerify('########'),
            'email' => $this->faker->safeEmail(),
            'engagement_status' => EngagementStatus::Identified,
            'notes' => null,
            'visible_to_client' => true,
        ];
    }

    /** A contact the client is deliberately not shown. */
    public function internal(): self
    {
        return $this->state(fn (): array => ['visible_to_client' => false]);
    }
}
