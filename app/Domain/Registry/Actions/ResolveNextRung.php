<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Actions\ResolveServiceZone;
use App\Domain\Verification\Actions\ResolveVerificationPrice;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Models\VerificationOrder;
use RuntimeException;

/**
 * What this listing could establish next, and what that costs today.
 *
 * Extracted from ListingController when the dashboard needed the same answer.
 * Two screens computing a price independently is how a business is quoted one
 * figure on the dashboard and a different one when they click it, which is the
 * fastest way to lose somebody who was about to pay.
 *
 * Priced here rather than on the money screen so the offer wherever it appears
 * is the offer on the order page.
 *
 * Null when something is already in flight for that tier, which the unique
 * index would refuse anyway. Better to not offer it than to offer it and then
 * explain.
 */
final class ResolveNextRung
{
    public function __construct(
        private readonly ResolveListingTier $tiers,
        private readonly ResolveVerificationPrice $prices,
        private readonly ResolveServiceZone $zones,
    ) {}

    /**
     * @return array{tier: string, label: string, feeNaira: int, within: string}|null
     */
    public function __invoke(Enterprise $enterprise): ?array
    {
        $established = $this->tiers->forOrigin(
            (string) $enterprise->structure->origin,
            (string) $enterprise->structure->status,
        );

        $next = $established === 'location_verified'
            ? 'operations_verified'
            : 'location_verified';

        $live = VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('tier', $next)
            ->get()
            ->contains(static fn (VerificationOrder $o): bool => ! $o->status->isSettled());

        if ($live) {
            return null;
        }

        try {
            $price = ($this->prices)(
                $next,
                OrderUrgency::Standard,
                ($this->zones)($enterprise->structure),
            );
        } catch (RuntimeException) {
            // No live price for that rung means we are not selling it today.
            // Saying nothing is the honest surface for that.
            return null;
        }

        return [
            'tier' => $next,
            'label' => ucfirst(str_replace('_', ' ', $next)),
            'feeNaira' => (int) round($price->amount_minor / 100),
            'within' => "{$price->sla_working_days} working days",
        ];
    }
}
