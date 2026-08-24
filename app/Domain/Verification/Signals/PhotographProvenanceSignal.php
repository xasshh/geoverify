<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * Whether the photographs were taken here, now, on this handset.
 *
 * Three positions are stored per photograph and kept apart on purpose: where the
 * EXIF says the camera was, where the device claimed to be, and where the subject
 * stands. Agreement is evidence. Disagreement is the finding.
 *
 * A file carrying no camera metadata at all is what a screenshot looks like, and
 * what an image saved from a message looks like.
 */
final class PhotographProvenanceSignal implements Signal
{
    /** A facade is photographed from across a street, not from down the road. */
    private const TOO_FAR_M = 60.0;

    public function key(): string
    {
        return 'photograph_provenance';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Identification;
    }

    public function weight(): int
    {
        return 12;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->photographCount === 0) {
            return SignalResult::fail('No photograph was attached to this capture.', [
                'photographs' => 0,
            ]);
        }

        $evidence = [
            'photographs' => $facts->photographCount,
            'from_device_camera' => $facts->photographsFromDeviceCamera,
            'without_provenance' => $facts->photographsWithoutProvenance,
            'worst_distance_m' => $facts->worstPhotographDistanceM === null
                ? null
                : round($facts->worstPhotographDistanceM, 1),
        ];

        if ($facts->photographsWithoutProvenance === $facts->photographCount) {
            return SignalResult::fail(
                $facts->photographCount === 1
                    ? 'The photograph carries no camera metadata.'
                    : 'None of the photographs carry camera metadata.',
                $evidence,
            );
        }

        if ($facts->photographsWithoutProvenance > 0) {
            return SignalResult::warn(
                sprintf(
                    '%d of %d photographs carry no camera metadata.',
                    $facts->photographsWithoutProvenance,
                    $facts->photographCount,
                ),
                $evidence,
            );
        }

        if ($facts->worstPhotographDistanceM !== null
            && $facts->worstPhotographDistanceM > self::TOO_FAR_M) {
            return SignalResult::warn(
                'A photograph was taken '.round($facts->worstPhotographDistanceM).' m from its subject.',
                $evidence,
            );
        }

        return SignalResult::ok('Photographs came from the device camera, at the subject.', $evidence);
    }
}
