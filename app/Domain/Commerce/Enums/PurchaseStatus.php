<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * Where a product order has got to, and what may happen to it next.
 *
 * The labels are the mockup's pills with the regulated word taken out: "Held"
 * stands where it said something else, in the same colour and the same place.
 */
enum PurchaseStatus: string
{
    /** Placed, and the provider has not told us it was paid. Nothing is owed. */
    case AwaitingPayment = 'awaiting_payment';

    /** Paid and held. The merchant packs and sends. */
    case Held = 'held';

    /** On its way. Still held, until the buyer confirms or the window closes. */
    case Dispatched = 'dispatched';

    /** The merchant has been paid into their balance. */
    case Released = 'released';

    /** The buyer raised an issue. Held until an admin rules. */
    case Disputed = 'disputed';

    /** Returned to the buyer on a ruling. */
    case Refunded = 'refunded';

    /** Abandoned before any money arrived. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::Held => 'Held',
            self::Dispatched => 'Dispatched',
            self::Released => 'Released',
            self::Disputed => 'Buyer raised issue',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether the buyer's money is sitting in BUYER_FUNDS_HELD for this order. */
    public function holdsFunds(): bool
    {
        return in_array($this, [self::Held, self::Dispatched, self::Disputed], true);
    }

    /** @return list<self> */
    public function allows(): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::Held, self::Cancelled],
            // A buyer holding the goods can confirm before the merchant has
            // remembered to mark them sent. The buyer's word is the one that
            // releases money, so it is not made to wait on the merchant's.
            self::Held => [self::Dispatched, self::Released, self::Disputed],
            self::Dispatched => [self::Released, self::Disputed],
            // A ruling goes one way or the other, and that is the end of it.
            self::Disputed => [self::Released, self::Refunded],
            self::Released, self::Refunded, self::Cancelled => [],
        };
    }

    public function allowsMoveTo(self $next): bool
    {
        return in_array($next, $this->allows(), true);
    }
}
