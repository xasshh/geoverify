<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;

/**
 * The device's own answer to "is a mock location provider running".
 *
 * The heaviest signal here, and the only one that fails on a single occurrence.
 * A mock provider is free, installs in thirty seconds, and there is no innocent
 * reason for one to be active on a working enumeration handset.
 */
final class MockLocationSignal implements Signal
{
    public function key(): string
    {
        return 'mock_location';
    }

    public function question(): ReviewQuestion
    {
        return ReviewQuestion::Presence;
    }

    public function weight(): int
    {
        return 20;
    }

    public function evaluate(CaptureFacts $facts): SignalResult
    {
        if ($facts->fixCount === 0) {
            return SignalResult::unknown('No fixes were recorded for this capture.');
        }

        if ($facts->mockFixCount > 0) {
            return SignalResult::fail(
                $facts->mockFixCount === 1
                    ? 'A mock location provider was active on 1 fix.'
                    : "A mock location provider was active on {$facts->mockFixCount} fixes.",
                ['mock_fixes' => $facts->mockFixCount, 'fixes' => $facts->fixCount],
            );
        }

        return SignalResult::ok('No mock location provider was reported.');
    }
}
