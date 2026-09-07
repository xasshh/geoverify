<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Facades\DB;

/**
 * Where the officer has got to, read out of the field platform's own record.
 *
 * The order does not track the visit and is not told about it. It reads it,
 * here, and moves itself along the states its own machine allows. Pulled rather
 * than pushed, because pushing would mean the sync path having to know that
 * paid work exists, and officers are in the field against a shipped client:
 * that contract is fixed and nothing here may widen it.
 *
 * Read from the observations rather than from the assignment's status column,
 * because that column is set when work is handed out and the field client never
 * moves it again. What an officer actually produces is a structure_observations
 * row, and the state of that row is the honest answer to how far along the
 * visit is. Reading the column instead would leave every paid order sitting at
 * "assigned" while the photographs were already in the review queue.
 *
 * Idempotent and safe to call on anything. An order with no assignment, or one
 * already settled, is returned untouched.
 */
final class SyncOrderToVisit
{
    public function __invoke(VerificationOrder $order): VerificationOrder
    {
        if ($order->assignment_id === null || $order->status->isSettled()) {
            return $order;
        }

        $latest = DB::scalar(
            'select status from structure_observations
              where assignment_id = ?
              order by id desc
              limit 1',
            [$order->assignment_id],
        );

        if (! is_string($latest)) {
            // Nothing filed yet. The officer holds it and the order says so.
            return $order;
        }

        $wanted = $this->mirror($latest);

        if ($wanted === null || $wanted === $order->status) {
            return $order;
        }

        return DB::transaction(function () use ($order, $wanted): VerificationOrder {
            /** @var VerificationOrder $fresh */
            $fresh = VerificationOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Walked one legal step at a time rather than jumped. An officer who
            // syncs a whole day's work at once should still take the order
            // through in_progress, so the record reads as what happened rather
            // than as a leap nobody can account for.
            $guard = 0;

            while ($fresh->status !== $wanted && $guard++ < count(OrderStatus::cases())) {
                $step = $this->stepToward($fresh->status, $wanted);

                if ($step === null) {
                    break;
                }

                $fresh->update(['status' => $step]);
            }

            return $fresh;
        });
    }

    /** What an observation in this state means for the order behind it. */
    private function mirror(string $observationStatus): ?OrderStatus
    {
        return match ($observationStatus) {
            // Started, not filed. On the handset, or synced as a draft.
            Structure::STATUS_DRAFT => OrderStatus::InProgress,

            // Filed. Waiting on us now, whether it is in the ordinary review
            // queue, flagged for a closer look, or already accepted there:
            // accepting the capture and accepting the order are separate
            // decisions, and only the second one moves money.
            Structure::STATUS_SUBMITTED,
            Structure::STATUS_FLAGGED,
            Structure::STATUS_ACCEPTED => OrderStatus::Submitted,

            // Sent back. The work is with the officer again and the customer's
            // position has not changed: they are still owed the same visit by
            // the same date.
            Structure::STATUS_REJECTED => OrderStatus::InProgress,

            default => null,
        };
    }

    /** The next legal state on the way to where we want to be. */
    private function stepToward(OrderStatus $from, OrderStatus $to): ?OrderStatus
    {
        if ($from->allowsMoveTo($to)) {
            return $to;
        }

        $forward = [
            OrderStatus::Paid,
            OrderStatus::Assigned,
            OrderStatus::InProgress,
            OrderStatus::Submitted,
        ];

        $here = array_search($from, $forward, true);
        $there = array_search($to, $forward, true);

        if ($here === false || $there === false || $there <= $here) {
            return null;
        }

        $next = $forward[$here + 1];

        return $from->allowsMoveTo($next) ? $next : null;
    }
}
