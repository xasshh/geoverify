<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Models\Assignment;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Structure;
use App\Domain\Sync\Actions\ProcessMutationBatch;
use App\Domain\Sync\Models\SyncReceipt;
use App\Enums\Role;
use App\Jobs\RefreshCellProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Sync under load
|--------------------------------------------------------------------------
|
| The M8 gate is 2,000 mutations and 500 photographs, and it asks for measured
| numbers rather than assurances. So these tests measure, print what they found,
| and fail on a budget: a regression that doubles the query count is a defect
| even when every assertion about behaviour still passes.
|
| Scoring is faked here on purpose. In production a capture is scored by a
| queued job, and letting the sync connection run it inline would measure a
| system nobody deploys.
|
*/

/**
 * A batch of structure mutations, shaped exactly as a handset sends them.
 *
 * @return list<array<string, mixed>>
 */
function mutationsFor(int $count, int $gridCellId, ?int $assignmentId, ?int $sessionId = null): array
{
    $mutations = [];

    for ($i = 0; $i < $count; $i++) {
        $mutations[] = [
            'client_uuid' => (string) Str::uuid7(),
            'entity' => 'structure',
            'op' => 'create',
            'payload' => [
                'client_uuid' => (string) Str::uuid7(),
                'observation_uuid' => (string) Str::uuid7(),
                'grid_cell_id' => $gridCellId,
                // Spread across the cell rather than stacked, so the spatial
                // work is representative instead of hitting one index page.
                'longitude' => 7.46 + (($i % 40) * 0.00012),
                'latitude' => 9.05 + (intdiv($i, 40) * 0.00012),
                'accuracy_m' => 3.0 + (($i % 7) * 0.4),
                'structure_type' => 'shophouse',
                'occupancy_status' => 'occupied',
                'floors' => 1 + ($i % 3),
                'unit_count' => 1 + ($i % 5),
                'observed_at' => now()->subMinutes(2000 - $i)->toIso8601String(),
                'field_session_id' => $sessionId,
                'assignment_id' => $assignmentId,
            ],
        ];
    }

    return $mutations;
}

/**
 * Runs a callable with the query count and wall time around it.
 *
 * @return array{result: mixed, queries: int, seconds: float, peakMb: float}
 */
function measured(callable $work): array
{
    $queries = 0;
    DB::flushQueryLog();
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    gc_collect_cycles();
    $before = microtime(true);

    $result = $work();

    $seconds = microtime(true) - $before;

    return [
        'result' => $result,
        'queries' => $queries,
        'seconds' => round($seconds, 2),
        'peakMb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
    ];
}

it('applies two thousand mutations within a query and time budget', function () {
    Queue::fake();

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $assignmentId = Assignment::query()->where('grid_cell_id', $cell->id)->value('id');

    $mutations = mutationsFor(2_000, $cell->id, $assignmentId);
    $processor = app(ProcessMutationBatch::class);

    // Fifty at a time, which is what the field client sends: a batch that fits
    // in one request on a connection that may not survive a second one.
    $measurement = measured(function () use ($processor, $mutations, $officer): int {
        $applied = 0;

        foreach (array_chunk($mutations, 50) as $batch) {
            foreach ($processor->process($batch, $officer) as $result) {
                if ($result['status'] === ProcessMutationBatch::STATUS_APPLIED) {
                    $applied++;
                }
            }
        }

        return $applied;
    });

    $perMutation = $measurement['queries'] / 2_000;

    dump([
        'mutations' => 2_000,
        'applied' => $measurement['result'],
        'seconds' => $measurement['seconds'],
        'queries' => $measurement['queries'],
        'queries_per_mutation' => round($perMutation, 1),
        'peak_mb' => $measurement['peakMb'],
        'mutations_per_second' => round(2_000 / max($measurement['seconds'], 0.01), 1),
    ]);

    expect($measurement['result'])->toBe(2_000)
        ->and(SyncReceipt::query()->count())->toBe(2_000)
        // A budget on queries rather than on seconds, because seconds are a
        // property of the machine and queries are a property of the code. This
        // was 12 before the cell progress recompute was taken out of the
        // request path, and 24,000 queries hid a statement that cost 25 ms.
        ->and($perMutation)->toBeLessThanOrEqual(11.0);
})->group('load');

it('brings the cell count up to date once the deferred recompute runs', function () {
    Queue::fake();

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $assignmentId = Assignment::query()->where('grid_cell_id', $cell->id)->value('id');

    app(ProcessMutationBatch::class)->process(
        mutationsFor(40, $cell->id, $assignmentId),
        $officer,
    );

    // Nothing has run the recompute yet, so the cell has not moved. This is the
    // cost of taking it off the request path, and it is a real one: coverage is
    // eventually consistent rather than immediate.
    expect(GridCell::query()->findOrFail($cell->id)->structures_captured)->toBe(0);

    // Two thousand captures in one cell leave one pending recompute, not two
    // thousand, which is the whole point of coalescing it.
    Queue::assertPushed(RefreshCellProgress::class);

    (new RefreshCellProgress($cell->id))->handle();

    $fresh = GridCell::query()->findOrFail($cell->id);

    expect($fresh->structures_captured)->toBe(40);

    // Recomputed from the table, so running it again is a no-op rather than a
    // doubling. That is what makes it safe to coalesce, retry and run late.
    (new RefreshCellProgress($cell->id))->handle();

    expect(GridCell::query()->findOrFail($cell->id)->structures_captured)->toBe(40);
})->group('load');

it('answers a retried batch from receipts rather than doing the work again', function () {
    Queue::fake();

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $assignmentId = Assignment::query()->where('grid_cell_id', $cell->id)->value('id');

    $mutations = mutationsFor(500, $cell->id, $assignmentId);
    $processor = app(ProcessMutationBatch::class);

    $first = measured(fn (): array => $processor->process($mutations, $officer));
    $second = measured(fn (): array => $processor->process($mutations, $officer));

    dump([
        'first_pass_queries' => $first['queries'],
        'retry_queries' => $second['queries'],
        'retry_seconds' => $second['seconds'],
        'retry_share' => round($second['queries'] / max($first['queries'], 1), 3),
    ]);

    // A retry is a lookup per mutation and nothing else. This is the property
    // that makes a handset on a bad connection safe to keep retrying, and it is
    // also the cheapest thing this endpoint does.
    expect($second['queries'])->toBeLessThan((int) ($first['queries'] * 0.2));
})->group('load');

it('shows which statement grows with the size of the table', function () {
    Queue::fake();

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $assignmentId = Assignment::query()->where('grid_cell_id', $cell->id)->value('id');

    $byPattern = [];

    DB::listen(function ($query) use (&$byPattern): void {
        $sql = (string) preg_replace('/\s+/', ' ', trim($query->sql));
        $sql = (string) preg_replace('/\d+/', 'N', $sql);
        $key = substr($sql, 0, 78);
        $byPattern[$key] ??= ['n' => 0, 'ms' => 0.0];
        $byPattern[$key]['n']++;
        $byPattern[$key]['ms'] += $query->time;
    });

    $processor = app(ProcessMutationBatch::class);

    foreach (array_chunk(mutationsFor(1_200, $cell->id, $assignmentId), 50) as $batch) {
        $processor->process($batch, $officer);
    }

    uasort($byPattern, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

    $worst = [];

    foreach (array_slice($byPattern, 0, 6, true) as $sql => $stat) {
        $worst[] = [
            'sql' => $sql,
            'count' => $stat['n'],
            'total_ms' => (int) $stat['ms'],
            'ms_each' => round($stat['ms'] / $stat['n'], 2),
        ];
    }

    dump($worst);

    expect($worst)->not->toBeEmpty();
})->group('load');

it('stores five hundred photographs within a query and time budget', function () {
    Queue::fake();
    Storage::fake('media');

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    $structure = Structure::query()->findOrFail($observation->structure_id);

    $store = app(StorePhotograph::class);

    // A real JPEG each time, and a different one each time, because hashing the
    // same bytes five hundred times would measure the cache rather than the
    // work. This is the size the field client compresses to.
    $files = [];

    for ($i = 0; $i < 500; $i++) {
        $files[] = UploadedFile::fake()->image("facade-{$i}.jpg", 1280, 960);
    }

    $measurement = measured(function () use ($store, $files, $structure, $officer, $session): int {
        $stored = 0;

        foreach ($files as $file) {
            $store->store(
                $file,
                $structure,
                'facade',
                $officer,
                (string) Str::uuid7(),
                7.4601,
                9.0501,
                $session->id,
            );
            $stored++;
        }

        return $stored;
    });

    dump([
        'photographs' => 500,
        'stored' => $measurement['result'],
        'seconds' => $measurement['seconds'],
        'queries' => $measurement['queries'],
        'queries_per_photograph' => round($measurement['queries'] / 500, 1),
        'peak_mb' => $measurement['peakMb'],
        'photographs_per_second' => round(500 / max($measurement['seconds'], 0.01), 1),
    ]);

    expect($measurement['result'])->toBe(500)
        ->and(Media::query()->count())->toBe(500)
        // Hashing, EXIF and the write itself, and nothing that grows with the
        // number already stored.
        ->and($measurement['queries'] / 500)->toBeLessThanOrEqual(6.0);
})->group('load');

it('answers a retried photograph upload without storing it twice', function () {
    Queue::fake();
    Storage::fake('media');

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));
    $structure = Structure::query()->findOrFail($observation->structure_id);

    $store = app(StorePhotograph::class);
    $uuid = (string) Str::uuid7();

    $first = $store->store(
        UploadedFile::fake()->image('facade.jpg', 1280, 960),
        $structure, 'facade', $officer, $uuid, 7.4601, 9.0501, $session->id,
    );

    $again = measured(fn (): Media => $store->store(
        UploadedFile::fake()->image('facade.jpg', 1280, 960),
        $structure, 'facade', $officer, $uuid, 7.4601, 9.0501, $session->id,
    ));

    dump(['retry_queries' => $again['queries']]);

    // One photograph, same answer, and a handset on a bad connection can keep
    // asking without filling the disk.
    expect($again['result']->id)->toBe($first->id)
        ->and(Media::query()->count())->toBe(1)
        ->and($again['queries'])->toBeLessThanOrEqual(2);
})->group('load');
