<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enumerate\Actions\ManageMonitoring;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The morning run for Tier 3: yesterday's unfiled daily visits are marked
 * missed, and today's are handed to each monitoring officer.
 *
 * Idempotent, so a second run in the same morning opens nothing new. It does
 * not catch up on days it did not run: a day that was never scheduled was
 * never visited, and it counts as missed when monitoring closes, which is
 * what the requester is owed for it.
 */
final class EnumerateScheduleMonitoringCommand extends Command
{
    protected $signature = 'enumerate:schedule-monitoring {--date= : Run as if today were this date (YYYY-MM-DD)}';

    protected $description = 'Open today\'s Tier 3 daily visits and mark unfiled ones from earlier days as missed';

    public function handle(ManageMonitoring $monitoring): int
    {
        $date = $this->option('date');
        $today = is_string($date) && $date !== ''
            ? Carbon::parse($date, config('app.timezone'))
            : Carbon::now(config('app.timezone'));

        $result = $monitoring->schedule($today);

        $this->info(sprintf(
            '%s: %d daily %s opened, %d missed.',
            $today->toDateString(),
            $result['opened'],
            $result['opened'] === 1 ? 'visit' : 'visits',
            $result['missed'],
        ));

        return self::SUCCESS;
    }
}
