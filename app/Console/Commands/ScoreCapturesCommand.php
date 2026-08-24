<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\ScoreObservation;
use Illuminate\Console\Command;

/**
 * Scores captures that have none, or rescores everything after a signal changes.
 *
 * Captures are scored on arrival by a queued job. This exists for the two cases
 * that leaves: a backlog that predates the scorer, and the day a signal's
 * threshold is corrected and every open capture has to be read again with it.
 */
final class ScoreCapturesCommand extends Command
{
    protected $signature = 'geoverify:score
        {--all : Rescore every submitted capture, not only the unscored ones}
        {--limit=1000 : How many to work through in one run}';

    protected $description = 'Score submitted captures for confidence';

    public function handle(ScoreObservation $score): int
    {
        $query = StructureObservation::query()
            ->where('status', Structure::STATUS_SUBMITTED)
            ->orderBy('observed_at');

        if ($this->option('all') !== true) {
            $query->whereNull('confidence_score');
        }

        $observations = $query->limit((int) $this->option('limit'))->get();

        if ($observations->isEmpty()) {
            $this->info('Nothing to score.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($observations->count());
        $scores = [];

        foreach ($observations as $observation) {
            $scores[] = $score($observation);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        sort($scores);
        $middle = $scores[intdiv(count($scores), 2)];

        $this->info(sprintf(
            'Scored %d captures. Worst %d, median %d, best %d.',
            count($scores),
            $scores[0],
            $middle,
            $scores[count($scores) - 1],
        ));

        return self::SUCCESS;
    }
}
