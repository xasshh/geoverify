<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Jobs;

use App\Domain\AreaCapture\Actions\ScoreAreaCapture;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Scores an area capture after it lands, off the request, like ScoreCapture. */
final class ScoreAreaRevision implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $revisionId) {}

    public function handle(ScoreAreaCapture $score): void
    {
        $revision = AreaFeatureRevision::query()->find($this->revisionId);

        if ($revision !== null) {
            $score($revision);
        }
    }
}
