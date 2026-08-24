<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * How fast the officer must have been moving between fixes.
 *
 * Computed from the distance and the time actually recorded, not from the speed
 * the device reported, because a spoofed fix can report any speed it likes but
 * cannot make two positions and two timestamps agree with each other.
 *
 * An officer in a vehicle between clusters of work is ordinary and reads high
 * here, so vehicle speed is a flag rather than a failure. Faster than a vehicle
 * is not a journey at all.
 */
final class ImpliedSpeedSignal implements Signal
{
    /** A brisk walk with a phone and a clipboard. */
    private const WALKING_MPS = 2.5;

    /** Plausible in a vehicle on Abuja roads. */
    private const VEHICLE_MPS = 30.0;

    public function key(): string
    {
        return 'implied_speed';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Plausibility;
    }

    public function weight(): int
    {
        return 10;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->fastestImpliedSpeedMps === null) {
            return SignalResult::unknown('Not enough fixes to imply a speed.');
        }

        $speed = $facts->fastestImpliedSpeedMps;
        $evidence = [
            'fastest_implied_mps' => round($speed, 2),
            'fastest_implied_kmh' => round($speed * 3.6, 1),
        ];

        if ($speed > self::VEHICLE_MPS) {
            return SignalResult::fail(
                'The fixes imply '.round($speed * 3.6).' km/h between two points.',
                $evidence,
            );
        }

        if ($speed > self::WALKING_MPS) {
            return SignalResult::warn(
                'The fixes imply '.round($speed * 3.6).' km/h, so part of this was travelled.',
                $evidence,
            );
        }

        return SignalResult::ok('Movement between fixes stayed at walking pace.', $evidence);
    }
}
