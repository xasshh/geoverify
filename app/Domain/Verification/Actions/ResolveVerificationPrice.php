<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use App\Domain\Verification\Models\VerificationPrice;
use RuntimeException;

/**
 * What this order costs, today, for this ground.
 *
 * Read once at creation and stamped onto the order. Nothing later joins back to
 * this table: a price change next quarter must not rewrite the history of what
 * somebody was charged, and the only way to guarantee that is for the order to
 * stop depending on the price row the moment it is placed.
 *
 * Refuses rather than guesses. A tier with no live price is a tier we have not
 * decided how to sell, and inventing a number at the till is worse than saying
 * so.
 */
final class ResolveVerificationPrice
{
    public function __invoke(
        string $tier,
        OrderUrgency $urgency,
        ServiceZone $zone,
    ): VerificationPrice {
        $price = VerificationPrice::query()
            ->live()
            ->where('tier', $tier)
            ->where('urgency', $urgency->value)
            ->where('zone', $zone->value)
            ->first();

        if ($price instanceof VerificationPrice) {
            return $price;
        }

        // Express is a scheduling concession, so a tier sold only at standard
        // pace is a real configuration rather than a gap. Said plainly, because
        // the alternative is a customer being quoted the standard price for
        // something we cannot expedite.
        throw new RuntimeException(sprintf(
            'There is no %s price for %s in zone %s.',
            $urgency->value,
            str_replace('_', ' ', $tier),
            $zone->value,
        ));
    }
}
