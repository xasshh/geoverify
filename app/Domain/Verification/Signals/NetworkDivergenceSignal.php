<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * The GNSS position against the cell tower and wifi one.
 *
 * The cheapest way to catch a spoofed location, because most mock providers only
 * move the GNSS fix and leave the network position reporting where the handset
 * actually is. Honest disagreement of a few hundred metres is normal, since a
 * network position is derived from tower geometry. Kilometres is not.
 */
final class NetworkDivergenceSignal implements Signal
{
    /** A network fix is coarse. This much apart is ordinary. */
    private const ORDINARY_M = 500.0;

    /** Past this the two positions are not describing the same place. */
    private const IRRECONCILABLE_M = 2_000.0;

    public function key(): string
    {
        return 'network_divergence';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Presence;
    }

    public function weight(): int
    {
        return 10;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->networkComparableFixCount === 0 || $facts->worstNetworkDivergenceM === null) {
            return SignalResult::unknown('The device reported no network position to compare.');
        }

        $metres = $facts->worstNetworkDivergenceM;
        $evidence = [
            'worst_divergence_m' => round($metres, 1),
            'comparable_fixes' => $facts->networkComparableFixCount,
        ];

        if ($metres >= self::IRRECONCILABLE_M) {
            return SignalResult::fail(
                'The network position is '.$this->readable($metres).' from the satellite position.',
                $evidence,
            );
        }

        if ($metres > self::ORDINARY_M) {
            return SignalResult::warn(
                'The network position sits '.$this->readable($metres).' from the satellite position.',
                $evidence,
            );
        }

        return SignalResult::ok('The network and satellite positions agree.', $evidence);
    }

    private function readable(float $metres): string
    {
        return $metres >= 1_000.0
            ? round($metres / 1_000.0, 1).' km'
            : round($metres).' m';
    }
}
