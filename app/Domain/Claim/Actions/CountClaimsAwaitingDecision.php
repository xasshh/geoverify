<?php

declare(strict_types=1);

namespace App\Domain\Claim\Actions;

use App\Domain\Claim\Enums\ClaimStatus;
use Illuminate\Support\Facades\DB;

/**
 * How many claims and disputes are waiting on a person.
 *
 * Counted together because they land on one screen and a supervisor deciding
 * what to open next does not care which of the two is which until they get
 * there. Which one leads is BuildClaimQueue's business, and it puts disputes
 * first: an ordinary claim is somebody waiting, a dispute is somebody locked out
 * of their own business.
 */
final class CountClaimsAwaitingDecision
{
    public function __invoke(): int
    {
        $claims = (int) DB::scalar(
            'select count(*) from claims where status = ?',
            [ClaimStatus::Submitted->value],
        );

        $disputes = (int) DB::scalar(
            'select count(*) from claim_disputes where resolution is null',
        );

        return $claims + $disputes;
    }
}
