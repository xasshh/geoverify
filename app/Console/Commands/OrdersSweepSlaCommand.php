<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Verification\Actions\RefundOrder;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Console\Command;
use Throwable;

/**
 * The refund the customer never has to ask for.
 *
 * We promised ten working days, or three. A promise that is only kept when
 * somebody complains is not a promise, it is a hope that they will not notice,
 * and the customers who do notice are the ones who write about it in public.
 *
 * So this runs every morning and returns the money on orders that went past
 * their date without an officer attending. It refunds rather than cancelling:
 * the order is settled, and the customer may place another one for the same
 * tier the moment this has run, which the partial unique index allows because
 * a refunded order is no longer live.
 *
 * Each order is refunded in its own transaction and its own try. One order
 * whose ledger post fails must not stop the twenty behind it from being paid
 * back, and a sweep that gives up halfway is one nobody can reason about.
 */
final class OrdersSweepSlaCommand extends Command
{
    protected $signature = 'orders:sweep-sla {--dry-run : List what would be refunded and post nothing}';

    protected $description = 'Refund verification orders whose promised date passed unattended';

    public function handle(RefundOrder $refunds): int
    {
        $breached = VerificationOrder::query()->breachingSla()->orderBy('due_by')->get();

        if ($breached->isEmpty()) {
            $this->info('Nothing is past its date.');

            return self::SUCCESS;
        }

        $this->warn("{$breached->count()} order(s) past the date we promised.");

        $failed = 0;

        foreach ($breached as $order) {
            $line = sprintf(
                '%s  %s  due %s  N%s',
                $order->reference,
                $order->status->value,
                $order->due_by?->toDateString() ?? 'never',
                number_format($order->amount()),
            );

            if ($this->option('dry-run')) {
                $this->line("  would refund  {$line}");

                continue;
            }

            try {
                $refunds(
                    $order,
                    // The reason a customer will read on their own order page.
                    // Ours, said plainly, because it was our failure.
                    sprintf(
                        'We did not attend within the %d working days we promised.',
                        $order->sla_working_days,
                    ),
                );

                $this->line("  refunded  {$line}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("  failed  {$line}  {$e->getMessage()}");
            }
        }

        // A non-zero exit so whatever runs this is told, rather than a red line
        // scrolling past in a log nobody reads.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
