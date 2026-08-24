<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\ScoreObservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores a capture after it lands, off the request.
 *
 * Deliberately queued. Scoring is five queries over a session's fixes, and a
 * sync batch carries two hundred mutations: doing this inline would put the
 * whole of it on the officer's connection, which is the one thing the field
 * client is built never to depend on.
 *
 * Queued also means a capture that arrives before its fixes do gets scored
 * against whatever exists when the job runs, and rescoring later is safe: the
 * signal readings are replaced, and every scoring appends to verification_events.
 */
final class ScoreCapture implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $observationId) {}

    public function handle(ScoreObservation $score): void
    {
        $observation = StructureObservation::query()->find($this->observationId);

        if ($observation === null) {
            return;
        }

        $score($observation);
    }

    /** One pending scoring per capture is enough. */
    public function uniqueId(): string
    {
        return (string) $this->observationId;
    }
}
