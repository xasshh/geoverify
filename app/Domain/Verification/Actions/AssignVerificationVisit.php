<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Registry\Actions\ResolveStructureCell;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A supervisor gives a paid visit to an officer.
 *
 * The visit is an ordinary assignment, on the field platform's own table, and
 * that is the entire point. The officer's handset already knows how to receive
 * an assignment, work it offline and sync it back; a second delivery mechanism
 * for paid work would mean two capture paths, two sync contracts and two sets
 * of bugs, and would need a shipped client to be replaced to reach the officers
 * who are in the field today.
 *
 * So a paid visit differs from a sweep in three columns and nothing else: it
 * names a structure, it carries a priority that sorts it to the top of the
 * queue, and its kind says what it is.
 */
final class AssignVerificationVisit
{
    public function __construct(private readonly ResolveStructureCell $cells) {}

    public function __invoke(
        VerificationOrder $order,
        User $officer,
        User $assignedBy,
        ?Carbon $dueOn = null,
    ): Assignment {
        if (! $officer->role->capturesInTheField()) {
            throw new RuntimeException('A visit is worked by an officer.');
        }

        return DB::transaction(function () use ($order, $officer, $assignedBy, $dueOn): Assignment {
            /** @var VerificationOrder $fresh */
            $fresh = VerificationOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->allowsMoveTo(OrderStatus::Assigned)) {
                throw new RuntimeException(sprintf(
                    'An order that is %s cannot be given to an officer.',
                    $fresh->status->label(),
                ));
            }

            $fresh->loadMissing('structure');

            $assignment = Assignment::query()->create([
                // The cell as well as the structure. Everything the console
                // draws is organised by cell, and a visit that sat outside that
                // organisation would be invisible on every map we have.
                // Found spatially for a business that registered itself,
                // which carries no cell of its own.
                'grid_cell_id' => ($this->cells)($fresh->structure),
                'structure_id' => $fresh->structure_id,
                'kind' => Assignment::KIND_VISIT,
                'priority' => $fresh->urgency->priority(),
                'user_id' => $officer->id,
                'assigned_by' => $assignedBy->id,
                'assigned_at' => now(),
                // The officer's date is the promise we made, so a breach is
                // visible on the officer's own screen before it is a refund.
                'due_on' => $dueOn ?? $fresh->due_by,
                'status' => AssignmentStatus::Assigned,
            ]);

            $fresh->update([
                'status' => OrderStatus::Assigned,
                'assignment_id' => $assignment->id,
            ]);

            VerificationEvent::record($fresh, 'order.assigned', $assignedBy, [
                'reference' => $fresh->reference,
                'assignment_id' => $assignment->id,
                'officer_id' => $officer->id,
                'due_on' => $assignment->due_on?->toDateString(),
                'priority' => $assignment->priority,
            ]);

            return $assignment;
        });
    }
}
