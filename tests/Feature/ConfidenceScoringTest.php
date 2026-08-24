<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\AssembleCaptureFacts;
use App\Domain\Verification\Actions\ScoreObservation;
use App\Domain\Verification\Enums\Verdict;
use App\Domain\Verification\Models\ObservationSignal;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Confidence scoring
|--------------------------------------------------------------------------
|
| The signals themselves are tested in isolation in tests/Unit. What is tested
| here is the half that needs a database: that the facts are assembled correctly
| out of PostGIS, and that a fabricated day and a walked one come out at opposite
| ends of the queue.
|
*/

/**
 * The middle of a cell, read from PostGIS.
 *
 * Fixtures are anchored here rather than at a hardcoded coordinate, because a
 * structure has to actually stand in the cell it was assigned in for the
 * containment signal to have anything true to say about it.
 *
 * @return array{float, float}
 */
function cellCentre(GridCell $cell): array
{
    $centre = DB::selectOne(
        'select st_x(centroid::geometry) as lon, st_y(centroid::geometry) as lat from grid_cells where id = ?',
        [$cell->id],
    );

    return [(float) $centre->lon, (float) $centre->lat];
}

/** A session for the officer, with an assignment so containment can be judged. */
function sessionFor(User $officer, GridCell $cell): FieldSession
{
    $assignment = Assignment::query()->where('grid_cell_id', $cell->id)->firstOrFail();

    return FieldSession::query()->create([
        'user_id' => $officer->id,
        'assignment_id' => $assignment->id,
        'started_at' => now()->subHours(3),
        'client_uuid' => (string) Str::uuid7(),
    ]);
}

/**
 * Writes fixes and rebuilds the session trace from them, in PostGIS.
 *
 * @param  list<array{lon: float, lat: float, at: Carbon, accuracy: float, mock?: bool, satellites?: int|null, network?: array{float, float}|null}>  $fixes
 */
function recordFixes(FieldSession $session, array $fixes): void
{
    foreach ($fixes as $fix) {
        $network = $fix['network'] ?? null;

        // point is NOT NULL, and deliberately so: a fix without a position is
        // not a fix. It therefore has to be written in the insert rather than
        // filled in afterwards.
        $bindings = [
            $session->id,
            $fix['at'],
            $fix['accuracy'],
            $fix['satellites'] ?? 9,
            $fix['mock'] ?? false,
            $fix['lon'],
            $fix['lat'],
        ];

        if ($network !== null) {
            $bindings[] = $network[0];
            $bindings[] = $network[1];
        }

        DB::insert(sprintf(<<<'SQL'
            insert into position_fixes (
                field_session_id, recorded_at, accuracy_m, satellite_count, is_mock,
                provider, source, created_at, updated_at, point, network_point
            ) values (
                ?, ?, ?, ?, ?, 'gps', 'device', now(), now(),
                st_setsrid(st_point(?, ?), 4326), %s
            )
        SQL, $network === null ? 'null' : 'st_setsrid(st_point(?, ?), 4326)'), $bindings);
    }

    // The trace is built from the stored fixes, with M carrying epoch seconds,
    // exactly as RecordTrace builds it in the field.
    DB::statement(<<<'SQL'
        update field_sessions set trace = (
            select st_makeline(
                st_setsrid(st_makepointm(st_x(point), st_y(point), extract(epoch from recorded_at)), 4326)
                order by recorded_at
            )
            from position_fixes where field_session_id = ?
        ) where id = ?
    SQL, [$session->id, $session->id]);
}

/**
 * A day genuinely walked: turns, uneven steps, accuracy that wanders.
 *
 * @return list<array{lon: float, lat: float, at: Carbon, accuracy: float, network: array{float, float}}>
 */
function walkedDay(Carbon $start, float $lon, float $lat): array
{
    $shape = [
        [0.0000, 0.0000], [0.0004, 0.0001], [0.0007, 0.0004], [0.0007, 0.0009],
        [0.0011, 0.0012], [0.0016, 0.0012], [0.0018, 0.0016], [0.0018, 0.0022],
        [0.0023, 0.0024], [0.0029, 0.0025], [0.0031, 0.0030], [0.0036, 0.0034],
    ];
    $accuracies = [3.1, 4.4, 2.8, 5.9, 3.3, 4.9, 3.7, 6.2, 2.9, 4.1, 3.5, 4.8];
    $gaps = [0, 47, 112, 63, 155, 88, 41, 203, 76, 129, 58, 174];

    $fixes = [];
    $at = $start->copy();

    foreach ($shape as $index => [$dLon, $dLat]) {
        $at = $at->copy()->addSeconds($gaps[$index]);
        $fixes[] = [
            'lon' => $lon + $dLon,
            'lat' => $lat + $dLat,
            'at' => $at,
            'accuracy' => $accuracies[$index],
            'network' => [$lon + $dLon + 0.0012, $lat + $dLat - 0.0009],
        ];
    }

    return $fixes;
}

/**
 * A day that was not walked: a straight line, a constant step, a constant
 * accuracy, and a network position that never left the officer's house.
 *
 * @return list<array{lon: float, lat: float, at: Carbon, accuracy: float, network: array{float, float}}>
 */
function fabricatedDay(Carbon $start, float $lon, float $lat): array
{
    $fixes = [];
    $at = $start->copy();

    for ($i = 0; $i < 12; $i++) {
        $at = $at->copy()->addSeconds(60);
        $fixes[] = [
            'lon' => $lon + ($i * 0.0003),
            'lat' => $lat + ($i * 0.0003),
            'at' => $at,
            'accuracy' => 4.0,
            // Kilometres away, and never moving: the handset never left home.
            'network' => [$lon + 0.06, $lat + 0.06],
        ];
    }

    return $fixes;
}

/** Captures a structure in the cell, tied to the session, at the given time. */
function captureIn(GridCell $cell, User $officer, FieldSession $session, Carbon $at, ?float $lon = null, ?float $lat = null): StructureObservation
{
    $assignment = Assignment::query()->where('grid_cell_id', $cell->id)->firstOrFail();
    [$centreLon, $centreLat] = cellCentre($cell);
    $lon ??= $centreLon;
    $lat ??= $centreLat;

    $structure = app(CaptureStructure::class)->capture(
        captureFor($cell, [
            'longitude' => $lon,
            'latitude' => $lat,
            'observed_at' => $at->toIso8601String(),
            'field_session_id' => $session->id,
            'assignment_id' => $assignment->id,
        ]),
        $officer,
    );

    return StructureObservation::query()
        ->where('structure_id', $structure->id)
        ->latest('observed_at')
        ->firstOrFail();
}

it('assembles the facts out of PostGIS rather than out of PHP', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);

    recordFixes($session, walkedDay(now()->subHours(3), ...cellCentre($cell)));

    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    $facts = app(AssembleCaptureFacts::class)($observation);

    expect($facts->fixCount)->toBe(12)
        ->and($facts->distinctAccuracyValues)->toBe(12)
        ->and($facts->mockFixCount)->toBe(0)
        // Metres over the ellipsoid, from the geography type. A degree based
        // number here would be roughly 0.005 and the signals would be nonsense.
        ->and($facts->traceLengthM)->toBeGreaterThan(400.0)
        ->and($facts->traceEndToEndM)->toBeGreaterThan(400.0)
        ->and($facts->sinuosity())->toBeGreaterThan(1.05)
        ->and($facts->insideAssignedCell)->toBeTrue();
});

it('scores a walked day high and keeps what each signal concluded', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);

    recordFixes($session, walkedDay(now()->subHours(3), ...cellCentre($cell)));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    $score = app(ScoreObservation::class)($observation);

    expect($score)->toBeGreaterThan(60)
        ->and($observation->fresh()->confidence_score)->toBe($score)
        ->and(ObservationSignal::query()->where('structure_observation_id', $observation->id)->count())
        ->toBe(count(ScoreObservation::signals()));

    // The mock provider signal is the heaviest, and a walked day passes it.
    $mock = ObservationSignal::query()
        ->where('structure_observation_id', $observation->id)
        ->where('signal', 'mock_location')
        ->firstOrFail();

    expect($mock->verdict)->toBe(Verdict::Ok)->and($mock->deduction)->toBe(0);
});

it('reviews a spoofed day and catches it', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    [$lon, $lat] = cellCentre($cell);

    // A day genuinely worked: an uneven pace, because one shop is shut and the
    // next owner wants to talk about the last census.
    $honest = sessionFor($officer, $cell);
    recordFixes($honest, walkedDay(now()->subHours(6), $lon, $lat));
    $honestCapture = null;
    $at = now()->subHours(6);

    foreach ([0, 412, 197, 638, 285] as $gap) {
        $at = $at->copy()->addSeconds($gap);
        $honestCapture = captureIn($cell, $officer, $honest, $at);
    }

    // A day that was not: a straight line at a constant step, an accuracy that
    // never moved, a handset that never left home, and a capture every three
    // minutes to the second.
    $spoofed = sessionFor($officer, $cell);
    recordFixes($spoofed, fabricatedDay(now()->subHours(3), $lon, $lat));
    $spoofedCapture = null;
    $at = now()->subHours(3);

    for ($i = 0; $i < 5; $i++) {
        $at = $at->copy()->addSeconds(180);
        $spoofedCapture = captureIn($cell, $officer, $spoofed, $at);
    }

    $honestScore = app(ScoreObservation::class)($honestCapture);
    $spoofedScore = app(ScoreObservation::class)($spoofedCapture);

    expect($spoofedScore)->toBeLessThan($honestScore)
        // Not a hair apart. A supervisor scanning a queue has to see the
        // difference without reading the flags.
        ->and($honestScore - $spoofedScore)->toBeGreaterThan(30);

    // Caught for the reasons it should be caught for, not by accident. Each of
    // these is an independent tell, and a fabricated day trips all four.
    $flagged = ObservationSignal::query()
        ->where('structure_observation_id', $spoofedCapture->id)
        ->flagged()
        ->pluck('verdict', 'signal');

    expect($flagged)->toHaveKeys([
        'trace_naturalness',
        'accuracy_variance',
        'network_divergence',
        'capture_interval',
    ]);

    // The honest day trips none of them.
    $honestFlags = ObservationSignal::query()
        ->where('structure_observation_id', $honestCapture->id)
        ->flagged()
        ->pluck('signal');

    expect($honestFlags)->not->toContain('trace_naturalness')
        ->and($honestFlags)->not->toContain('accuracy_variance')
        ->and($honestFlags)->not->toContain('network_divergence')
        ->and($honestFlags)->not->toContain('capture_interval');

    // The queue is ordered by score ascending, so the fabricated day is what a
    // supervisor opens first.
    $queue = StructureObservation::query()
        ->whereIn('id', [$honestCapture->id, $spoofedCapture->id])
        ->orderBy('confidence_score')
        ->pluck('id');

    expect($queue->first())->toBe($spoofedCapture->id);
});

it('fails outright on a mock location provider', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);

    $fixes = walkedDay(now()->subHours(3), ...cellCentre($cell));
    $fixes[4]['mock'] = true;
    recordFixes($session, $fixes);

    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    app(ScoreObservation::class)($observation);

    $mock = ObservationSignal::query()
        ->where('structure_observation_id', $observation->id)
        ->where('signal', 'mock_location')
        ->firstOrFail();

    expect($mock->verdict)->toBe(Verdict::Fail)
        ->and($mock->deduction)->toBe(20);
});

it('scores a capture on arrival, without anyone asking', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);

    recordFixes($session, fabricatedDay(now()->subHours(3), ...cellCentre($cell)));

    // Nothing below calls the scorer. The capture arriving is what scores it.
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    expect($observation->fresh()->confidence_score)->not->toBeNull()
        ->and(ObservationSignal::query()->where('structure_observation_id', $observation->id)->count())
        ->toBe(count(ScoreObservation::signals()));
});

it('records the score as a system event, with the flags that produced it', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);

    recordFixes($session, fabricatedDay(now()->subHours(3), ...cellCentre($cell)));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    // The first scoring, which is the one the capture's own arrival triggered.
    $event = VerificationEvent::query()
        ->where('subject_type', $observation->getMorphClass())
        ->where('subject_id', $observation->id)
        ->where('event', 'observation.scored')
        ->orderBy('id')
        ->firstOrFail();

    expect($event->actor_type)->toBe(VerificationEvent::ACTOR_SYSTEM)
        ->and($event->evidence['score'])->toBe($observation->fresh()->confidence_score)
        ->and($event->evidence['previous_score'])->toBeNull()
        ->and($event->evidence['flags'])->not->toBeEmpty();
});

it('replaces signal readings on a rescore rather than stacking them', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);

    recordFixes($session, walkedDay(now()->subHours(3), ...cellCentre($cell)));
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    $onArrival = VerificationEvent::query()
        ->where('subject_id', $observation->id)
        ->where('event', 'observation.scored')
        ->count();

    $first = app(ScoreObservation::class)($observation);
    $second = app(ScoreObservation::class)($observation->fresh());

    expect($second)->toBe($first)
        ->and(ObservationSignal::query()->where('structure_observation_id', $observation->id)->count())
        ->toBe(count(ScoreObservation::signals()));

    // Every scoring is in the log, because the log is append only even when
    // the reading it describes was replaced: one on arrival, two on demand.
    expect(VerificationEvent::query()
        ->where('subject_id', $observation->id)
        ->where('event', 'observation.scored')
        ->count())->toBe($onArrival + 2);
});
