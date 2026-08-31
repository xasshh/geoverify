<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use Illuminate\Support\Facades\DB;

/**
 * How many captures are waiting on an administrator.
 *
 * Its own count rather than part of the review number. A supervisor's queue and
 * an admin's are different work for different people, and adding them together
 * would tell a supervisor that captures they cannot act on are theirs to clear.
 */
final class CountEscalations
{
    public function __invoke(): int
    {
        return (int) DB::scalar(
            'select count(*) from structure_observations where status = ?',
            [Structure::STATUS_FLAGGED],
        );
    }
}
