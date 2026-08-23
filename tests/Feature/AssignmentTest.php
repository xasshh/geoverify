<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Actions\ReleaseAssignment;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function person(Role $role, string $name = 'Test person', string $status = User::STATUS_ACTIVE): User
{
    return User::query()->create([
        'name' => $name,
        'email' => str()->random(12).'@geoverify.test',
        'password' => 'secret-for-tests',
        'role' => $role,
        'status' => $status,
    ]);
}

/** @return list<int> */
function cellsInTestMandate(int $howMany): array
{
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    return GridCell::query()
        ->where('coverage_area_id', $area->id)
        ->limit($howMany)
        ->pluck('id')
        ->map(intval(...))
        ->all();
}

it('assigns cells to an officer and marks the ground as held', function () {
    $officer = person(Role::Officer, 'A. Bello');
    $supervisor = person(Role::Supervisor, 'Adaeze Nwosu');
    $cells = cellsInTestMandate(5);

    $result = app(AssignCells::class)->assign($cells, $officer, $supervisor);

    expect($result['assigned'])->toBe(5)
        ->and(Assignment::query()->where('user_id', $officer->id)->count())->toBe(5)
        ->and(GridCell::query()->whereIn('id', $cells)->where('status', GridCell::STATUS_ASSIGNED)->count())->toBe(5);
});

it('refuses to assign work to anyone but an active field officer', function () {
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(1);

    expect(fn () => app(AssignCells::class)->assign($cells, person(Role::Supervisor), $supervisor))
        ->toThrow(RuntimeException::class, 'not an active field officer');

    expect(fn () => app(AssignCells::class)->assign(
        $cells,
        person(Role::Officer, 'Suspended', User::STATUS_SUSPENDED),
        $supervisor,
    ))->toThrow(RuntimeException::class);
});

it('refuses to let an officer hand out work', function () {
    $cells = cellsInTestMandate(1);

    expect(fn () => app(AssignCells::class)->assign($cells, person(Role::Officer), person(Role::Officer)))
        ->toThrow(RuntimeException::class, 'Only a supervisor can assign work.');
});

it('closes the previous assignment on reassignment instead of overwriting it', function () {
    $first = person(Role::Officer, 'A. Bello');
    $second = person(Role::Officer, 'C. Okafor');
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(3);

    $assigner = app(AssignCells::class);
    $assigner->assign($cells, $first, $supervisor);
    $result = $assigner->assign($cells, $second, $supervisor);

    // Who held which ground on which day is a question an auditor asks, so the
    // earlier rows have to still be there.
    expect($result['reassigned'])->toBe(3)
        ->and(Assignment::query()->count())->toBe(6)
        ->and(Assignment::query()->whereNull('closed_at')->count())->toBe(3)
        ->and(Assignment::query()->where('user_id', $first->id)->whereNotNull('closed_at')->count())->toBe(3)
        ->and(Assignment::query()->where('user_id', $first->id)->first()?->status)
        ->toBe(AssignmentStatus::Reassigned);
});

it('leaves a cell alone when it is already the same officer, keeping how long they have held it', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(2);

    $assigner = app(AssignCells::class);
    $assigner->assign($cells, $officer, $supervisor);
    $result = $assigner->assign($cells, $officer, $supervisor);

    expect($result['assigned'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and(Assignment::query()->count())->toBe(2);
});

it('will not reassign work that is already submitted and waiting on a supervisor', function () {
    $officer = person(Role::Officer);
    $other = person(Role::Officer, 'Second officer');
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(1);

    $assigner = app(AssignCells::class);
    $assigner->assign($cells, $officer, $supervisor);
    Assignment::query()->update(['status' => AssignmentStatus::Submitted]);

    $result = $assigner->assign($cells, $other, $supervisor);

    expect($result['assigned'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});

it('permits only one open assignment per cell, enforced by the database', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(1);

    app(AssignCells::class)->assign($cells, $officer, $supervisor);

    // Written directly, bypassing the action, because the guarantee has to hold
    // even if a future code path forgets to close the previous row.
    expect(fn () => DB::table('assignments')->insert([
        'grid_cell_id' => $cells[0],
        'user_id' => $officer->id,
        'assigned_by' => $supervisor->id,
        'assigned_at' => now(),
        'status' => 'assigned',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('releases a cell back to the unassigned pool without deleting the history', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(1);

    app(AssignCells::class)->assign($cells, $officer, $supervisor);
    $assignment = Assignment::query()->firstOrFail();

    app(ReleaseAssignment::class)->release($assignment, $supervisor);

    expect(Assignment::query()->count())->toBe(1)
        ->and(Assignment::query()->whereNull('closed_at')->count())->toBe(0)
        ->and(GridCell::query()->find($cells[0])?->status)->toBe(GridCell::STATUS_UNASSIGNED);
});

it('refuses to release submitted work, because it is waiting on review not on an officer', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(1);

    app(AssignCells::class)->assign($cells, $officer, $supervisor);
    $assignment = Assignment::query()->firstOrFail();
    $assignment->update(['status' => AssignmentStatus::Submitted]);

    expect(fn () => app(ReleaseAssignment::class)->release($assignment, $supervisor))
        ->toThrow(RuntimeException::class, 'Submitted work cannot be released');
});

it('counts an assignment overdue only while it is still the officer to do', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $cells = cellsInTestMandate(1);

    app(AssignCells::class)->assign($cells, $officer, $supervisor, now()->subDays(2));
    $assignment = Assignment::query()->firstOrFail();

    expect($assignment->isOverdue())->toBeTrue();

    // Submitted late is not still late: it is with the supervisor now.
    $assignment->update(['status' => AssignmentStatus::Submitted]);

    expect($assignment->fresh()?->isOverdue())->toBeFalse();
});
