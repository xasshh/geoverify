<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Commerce\Actions\ManagePurchase;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pays a merchant whose buyer went quiet.
 *
 * A dispatched order is held until the buyer confirms or raises an issue. A
 * buyer who does neither would otherwise hold a stranger's money for ever, so
 * once the window after dispatch has closed the order is released as if they
 * had confirmed. An order with an issue raised is never touched here: that
 * waits for a person.
 *
 * Each order in its own try, as the SLA sweep does, so one failure does not
 * leave the rest unpaid.
 */
final class OrdersReleaseDeliveredCommand extends Command
{
    protected $signature = 'orders:release-delivered {--dry-run : List what would be released and post nothing}';

    protected $description = 'Release product orders whose buyer neither confirmed nor raised an issue in time';

    public function handle(ManagePurchase $purchases): int
    {
        $days = (int) config('geoverify.commerce.release_after_days', 7);

        $due = PurchaseOrder::query()
            ->where('status', PurchaseStatus::Dispatched->value)
            ->where('dispatched_at', '<=', now()->subDays($days))
            ->orderBy('dispatched_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing is waiting on a quiet buyer.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($due as $order) {
            $line = sprintf('%s  dispatched %s  N%s', $order->reference, $order->dispatched_at?->toDateString(), number_format(intdiv($order->amount_minor, 100)));

            if ($this->option('dry-run')) {
                $this->line("  would release  {$line}");

                continue;
            }

            try {
                $purchases->releaseByWindow($order);
                $this->line("  released  {$line}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("  failed  {$line}  {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
