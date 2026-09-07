<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationOrder;

/**
 * Paid visits nobody has been sent on yet.
 *
 * The sidebar number that costs us money if it is ignored. Deliberately counts
 * only orders with no officer against them: an assigned visit running late is a
 * different problem with a different answer, and folding the two into one badge
 * would make the badge unactionable.
 */
final class CountOrdersAwaitingAssignment
{
    public function __invoke(): int
    {
        return VerificationOrder::query()
            ->where('status', OrderStatus::Paid->value)
            ->count();
    }
}
