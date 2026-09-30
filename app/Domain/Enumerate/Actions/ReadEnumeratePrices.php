<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumeratePrice;
use RuntimeException;

/**
 * Today's price list: one live row per tier, and per period for Tier 3.
 */
final class ReadEnumeratePrices
{
    public function priceMinor(Tier $tier, ?int $days): int
    {
        $price = EnumeratePrice::query()
            ->where('tier', $tier->value)
            ->whereNull('effective_to')
            ->when($tier === Tier::Activity, fn ($q) => $q->where('days', $days), fn ($q) => $q->whereNull('days'))
            ->value('amount_minor');

        if ($price === null) {
            throw new RuntimeException('That check is not on sale right now.');
        }

        return (int) $price;
    }

    /**
     * For the tier cards: Tier 1 and 2 by tier, Tier 3 by period.
     *
     * @return array{tier1: int, tier2: int, tier3: array<int, int>}
     */
    public function list(): array
    {
        $rows = EnumeratePrice::query()->whereNull('effective_to')->get();
        $tier3 = [];

        foreach ($rows->where('tier', 3) as $row) {
            $tier3[(int) $row->days] = $row->amount_minor;
        }

        ksort($tier3);

        return [
            'tier1' => (int) $rows->where('tier', 1)->value('amount_minor'),
            'tier2' => (int) $rows->where('tier', 2)->value('amount_minor'),
            'tier3' => $tier3,
        ];
    }
}
