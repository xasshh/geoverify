<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hands cells to an officer.
 *
 * Reassignment closes the previous assignment rather than editing it. Who held
 * which ground on which day is a question an auditor will ask, and an overwritten
 * row cannot answer it.
 */
final class AssignCells
{
    /**
     * @param  list<int>  $gridCellIds
     * @return array{assigned: int, reassigned: int, skipped: int}
     */
    public function assign(
        array $gridCellIds,
        User $officer,
        User $assignedBy,
        ?Carbon $dueOn = null,
    ): array {
        if (! $officer->capturesInTheField()) {
            throw new RuntimeException(
                "{$officer->name} is not an active field officer and cannot hold assignments.",
            );
        }

        if (! $assignedBy->supervises()) {
            throw new RuntimeException('Only a supervisor can assign work.');
        }

        $cellIds = array_values(array_unique(array_filter($gridCellIds)));

        if ($cellIds === []) {
            return ['assigned' => 0, 'reassigned' => 0, 'skipped' => 0];
        }

        return DB::transaction(function () use ($cellIds, $officer, $assignedBy, $dueOn): array {
            // Locked for the duration: two supervisors assigning the same cell at
            // the same moment would otherwise both succeed against the partial
            // unique index and one would fail with a constraint error the officer
            // never sees the cause of.
            $cells = GridCell::query()
                ->whereIn('id', $cellIds)
                ->lockForUpdate()
                ->get();

            $existing = Assignment::query()
                ->whereIn('grid_cell_id', $cells->pluck('id'))
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->get()
                ->keyBy('grid_cell_id');

            $assigned = 0;
            $reassigned = 0;
            $skipped = 0;
            $now = now();

            foreach ($cells as $cell) {
                /** @var Assignment|null $open */
                $open = $existing->get($cell->id);

                if ($open instanceof Assignment) {
                    // Already this officer's, and still open. Leave it alone rather
                    // than churning the assigned_at date and losing how long they
                    // have actually held it.
                    if ($open->user_id === $officer->id && $open->status->isOpen()) {
                        $skipped++;

                        continue;
                    }

                    // Submitted or accepted work is not reassignable: it is waiting
                    // on a supervisor, not on an officer.
                    if (! $open->status->isOpen()) {
                        $skipped++;

                        continue;
                    }

                    $open->update([
                        'status' => AssignmentStatus::Reassigned,
                        'closed_at' => $now,
                    ]);

                    $reassigned++;
                }

                Assignment::query()->create([
                    'grid_cell_id' => $cell->id,
                    'user_id' => $officer->id,
                    'assigned_by' => $assignedBy->id,
                    'assigned_at' => $now,
                    'due_on' => $dueOn,
                    'status' => AssignmentStatus::Assigned,
                ]);

                $cell->update(['status' => GridCell::STATUS_ASSIGNED]);

                $assigned++;
            }

            return ['assigned' => $assigned, 'reassigned' => $reassigned, 'skipped' => $skipped];
        });
    }
}
