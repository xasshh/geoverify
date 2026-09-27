<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\BuildReviewQueue;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Actions\ScoreObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The review queue and the decision on a capture
|--------------------------------------------------------------------------
|
| The queue exists to put the captures most likely to be wrong in front of the
| person who can do something about them, so what is tested here is the order it
| comes out in, and that a decision on a capture is recorded rather than applied
| silently.
|
*/

it('orders the queue by confidence ascending, worst first', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    $honest = sessionFor($officer, $cell);
    recordFixes($honest, walkedDay(now()->subHours(6), $lon, $lat));
    $good = captureIn($cell, $officer, $honest, now()->subHours(5));

    $spoofed = sessionFor($officer, $cell);
    recordFixes($spoofed, fabricatedDay(now()->subHours(3), $lon, $lat));
    $bad = captureIn($cell, $officer, $spoofed, now()->subHours(2));

    app(ScoreObservation::class)($good);
    app(ScoreObservation::class)($bad);

    $queue = app(BuildReviewQueue::class)();

    expect($queue['awaiting'])->toBe(2)
        ->and($queue['rows'][0]['id'])->toBe($bad->id)
        ->and($queue['rows'][1]['id'])->toBe($good->id)
        ->and($queue['rows'][0]['score'])->toBeLessThan($queue['rows'][1]['score']);
});

it('sorts an unscored capture last, because unscored is not suspicious', function () {
    // Captures are scored on arrival, so the only way to hold one unscored is
    // to stop the job. In the field this is the capture whose scoring has not
    // run yet, which is a queue that is behind and not a capture in doubt.
    Queue::fake();

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    recordFixes($session, fabricatedDay(now()->subHours(3), $lon, $lat));

    $scored = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ScoreObservation::class)($scored);

    $unscored = captureIn($cell, $officer, $session, now()->subHour());

    $queue = app(BuildReviewQueue::class)();

    expect($queue['rows'][0]['id'])->toBe($scored->id)
        ->and($queue['rows'][1]['id'])->toBe($unscored->id)
        ->and($queue['rows'][1]['score'])->toBeNull();
});

it('carries a sampled trace for the presence mark without smoothing it', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    recordFixes($session, walkedDay(now()->subHours(3), $lon, $lat));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ScoreObservation::class)($observation);

    $queue = app(BuildReviewQueue::class)();
    $trace = $queue['rows'][0]['trace'];

    // Twelve fixes, under the sampling cap, so every one of them survives. The
    // mark has to show the jitter, which is the whole reason it is not run
    // through ST_Simplify on the way out.
    expect($trace)->toHaveCount(12)
        ->and($trace[0])->toHaveCount(2)
        ->and($trace[0][0])->toBeFloat();
});

it('shows the queue to a supervisor and sends an officer back to their own work', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    assignedCell($officer, $supervisor);

    $this->actingAs($supervisor)->get(route('console.review.index'))->assertOk();

    // Not a 403. An officer who lands here has taken a wrong turn, not tried
    // to break in, and the console sends them to the work that is theirs.
    $this->actingAs($officer)->get(route('console.review.index'))->assertRedirect('/field');
});

it('lays the record out as the three questions, with every reading shown', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    recordFixes($session, fabricatedDay(now()->subHours(3), $lon, $lat));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ScoreObservation::class)($observation);

    $this->actingAs($supervisor)
        ->get(route('console.review.show', $observation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('console/ReviewRecord')
            ->where('canDecide', true)
            // Every signal is shown, not only the flagged ones: an empty column
            // is ambiguous between clean and not checked.
            ->has('record.signals.presence', 6)
            ->has('record.signals.identification', 2)
            ->has('record.signals.plausibility', 2)
            ->has('record.trace')
            ->where('record.officer.captures', 1));
});

it('offers no decision on a capture already decided', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    $this->actingAs($supervisor)
        ->get(route('console.review.show', $observation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canDecide', false));
});

it('accepts a capture and writes the decision to the log', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    recordFixes($session, walkedDay(now()->subHours(3), $lon, $lat));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    $score = app(ScoreObservation::class)($observation);

    $this->actingAs($supervisor)
        ->post(route('console.review.decide', $observation), ['decision' => 'accept'])
        ->assertRedirect(route('console.review.index'));

    expect($observation->fresh()->status)->toBe(Structure::STATUS_ACCEPTED);

    $event = VerificationEvent::query()
        ->where('subject_id', $observation->id)
        ->where('event', 'observation.accepted')
        ->firstOrFail();

    // The score as it stood when the decision was taken, so a later rescore
    // cannot make the supervisor look better or worse informed than they were.
    expect($event->actor_id)->toBe($supervisor->id)
        ->and($event->evidence['confidence_score'])->toBe($score)
        ->and($event->evidence['from'])->toBe(Structure::STATUS_SUBMITTED);
});

it('refuses to send work back without a reason the officer can act on', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    $this->actingAs($supervisor)
        ->from(route('console.review.show', $observation))
        ->post(route('console.review.decide', $observation), ['decision' => 'return'])
        ->assertSessionHasErrors('decision');

    expect($observation->fresh()->status)->toBe(Structure::STATUS_SUBMITTED);
});

it('returns work with a reason and records the flags as they stood', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    [$lon, $lat] = cellCentre($cell);

    $session = sessionFor($officer, $cell);
    recordFixes($session, fabricatedDay(now()->subHours(3), $lon, $lat));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ScoreObservation::class)($observation);

    $this->actingAs($supervisor)
        ->post(route('console.review.decide', $observation), [
            'decision' => 'return',
            'reason' => 'The trace is a straight line. Walk the street and capture again.',
        ])
        ->assertRedirect();

    $event = VerificationEvent::query()
        ->where('subject_id', $observation->id)
        ->where('event', 'observation.returned')
        ->firstOrFail();

    // The flags as the log recorded them, which is the thing under test: a
    // rescore later must not be able to change what the supervisor was shown.
    $recorded = $event->evidence['flags'] ?? [];
    $signalsAtDecision = array_map(
        static fn (mixed $flag): mixed => is_array($flag) ? ($flag['signal'] ?? null) : null,
        is_array($recorded) ? $recorded : [],
    );

    expect($observation->fresh()->status)->toBe(Structure::STATUS_REJECTED)
        ->and($event->evidence['reason'])->toContain('straight line')
        ->and($signalsAtDecision)->toContain('trace_naturalness');
});

it('pushes a returned capture back to the officer who made it', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    // Before the decision the officer's board is clean.
    $this->actingAs($officer)
        ->get(route('field.cells'))
        ->assertInertia(fn ($page) => $page->has('assignments.0.returnedCaptures', 0));

    app(ReviewObservation::class)(
        $observation,
        ReviewDecision::Return,
        $supervisor,
        'The photographs do not show the structure recorded.',
    );

    // A return the officer cannot see is not a return. The reason travels with
    // it, read from the log rather than copied.
    $this->actingAs($officer)
        ->get(route('field.cells'))
        ->assertInertia(fn ($page) => $page
            ->has('assignments.0.returnedCaptures', 1)
            ->where('assignments.0.returnedCaptures.0.id', $observation->id)
            ->where(
                'assignments.0.returnedCaptures.0.reason',
                'The photographs do not show the structure recorded.',
            ));
});

it('keeps one officer\'s returned work off another officer\'s board', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $other = person(Role::Officer, 'Another officer');
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    app(ReviewObservation::class)(
        $observation,
        ReviewDecision::Return,
        $supervisor,
        'The position is outside the assigned cell.',
    );

    // A cell of their own. Reusing the first officer's would reassign it away
    // from them, which is a different test.
    $second = GridCell::query()
        ->where('coverage_area_id', $cell->coverage_area_id)
        ->whereKeyNot($cell->id)
        ->firstOrFail();

    app(AssignCells::class)->assign([$second->id], $other, $supervisor);

    $this->actingAs($other)
        ->get(route('field.cells'))
        ->assertInertia(fn ($page) => $page->has('assignments.0.returnedCaptures', 0));
});

it('never lets anyone decide their own capture, not even after promotion', function () {
    // An officer's captures stay attributable after they leave or move on, so
    // an officer promoted to supervisor will find their own past work sitting
    // in the queue they now run. They still may not decide it.
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    $officer->update(['role' => Role::Supervisor]);

    $this->actingAs($officer->fresh())
        ->post(route('console.review.decide', $observation), ['decision' => 'accept'])
        ->assertForbidden();

    expect($observation->fresh()->status)->toBe(Structure::STATUS_SUBMITTED);
});

it('does not retake a decision that has already been taken', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    $this->actingAs($supervisor)
        ->post(route('console.review.decide', $observation), ['decision' => 'accept'])
        ->assertForbidden();
});

it('projects the decision onto the structure, but only from its latest observation', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $session = sessionFor($officer, $cell);
    $older = captureIn($cell, $officer, $session, now()->subHours(4));

    // A re-enumeration of the same structure, which is a new observation and
    // never an overwrite.
    $newer = StructureObservation::query()->create([
        'structure_id' => $older->structure_id,
        'captured_by' => $officer->id,
        'field_session_id' => $session->id,
        'assignment_id' => $older->assignment_id,
        'observed_at' => now()->subHour(),
        'structure_type' => $older->structure_type,
        'occupancy_status' => $older->occupancy_status,
        'client_uuid' => (string) Str::uuid7(),
    ]);

    app(ReviewObservation::class)($older, ReviewDecision::Accept, $supervisor);

    // The structure still describes the newer observation, which nobody has
    // decided yet. A decision about February must not restate March.
    $status = DB::scalar('select status from structures where id = ?', [$older->structure_id]);
    expect($status)->not->toBe(Structure::STATUS_ACCEPTED);

    app(ReviewObservation::class)($newer, ReviewDecision::Accept, $supervisor);

    $status = DB::scalar('select status from structures where id = ?', [$older->structure_id]);
    expect($status)->toBe(Structure::STATUS_ACCEPTED);
});
