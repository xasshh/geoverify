<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Models\FieldSession;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The slice of the sector taxonomy these tests need.
 *
 * ISIC is reference data loaded by geoverify:taxonomy-load, not by a migration,
 * so a refreshed test database has none of it. Seeding the handful of rows a
 * test actually exercises keeps it self contained without loading 766 classes
 * for every case.
 */
function seedSectors(): void
{
    DB::table('isic_classes')->insert([
        ['code' => '47', 'level' => 'division', 'parent_code' => null,
            'name' => 'Retail trade, except of motor vehicles and motorcycles',
            'created_at' => now(), 'updated_at' => now()],
        ['code' => '4711', 'level' => 'class', 'parent_code' => '471',
            'name' => 'Retail sale in non-specialized stores with food, beverages or tobacco predominating',
            'created_at' => now(), 'updated_at' => now()],
        ['code' => '45', 'level' => 'division', 'parent_code' => null,
            'name' => 'Wholesale and retail trade and repair of motor vehicles and motorcycles',
            'created_at' => now(), 'updated_at' => now()],
        ['code' => '4520', 'level' => 'class', 'parent_code' => '452',
            'name' => 'Maintenance and repair of motor vehicles',
            'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('trade_aliases')->insert([
        ['term' => 'Provisions store', 'isic_code' => '4711', 'weight' => 200,
            'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
        ['term' => 'Vulcanizer', 'isic_code' => '4520', 'weight' => 230,
            'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

/** @return array{officer: User, cell: GridCell} */
function fieldSetup(): array
{
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    return ['officer' => $officer, 'cell' => $cell];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function structurePayload(GridCell $cell, array $overrides = []): array
{
    return array_merge([
        'client_uuid' => (string) Str::uuid7(),
        'observation_uuid' => (string) Str::uuid7(),
        'grid_cell_id' => $cell->id,
        'longitude' => 7.46,
        'latitude' => 9.05,
        'accuracy_m' => 4.2,
        'structure_type' => 'shophouse',
        'occupancy_status' => 'occupied',
        'unit_count' => 14,
        'observed_at' => now()->toIso8601String(),
    ], $overrides);
}

it('captures a structure and tells the officer which ward the server chose', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell))
        ->assertCreated()
        ->assertJsonStructure(['id', 'client_uuid', 'status', 'resolved' => ['ward', 'lga', 'state']]);

    expect(Structure::query()->count())->toBe(1);
});

it('refuses a capture in a cell the officer does not hold', function () {
    $officer = person(Role::Officer);
    $otherOfficer = person(Role::Officer, 'Someone else');
    $cell = assignedCell($otherOfficer, person(Role::Supervisor));

    $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell))
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not assigned to you'));

    expect(Structure::query()->count())->toBe(0);
});

it('creates one structure when the same submission arrives three times', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $payload = structurePayload($cell);

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($officer)->postJson('/api/field/structures', $payload)->assertCreated();
    }

    expect(Structure::query()->count())->toBe(1);
});

it('rejects a structure type this system does not record, with a readable message', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell, ['structure_type' => 'castle']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'That is not a structure type this system records.');
});

it('captures a business inside a structure and files it under an ISIC class', function () {
    seedSectors();
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell))
        ->json('id');

    $this->actingAs($officer)
        ->postJson('/api/field/enterprises', [
            'client_uuid' => (string) Str::uuid7(),
            'observation_uuid' => (string) Str::uuid7(),
            'structure_id' => $structureId,
            'unit_label' => 'G01',
            'trading_name' => 'Mama Ngozi Provisions',
            'sector_code' => '4711',
            'scale_band' => 'micro',
            'observed_at' => now()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('sector_code', '4711');

    $enterprise = Enterprise::query()->firstOrFail();

    // The division is derived server side, so reporting at the level an
    // economist reads does not depend on the handset sending it.
    expect($enterprise->subsector_code)->toBe('47');
});

it('searches sectors by the words an officer actually uses', function () {
    seedSectors();
    $officer = person(Role::Officer);

    $results = $this->actingAs($officer)
        ->getJson('/api/field/sectors?q=vulcaniser')
        ->assertOk()
        ->json('results');

    expect($results)->not->toBeEmpty()
        ->and($results[0]['code'])->toBe('4520')
        ->and($results[0]['matchedOn'])->toBe('Vulcanizer');
});

it('stores a photograph privately and records what can be checked about it', function () {
    Storage::fake('media');
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell))
        ->json('id');

    $response = $this->actingAs($officer)->post('/api/field/photographs', [
        'client_uuid' => (string) Str::uuid7(),
        'structure_id' => $structureId,
        'kind' => 'facade',
        'photo' => UploadedFile::fake()->image('facade.jpg', 1600, 1200),
        'device_longitude' => 7.4601,
        'device_latitude' => 9.0501,
    ]);

    $response->assertCreated();

    $media = Media::query()->firstOrFail();

    expect($media->kind)->toBe('facade')
        ->and($media->disk)->toBe('media')
        ->and($media->sha256)->toHaveLength(64)
        // The distance between where the camera was and where the subject
        // stands, which is what a supervisor reads to judge a facade shot.
        ->and($media->distance_from_subject_m)->not->toBeNull();

    Storage::disk('media')->assertExists($media->disk_path);
});

it('stores one photograph when the same upload arrives twice', function () {
    Storage::fake('media');
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell))
        ->json('id');

    $uuid = (string) Str::uuid7();

    foreach (range(1, 2) as $ignored) {
        $this->actingAs($officer)->post('/api/field/photographs', [
            'client_uuid' => $uuid,
            'structure_id' => $structureId,
            'kind' => 'signage',
            'photo' => UploadedFile::fake()->image('sign.jpg', 800, 600),
        ])->assertCreated();
    }

    expect(Media::query()->count())->toBe(1);
});

it('opens one session however many times the same client uuid is sent', function () {
    $officer = person(Role::Officer);
    $uuid = (string) Str::uuid7();

    $this->actingAs($officer)->postJson('/api/field/sessions', ['client_uuid' => $uuid])->assertCreated();
    $this->actingAs($officer)->postJson('/api/field/sessions', ['client_uuid' => $uuid])->assertOk();

    expect(FieldSession::query()->count())->toBe(1);
});

it('builds a timestamped trace from the fixes it is sent', function () {
    $officer = person(Role::Officer);

    $sessionId = $this->actingAs($officer)
        ->postJson('/api/field/sessions', ['client_uuid' => (string) Str::uuid7()])
        ->json('id');

    $fixes = collect(range(0, 5))->map(fn (int $i): array => [
        'longitude' => 7.4600 + $i * 0.0002,
        'latitude' => 9.0500 + $i * 0.0001,
        'recorded_at' => now()->addSeconds($i * 10)->toIso8601String(),
        'accuracy_m' => 4.5,
        'satellite_count' => 11,
        'provider' => 'gps',
        'is_mock' => false,
    ])->all();

    $body = $this->actingAs($officer)
        ->postJson("/api/field/sessions/{$sessionId}/fixes", ['fixes' => $fixes])
        ->assertOk()
        ->json();

    expect($body['stored'])->toBe(6)
        ->and($body['distance_m'])->toBeGreaterThan(0);

    // Sending the same batch again is what a retried sync looks like.
    $replay = $this->actingAs($officer)
        ->postJson("/api/field/sessions/{$sessionId}/fixes", ['fixes' => $fixes])
        ->json();

    expect($replay['stored'])->toBe(0)
        ->and($replay['duplicates'])->toBe(6)
        ->and($replay['fix_count'])->toBe(6);
});

it('refuses to let one officer append fixes to another officer session', function () {
    $mine = person(Role::Officer);
    $theirs = person(Role::Officer, 'Someone else');

    $sessionId = $this->actingAs($mine)
        ->postJson('/api/field/sessions', ['client_uuid' => (string) Str::uuid7()])
        ->json('id');

    $this->actingAs($theirs)
        ->postJson("/api/field/sessions/{$sessionId}/fixes", [
            'fixes' => [[
                'longitude' => 7.46, 'latitude' => 9.05,
                'recorded_at' => now()->toIso8601String(),
            ]],
        ])
        ->assertForbidden();
});
