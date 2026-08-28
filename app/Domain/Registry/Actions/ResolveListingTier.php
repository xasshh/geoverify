<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Models\Structure;

/**
 * What a listing has actually established, and on what evidence.
 *
 * One place, because two screens disagreeing about a business's tier is worse
 * than either being wrong: the tier is the product, and a search result that
 * says "location verified" over a listing page that says "listed" destroys the
 * only thing the register is selling.
 *
 * The rule is short and refuses to be generous. An officer standing at the door
 * with a GPS fix is location_verified, which is exactly what that tier means. A
 * business that typed its own address is listed, and nothing more, however
 * complete the form was and however honest the person filling it in. Buying the
 * next rung requires a visit, which is the load bearing rule of the whole
 * platform: verification cannot be bought without one.
 */
final class ResolveListingTier
{
    /** The highest tier this listing has established. */
    public function forOrigin(string $origin, string $status): string
    {
        if ($origin === Structure::ORIGIN_SELF_REGISTERED) {
            return 'listed';
        }

        // A rejected capture establishes nothing. It should not be reachable
        // here at all, and if it is, saying "listed" is the honest answer
        // rather than crediting work a supervisor threw out.
        return $status === Structure::STATUS_REJECTED ? 'listed' : 'location_verified';
    }

    /**
     * The same answer, said the way the ladder component wants it.
     *
     * @return list<array{tier: string, state: string, establishedOn?: string}>
     */
    public function rungs(string $origin, string $status, string $establishedOn): array
    {
        $reached = $this->forOrigin($origin, $status);

        $rungs = [
            ['tier' => 'listed', 'state' => 'current', 'establishedOn' => $establishedOn],
            ['tier' => 'identity_verified', 'state' => 'not_established'],
            ['tier' => 'location_verified', 'state' => 'not_established'],
            ['tier' => 'operations_verified', 'state' => 'not_established'],
            ['tier' => 'monitored', 'state' => 'not_established'],
        ];

        if ($reached === 'location_verified') {
            $rungs[2] = [
                'tier' => 'location_verified',
                'state' => 'current',
                'establishedOn' => $establishedOn,
            ];
        }

        return $rungs;
    }
}
