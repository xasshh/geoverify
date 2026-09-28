<?php

declare(strict_types=1);

namespace App\Domain\Field\Models;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Officer to cell: who is responsible for which ground, and by when. */
/**
 * @property int $id
 * @property int $grid_cell_id
 * @property int|null $structure_id
 * @property string $kind
 * @property int $priority
 * @property int $user_id
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $due_on
 * @property AssignmentStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $return_reason
 * @property Carbon|null $closed_at
 * @property-read GridCell|null $gridCell
 * @property-read User|null $officer
 */
final class Assignment extends Model
{
    /**
     * Cover this ground and record what is on it.
     *
     * The default, and what every assignment written before Phase 2 was. A
     * sweep names a cell and no particular building, and one cell may only have
     * one open at a time.
     */
    public const KIND_SWEEP = 'sweep';

    /**
     * Go to this building, because somebody paid us to.
     *
     * Named against a structure and exempt from the one-open-per-cell rule: a
     * paid visit must be givable to an officer today whether or not the cell it
     * falls in is already being swept by somebody else.
     */
    public const KIND_VISIT = 'visit';

    /**
     * An inspection or site visit a buyer paid for (Phase 4 M3). Names the
     * building, like a visit; its report lives on `inspections`, not in the
     * register.
     */
    public const KIND_INSPECTION = 'inspection';

    protected $fillable = [
        'grid_cell_id', 'structure_id', 'kind', 'priority',
        'user_id', 'assigned_by', 'assigned_at', 'due_on',
        'status', 'started_at', 'submitted_at', 'reviewed_at', 'reviewed_by',
        'return_reason', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'priority' => 'integer',
            'assigned_at' => 'datetime',
            'due_on' => 'date',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GridCell, $this> */
    public function gridCell(): BelongsTo
    {
        return $this->belongsTo(GridCell::class);
    }

    /** @return BelongsTo<User, $this> */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The live assignment for a cell. Closed rows are history and are never
     * returned here.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNull('closed_at')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->toDateString())
            ->whereIn('status', [
                AssignmentStatus::Assigned->value,
                AssignmentStatus::InProgress->value,
                AssignmentStatus::Returned->value,
            ]);
    }

    public function isOverdue(): bool
    {
        return $this->closed_at === null
            && $this->due_on !== null
            && $this->status->isOpen()
            && $this->due_on->isBefore(now()->startOfDay());
    }
}
