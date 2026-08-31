<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\PaymentStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignCommercial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CampaignCommercial> */
final class CampaignCommercialFactory extends Factory
{
    protected $model = CampaignCommercial::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'contract_value' => $this->faker->numberBetween(2_000_000, 80_000_000),
            'currency' => 'NGN',
            'payment_status' => PaymentStatus::Unpaid,
            'internal_notes' => $this->faker->sentence(14),
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now()->subWeeks(3),
        ]);
    }
}
