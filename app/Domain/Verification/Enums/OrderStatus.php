<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

/**
 * Where a verification order stands.
 *
 * Deliberately says nothing about what was found. A visit that attends and
 * discovers the business is not at that address reaches `completed` like any
 * other: the work was performed and the report is delivered, and it is
 * billable. What was found lives in `outcome`, and collapsing the two would
 * make an honest negative look like a failure to deliver.
 */
enum OrderStatus: string
{
    /** Placed, not yet paid. Nothing is held and nobody is deployed. */
    case AwaitingPayment = 'awaiting_payment';

    /** The money is with us and the clock has started. */
    case Paid = 'paid';

    /** An officer has been given the visit. */
    case Assigned = 'assigned';

    case InProgress = 'in_progress';

    /** The officer has filed it; a supervisor has not yet accepted. */
    case Submitted = 'submitted';

    /** Accepted. The work is done and the fee is ours. */
    case Completed = 'completed';

    /** Withdrawn before payment. Nothing was held, so nothing is returned. */
    case Cancelled = 'cancelled';

    /** The money went back, whether for an SLA breach or a withdrawal. */
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::Paid => 'Paid, waiting for an officer',
            self::Assigned => 'Officer assigned',
            self::InProgress => 'Visit under way',
            self::Submitted => 'Filed, in review',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    /** Whether we are holding the customer's money right now. */
    public function holdsFunds(): bool
    {
        return match ($this) {
            self::Paid, self::Assigned, self::InProgress, self::Submitted => true,
            default => false,
        };
    }

    public function isSettled(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Refunded => true,
            default => false,
        };
    }

    /**
     * The moves allowed out of this state.
     *
     * @return list<self>
     */
    public function allows(): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Assigned, self::Refunded],
            self::Assigned => [self::InProgress, self::Paid, self::Refunded],
            self::InProgress => [self::Submitted, self::Refunded],
            // A supervisor returning the officer's work sends it back to the
            // visit, not back to the queue: the order stays assigned and the
            // officer goes again.
            self::Submitted => [self::Completed, self::InProgress, self::Refunded],
            self::Completed, self::Cancelled, self::Refunded => [],
        };
    }

    public function allowsMoveTo(self $next): bool
    {
        return in_array($next, $this->allows(), true);
    }
}
