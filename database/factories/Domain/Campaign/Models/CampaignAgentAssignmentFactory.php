<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\DeploymentStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignAgentAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CampaignAgentAssignment> */
final class CampaignAgentAssignmentFactory extends Factory
{
    protected $model = CampaignAgentAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => User::factory(),
            'coverage_area_id' => null,
            'assigned_at' => now()->subWeeks(2),
            'unassigned_at' => null,
            'status' => DeploymentStatus::Active,
        ];
    }

    public function stoodDown(): self
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::StoodDown,
            'unassigned_at' => now()->subDays(3),
        ]);
    }
}
