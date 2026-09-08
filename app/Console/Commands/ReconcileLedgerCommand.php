<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\Actions\ReconcileWithProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Compares what the provider collected with what this ledger says it did.
 *
 * Exits non-zero when the two disagree, so it can be run by a scheduler that
 * shouts. A reconciliation nobody reads is a reconciliation nobody runs, and
 * the whole value of this is that a single unrecorded charge is noticed on the
 * day rather than at the end of a quarter.
 *
 * The default window is the last seven days rather than yesterday. A webhook
 * that failed on Friday and was retried into a black hole over the weekend is
 * exactly the case this is for, and a window of one day would report it clean
 * on Monday.
 */
final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'geoverify:reconcile-ledger
        {--from= : Start of the window, as a date. Defaults to seven days ago}
        {--to= : End of the window, as a date. Defaults to now}';

    protected $description = 'Compare the ledger against the payment provider and report drift';

    public function handle(ReconcileWithProvider $reconcile): int
    {
        $timezone = config('app.timezone');

        try {
            $from = $this->option('from') === null
                ? Carbon::now($timezone)->subDays(7)->startOfDay()
                : Carbon::parse((string) $this->option('from'), $timezone)->startOfDay();

            $to = $this->option('to') === null
                ? Carbon::now($timezone)
                : Carbon::parse((string) $this->option('to'), $timezone)->endOfDay();
        } catch (\Throwable) {
            $this->error('Those dates could not be read. Use something like 2026-09-01.');

            return self::INVALID;
        }

        if ($from->greaterThan($to)) {
            $this->error('The window starts after it ends.');

            return self::INVALID;
        }

        try {
            $report = $reconcile($from, $to);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Window  {$report['from']} to {$report['to']}");
        $this->newLine();

        $this->table(
            ['', 'Charges', 'Naira'],
            [
                ['Provider', $report['provider_count'], self::naira($report['provider_total_minor'])],
                ['Ledger', $report['ledger_count'], self::naira($report['ledger_total_minor'])],
                ['Drift', '', self::naira($report['drift_minor'])],
                ['Refunds posted', '', self::naira($report['refunds_minor'])],
            ],
        );

        $clean = $report['drift_minor'] === 0
            && $report['missing_from_ledger'] === []
            && $report['missing_from_provider'] === []
            && $report['mismatched'] === [];

        if ($clean) {
            $this->info('The two sides agree.');

            return self::SUCCESS;
        }

        // Each list is a different kind of problem and they are not
        // interchangeable, so they are reported apart rather than as one count
        // of exceptions. What somebody does next depends on which one it is.
        foreach ($report['missing_from_ledger'] as $charge) {
            $this->error(sprintf(
                'Collected but not recorded  %s  %s  %s',
                $charge['reference'],
                self::naira($charge['amount_minor']),
                $charge['paid_at'] ?? 'no date given',
            ));
        }

        foreach ($report['missing_from_provider'] as $entry) {
            $this->error(sprintf(
                'Recorded but not collected  %s  %s',
                $entry['reference'],
                self::naira($entry['amount_minor']),
            ));
        }

        foreach ($report['mismatched'] as $entry) {
            $this->error(sprintf(
                'Different amounts  %s  provider %s  ledger %s',
                $entry['reference'],
                self::naira($entry['provider_minor']),
                self::naira($entry['ledger_minor']),
            ));
        }

        $this->newLine();
        $this->warn('The ledger and the provider disagree. Nothing has been changed here: a correction is a posted movement, made by somebody who has looked.');

        return self::FAILURE;
    }

    /** Kobo as a person reads it. */
    private static function naira(int $minor): string
    {
        return 'N'.number_format($minor / 100, 2);
    }
}
