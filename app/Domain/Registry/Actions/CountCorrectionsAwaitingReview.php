<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Enums\CorrectionStatus;
use Illuminate\Support\Facades\DB;

/**
 * How many corrections are waiting on a supervisor.
 *
 * A count rather than a call into BuildCorrectionQueue, which joins four tables
 * and counts each party's history. The sidebar needs one number on every console
 * page.
 */
final class CountCorrectionsAwaitingReview
{
    public function __invoke(): int
    {
        return (int) DB::scalar(
            'select count(*) from correction_proposals where status = ?',
            [CorrectionStatus::Submitted->value],
        );
    }
}
