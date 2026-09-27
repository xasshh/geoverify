<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * "Add a verification service" at checkout.
 *
 * Every order is held until delivery whatever is chosen here; these add a field
 * agent. The agent's side of both is M3, and until it exists neither can be
 * bought: an order waiting on an inspection nobody can carry out would hold a
 * buyer's money against nothing.
 */
enum Protection: string
{
    case None = 'none';
    case Inspection = 'inspection';
    case SiteVisit = 'site_visit';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No extra service',
            self::Inspection => 'Product inspection',
            self::SiteVisit => 'Site visit',
        };
    }

    /** Kobo, or null while the price is undecided. */
    public function feeMinor(): ?int
    {
        $fee = match ($this) {
            self::None => 0,
            self::Inspection => config('geoverify.commerce.inspection_fee_minor'),
            self::SiteVisit => config('geoverify.commerce.visit_fee_minor'),
        };

        return is_int($fee) ? $fee : null;
    }

    /**
     * Whether a buyer can choose this today. Needs a price, and needs the
     * agents' side of it to exist, which it does not until M3.
     */
    public function isOffered(): bool
    {
        return $this === self::None;
    }
}
