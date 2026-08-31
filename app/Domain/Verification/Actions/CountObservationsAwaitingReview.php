<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use Illuminate\Support\Facades\DB;

/**
 * How many captures are waiting on a decision.
 *
 * A count rather than a call into BuildReviewQueue, which assembles traces,
 * signals and photograph counts for every row. The sidebar needs one number on
 * every console page, and building the whole queue to discard all but its length
 * would put the most expensive read in the console behind every navigation.
 */
final class CountObservationsAwaitingReview
{
    public function __invoke(): int
    {
        return (int) DB::scalar(
            'select count(*) from structure_observations where status = ?',
            [Structure::STATUS_SUBMITTED],
        );
    }
}
