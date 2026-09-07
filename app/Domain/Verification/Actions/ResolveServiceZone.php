<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Enums\ServiceZone;
use Illuminate\Support\Facades\DB;

/**
 * Which zone a building sits in, decided by the register rather than by a form.
 *
 * Zone A is ground we already work: a ward where officers have been and left
 * accepted captures behind them, so a visit there is a detour on a round
 * somebody is making anyway. Zone B is everywhere else in a covered LGA, where
 * the visit is a dedicated trip and costs half as much again.
 *
 * Resolved server side from the structure's own ward, never client supplied.
 * The whole platform's answer to "where is this" is ST_Contains against
 * admin_boundaries at ingestion, and a price that could be moved by a browser
 * would be the one place that rule did not hold.
 *
 * A structure whose ward never resolved is Zone B. Not knowing where somebody
 * is is not a reason to charge them less for a trip we cannot plan.
 */
final class ResolveServiceZone
{
    /** How many accepted captures make a ward worth calling serviced. */
    private const SERVICED_THRESHOLD = 5;

    public function __invoke(Structure $structure): ServiceZone
    {
        if ($structure->ward_id === null) {
            return ServiceZone::B;
        }

        $accepted = (int) DB::scalar(
            'select count(*) from structures where ward_id = ? and status = ?',
            [$structure->ward_id, Structure::STATUS_ACCEPTED],
        );

        return $accepted >= self::SERVICED_THRESHOLD ? ServiceZone::A : ServiceZone::B;
    }
}
