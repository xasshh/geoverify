<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * Whether anything was overhead when the device claimed a satellite position.
 *
 * A GNSS fix computed from zero satellites did not come from satellites. Devices
 * that report the count at all are the ones worth asking, and the ones that do
 * not are simply not asked: an older handset is not evidence of anything.
 */
final class SatelliteVisibilitySignal implements Signal
{
    public function key(): string
    {
        return 'satellite_visibility';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Presence;
    }

    public function weight(): int
    {
        return 5;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->satelliteAwareFixCount === 0) {
            return SignalResult::unknown('This device does not report a satellite count.');
        }

        $evidence = [
            'zero_satellite_fixes' => $facts->zeroSatelliteFixCount,
            'reporting_fixes' => $facts->satelliteAwareFixCount,
        ];

        if ($facts->zeroSatelliteFixCount === $facts->satelliteAwareFixCount) {
            return SignalResult::fail(
                'Every fix claimed a position with no satellites in view.',
                $evidence,
            );
        }

        if ($facts->zeroSatelliteFixCount > 0) {
            return SignalResult::warn(
                sprintf(
                    '%d of %d fixes reported no satellites in view.',
                    $facts->zeroSatelliteFixCount,
                    $facts->satelliteAwareFixCount,
                ),
                $evidence,
            );
        }

        return SignalResult::ok('Satellites were in view throughout.', $evidence);
    }
}
