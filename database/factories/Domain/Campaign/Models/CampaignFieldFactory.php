<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\CampaignFieldType;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CampaignField> */
final class CampaignFieldFactory extends Factory
{
    protected $model = CampaignField::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = Str::title($this->faker->unique()->words(2, true));

        return [
            'campaign_id' => Campaign::factory(),
            'label' => $label,
            'key' => Str::snake($label),
            'type' => CampaignFieldType::Text,
            'options' => null,
            'is_required' => $this->faker->boolean(60),
            'sort_order' => $this->faker->numberBetween(0, 40),
            'help_text' => null,
        ];
    }
}
