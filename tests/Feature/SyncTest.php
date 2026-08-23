<?php

declare(strict_types=1);

use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Actions\ReleaseAssignment;
use App\Domain\Field\Models\Assignment;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Sync\Models\SyncReceipt;
use App\Enums\Role;
use Illuminate\Support\Str;

/**
 * A UUID v7 with a controlled timestamp, so ordering can be asserted rather than
 * hoped for. The first 48 bits are milliseconds since the epoch, which is what
 * makes these sort into creation order.
 */
function uuidAt(int $millisecondsFromNow): string
{
    return (string) Str::uuid7(now()->addMilliseconds($millisecondsFromNow));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function structureMutation(int $gridCellId, string $uuid, array $overrides = []): array
{
    return [
        'client_uuid' => $uuid,
        'entity' => 'structure',
        'op' => 'create',
        'payload' => array_merge([
            'client_uuid' => $uuid,
            'observation_uuid' => (string) Str::uuid7(),
            'grid_cell_id' => $gridCellId,
            'longitude' => 7.46,
            'latitude' => 9.05,
            'accuracy_m' => 4.2,
            'structure_type' => 'shophouse',
            'occupancy_status' => 'occupied',
            'unit_count' => 3,
            'observed_at' => now()->toIso8601String(),
        ], $overrides),
    ];
}

/** @return array<string, mixed> */
function enterpriseMutation(string $uuid, string $parentUuid, string $name = 'Mama Ngozi Provisions'): array
{
    return [
        'client_uuid' => $uuid,
        'entity' => 'enterprise',
        'op' => 'create',
        'payload' => [
            'client_uuid' => $uuid,
            'observation_uuid' => (string) Str::uuid7(),
            // The handset knows its building only by the uuid it generated.
            'structure_client_uuid' => $parentUuid,
            'trading_name' => $name,
            'observed_at' => now()->toIso8601String(),
        ],
    ];
}

it('applies a batch and reports on every mutation in it', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structureUuid = uuidAt(0);

    $body = $this->actingAs($officer)
        ->postJson('/api/field/sync', [
            'mutations' => [
                structureMutation($cell->id, $structureUuid),
                enterpriseMutation(uuidAt(10), $structureUuid),
            ],
        ])
        ->assertOk()
        ->json();

    expect($body['accepted'])->toBe(2)
        ->and($body['results'][0]['status'])->toBe('applied')
        ->and($body['results'][1]['status'])->toBe('applied')
        ->and(Structure::query()->count())->toBe(1)
        ->and(Enterprise::query()->count())->toBe(1);
});

it('creates one record when the same batch is submitted three times', function () {
    // A device on a bad connection cannot tell a lost response from lost work,
    // so it retries. This is the property that makes that safe.
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structureUuid = uuidAt(0);

    $batch = [
        'mutations' => [
            structureMutation($cell->id, $structureUuid),
            enterpriseMutation(uuidAt(10), $structureUuid),
        ],
    ];

    $first = $this->actingAs($officer)->postJson('/api/field/sync', $batch)->json();
    $second = $this->actingAs($officer)->postJson('/api/field/sync', $batch)->json();
    $third = $this->actingAs($officer)->postJson('/api/field/sync', $batch)->json();

    expect(Structure::query()->count())->toBe(1)
        ->and(Enterprise::query()->count())->toBe(1)
        // Every attempt is told the same thing, and told it succeeded.
        ->and($second['results'][0]['status'])->toBe('duplicate')
        ->and($third['results'][0]['status'])->toBe('duplicate')
        ->and($second['results'][0]['id'])->toBe($first['results'][0]['id'])
        ->and($third['results'][0]['id'])->toBe($first['results'][0]['id'])
        ->and($second['accepted'])->toBe(2);
});

it('applies mutations in creation order even when they arrive shuffled', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structureUuid = uuidAt(0);
    $enterpriseUuid = uuidAt(50);

    // The business first, the building second. UUID v7 carries the order, so the
    // server does not need the client to have sorted them.
    $body = $this->actingAs($officer)
        ->postJson('/api/field/sync', [
            'mutations' => [
                enterpriseMutation($enterpriseUuid, $structureUuid),
                structureMutation($cell->id, $structureUuid),
            ],
        ])
        ->json();

    expect($body['accepted'])->toBe(2)
        ->and(Enterprise::query()->count())->toBe(1)
        ->and(Enterprise::query()->firstOrFail()->structure_id)
        ->toBe(Structure::query()->firstOrFail()->id);
});

it('holds a business whose building has not arrived, rather than failing it', function () {
    ['officer' => $officer] = fieldSetup();

    $body = $this->actingAs($officer)
        ->postJson('/api/field/sync', [
            'mutations' => [enterpriseMutation(uuidAt(0), (string) Str::uuid7())],
        ])
        ->json();

    expect($body['results'][0]['status'])->toBe('deferred')
        ->and($body['accepted'])->toBe(0)
        // No receipt, so the client keeps it queued and tries again once the
        // building has landed.
        ->and(SyncReceipt::query()->count())->toBe(0)
        ->and(Enterprise::query()->count())->toBe(0);
});

it('accepts the held business once its building arrives in a later batch', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structureUuid = uuidAt(0);
    $enterpriseUuid = uuidAt(50);

    $this->actingAs($officer)->postJson('/api/field/sync', [
        'mutations' => [enterpriseMutation($enterpriseUuid, $structureUuid)],
    ])->assertOk();

    $this->actingAs($officer)->postJson('/api/field/sync', [
        'mutations' => [structureMutation($cell->id, $structureUuid)],
    ])->assertOk();

    $body = $this->actingAs($officer)->postJson('/api/field/sync', [
        'mutations' => [enterpriseMutation($enterpriseUuid, $structureUuid)],
    ])->json();

    expect($body['results'][0]['status'])->toBe('applied')
        ->and(Enterprise::query()->count())->toBe(1);
});

it('does not let one bad mutation reject the rest of the batch', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $body = $this->actingAs($officer)
        ->postJson('/api/field/sync', [
            'mutations' => [
                structureMutation($cell->id, uuidAt(0)),
                // A type this system does not record. One bad row must not cost
                // an officer the rest of their day.
                structureMutation($cell->id, uuidAt(10), ['structure_type' => 'castle']),
                structureMutation($cell->id, uuidAt(20)),
            ],
        ])
        ->assertOk()
        ->json();

    expect($body['accepted'])->toBe(2)
        ->and($body['results'][1]['status'])->toBe('failed')
        ->and(Structure::query()->count())->toBe(2);
});

it('records a failure so the client stops retrying what will never work', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $uuid = uuidAt(0);
    $bad = structureMutation($cell->id, $uuid, ['structure_type' => 'castle']);

    $this->actingAs($officer)->postJson('/api/field/sync', ['mutations' => [$bad]]);
    $second = $this->actingAs($officer)->postJson('/api/field/sync', ['mutations' => [$bad]])->json();

    expect($second['results'][0]['status'])->toBe('duplicate')
        ->and(SyncReceipt::query()->where('status', 'failed')->count())->toBe(1);
});

it('treats an edited record as new work rather than a retry', function () {
    // Same uuid, different payload. The officer corrected something, which is a
    // real change and must not be swallowed as a duplicate.
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $uuid = uuidAt(0);

    $this->actingAs($officer)->postJson('/api/field/sync', [
        'mutations' => [structureMutation($cell->id, $uuid, ['unit_count' => 3])],
    ]);

    $body = $this->actingAs($officer)->postJson('/api/field/sync', [
        'mutations' => [structureMutation($cell->id, $uuid, ['unit_count' => 14])],
    ])->json();

    expect($body['results'][0]['status'])->toBe('applied')
        ->and(Structure::query()->count())->toBe(1)
        ->and(Structure::query()->firstOrFail()->unit_count)->toBe(14)
        // Both submissions are on record, which is what the receipt table is for.
        ->and(SyncReceipt::query()->count())->toBe(2);
});

it('refuses work for a cell that was reassigned while the officer was offline', function () {
    // The realistic case: an officer works a cell all morning with no signal,
    // and a supervisor moves it to someone else meanwhile. The work must not be
    // silently written under an assignment that no longer exists.
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $supervisor = person(Role::Supervisor, 'Reassigning supervisor');

    app(AssignCells::class)->assign([$cell->id], person(Role::Officer, 'New holder'), $supervisor);

    $body = $this->actingAs($officer)
        ->postJson('/api/field/sync', ['mutations' => [structureMutation($cell->id, uuidAt(0))]])
        ->json();

    expect($body['results'][0]['status'])->toBe('failed')
        ->and(Structure::query()->count())->toBe(0);
});

it('refuses work for a cell that was released entirely', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $supervisor = person(Role::Supervisor, 'Releasing supervisor');

    app(ReleaseAssignment::class)->release(
        Assignment::query()->where('grid_cell_id', $cell->id)->firstOrFail(),
        $supervisor,
    );

    $body = $this->actingAs($officer)
        ->postJson('/api/field/sync', ['mutations' => [structureMutation($cell->id, uuidAt(0))]])
        ->json();

    expect($body['results'][0]['status'])->toBe('failed')
        ->and(Structure::query()->count())->toBe(0);
});

it('rejects an entity this system does not sync', function () {
    ['officer' => $officer] = fieldSetup();

    $this->actingAs($officer)
        ->postJson('/api/field/sync', [
            'mutations' => [[
                'client_uuid' => uuidAt(0),
                'entity' => 'invoice',
                'payload' => ['anything' => true],
            ]],
        ])
        ->assertStatus(422);
});

it('keeps a supervisor out of the sync endpoint', function () {
    $this->actingAs(person(Role::Supervisor))
        ->postJson('/api/field/sync', ['mutations' => []])
        ->assertRedirect('/console/coverage');
});
