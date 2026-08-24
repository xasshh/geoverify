<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\ReadLiveOperations;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Live operations, and coverage against what was accepted
|--------------------------------------------------------------------------
|
| Two questions a supervisor asks that nothing before M6 could answer: who is
| out there now, and how much of the mandate is actually verified rather than
| merely visited.
|
*/

it('reads who is out there from the last fix, not from a politely closed session', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    // A session nobody ever ended, which is what a flat battery leaves behind.
    $session = sessionFor($officer, $cell);
    recordFixes($session, walkedDay(now()->subMinutes(20), $lon, $lat));

    $live = app(ReadLiveOperations::class)();

    expect($live['officers'])->toHaveCount(1);

    $reading = $live['officers'][0];

    expect($reading['officer'])->toBe($officer->name)
        ->and($reading['endedAt'])->toBeNull()
        ->and($reading['active'])->toBeTrue()
        ->and($reading['longitude'])->toBeFloat()
        ->and($reading['trace'])->not->toBeEmpty();
});

it('calls an officer quiet once the fixes stop, whatever the session says', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    recordFixes($session, walkedDay(now()->subHours(4), $lon, $lat));

    $live = app(ReadLiveOperations::class)();

    expect($live['officers'][0]['active'])->toBeFalse()
        ->and($live['officers'][0]['endedAt'])->toBeNull();
});

it('surfaces a mock location provider on the live map', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    $fixes = walkedDay(now()->subMinutes(10), $lon, $lat);
    $fixes[array_key_last($fixes)]['mock'] = true;
    recordFixes($session, $fixes);

    expect(app(ReadLiveOperations::class)()['officers'][0]['isMock'])->toBeTrue();
});

it('leaves an officer who has not worked today off the map entirely', function () {
    $officer = person(Role::Officer);
    assignedCell($officer, person(Role::Supervisor));

    // An assignment but no session: holding ground is not being on it.
    expect(app(ReadLiveOperations::class)()['officers'])->toBe([]);
});

it('shows the live console to a supervisor and sends an officer to their own work', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    assignedCell($officer, $supervisor);

    $this->actingAs($supervisor)->get(route('console.live'))->assertOk();
    $this->actingAs($supervisor)->get(route('console.live.feed'))->assertOk();
    $this->actingAs($officer)->get(route('console.live'))->assertRedirect('/field');
});

it('opens the map on the mandate rather than on wherever an officer stands', function () {
    $supervisor = person(Role::Supervisor);
    assignedCell(person(Role::Officer), $supervisor);

    $this->actingAs($supervisor)
        ->get(route('console.live'))
        ->assertInertia(fn ($page) => $page
            ->component('console/Live')
            ->has('bounds', 4)
            ->where('bounds.0', fn (float $minX): bool => $minX > 7.0 && $minX < 7.6));
});

it('counts a cell as covered when captured and verified when accepted', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    // Captured, so the cell has been visited. Nothing is verified yet, and the
    // two numbers disagreeing is the whole point of keeping both.
    $fresh = GridCell::query()->findOrFail($cell->id);
    expect($fresh->structures_captured)->toBe(1)
        ->and($fresh->structures_accepted)->toBe(0);

    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    $fresh = GridCell::query()->findOrFail($cell->id);
    expect($fresh->structures_captured)->toBe(1)
        ->and($fresh->structures_accepted)->toBe(1);
});

it('takes acceptance back off the cell when the work is returned', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $first = captureIn($cell, $officer, $session, now()->subHours(3));

    app(ReviewObservation::class)($first, ReviewDecision::Accept, $supervisor);
    expect(GridCell::query()->findOrFail($cell->id)->structures_accepted)->toBe(1);

    // A re-enumeration of the same structure, returned. Re-enumeration writes a
    // new observation and never overwrites the last one, so this is a second
    // observation of one structure rather than a second structure.
    $second = StructureObservation::query()->create([
        'structure_id' => $first->structure_id,
        'captured_by' => $officer->id,
        'field_session_id' => $session->id,
        'assignment_id' => $first->assignment_id,
        'observed_at' => now()->subHour(),
        'structure_type' => $first->structure_type,
        'occupancy_status' => $first->occupancy_status,
        'client_uuid' => (string) Str::uuid7(),
    ]);

    app(ReviewObservation::class)($second, ReviewDecision::Return, $supervisor, 'Do this one again.');

    // The structure is no longer accepted, so the cell must stop claiming it.
    // Recomputed rather than decremented, which is what makes this safe to run
    // twice and safe after a decision is revisited.

    expect(GridCell::query()->findOrFail($cell->id)->structures_accepted)->toBe(0);
});

it('serves coverage cells with both numerators against the footprint denominator', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    DB::table('grid_cells')->where('id', $cell->id)->update(['footprint_count' => 4]);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    $response = $this->actingAs($supervisor)
        ->getJson(route('console.coverage.cells', $cell->coverage_area_id))
        ->assertOk();

    $features = $response->json('features');
    $feature = null;

    foreach (is_array($features) ? $features : [] as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $cell->id) {
            $feature = $candidate;
            break;
        }
    }

    expect($feature)->not->toBeNull()
        ->and($feature['properties']['footprints'])->toBe(4)
        ->and($feature['properties']['captured'])->toBe(1)
        ->and($feature['properties']['accepted'])->toBe(1)
        // One accepted of four footprints, on the same denominator as coverage.
        ->and((float) $feature['properties']['verified'])->toBe(25.0);
});

it('reports captured and accepted totals on the coverage summary', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $accepted = captureIn($cell, $officer, $session, now()->subHours(3));
    captureIn($cell, $officer, $session, now()->subHours(2), 7.999, 9.999);

    app(ReviewObservation::class)($accepted, ReviewDecision::Accept, $supervisor);

    $this->actingAs($supervisor)
        ->get(route('console.coverage', $cell->coverage_area_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.captured', fn (int $n): bool => $n >= 1)
            ->where('summary.accepted', 1));
});

it('never lets a structure count as accepted in a cell that is not its own', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $other = GridCell::query()
        ->where('coverage_area_id', $cell->coverage_area_id)
        ->whereKeyNot($cell->id)
        ->firstOrFail();

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    expect(GridCell::query()->findOrFail($other->id)->structures_accepted)->toBe(0)
        ->and(Structure::query()->findOrFail($observation->structure_id)->status)
        ->toBe(Structure::STATUS_ACCEPTED);
});
