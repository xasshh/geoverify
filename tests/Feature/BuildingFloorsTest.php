<?php

declare(strict_types=1);

use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Registry\Enums\StructureType;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Actions\AssembleReviewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * How many storeys, and what is on each of them.
 *
 * The register has always had the columns. What it did not have was anyone
 * filling them: the field client sent no storey count and had no way to say
 * which floor a business traded on, so a three storey plaza and a lock up shop
 * were the same record. These cover the whole path, because the value is only
 * worth anything if it survives the offline queue as well as the live post.
 */

/**
 * A detected outline under the capture point, so the structure inherits a real
 * footprint. Without one there is nothing for the massing to draw, which is
 * itself a case worth covering.
 */
function footprintUnderCapture(int $gridCellId): int
{
    // Inserted with its geometry in one statement: the column is NOT NULL, and
    // a footprint without an outline is not a footprint. Roughly 14 m by 9 m at
    // this latitude, written through PostGIS like every other geometry here.
    $row = DB::selectOne(
        "insert into external_footprints
            (source, source_id, confidence, area_m2, grid_cell_id, dismissed, footprint, created_at, updated_at)
         values (?, ?, 0.91, 120.0, ?, false, st_geomfromtext(
            'POLYGON((7.4600 9.0500, 7.46013 9.0500, 7.46013 9.05008, 7.4600 9.05008, 7.4600 9.0500))', 4326
         ), now(), now())
         returning id",
        ['google_open_buildings', (string) Str::uuid7(), $gridCellId]
    );

    return (int) $row->id;
}

it('records the storeys an officer counted, on the observation and the register', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $id = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell, ['floors' => 3]))
        ->assertCreated()
        ->json('id');

    $structure = Structure::query()->findOrFail($id);

    expect($structure->floors)->toBe(3)
        // The observation is the authority; the column on structures is its
        // projection. A revisit that finds a fourth storey must not rewrite what
        // was true on this visit.
        ->and($structure->observations()->latest('observed_at')->firstOrFail()->floors)->toBe(3);
});

it('records which storey a business trades on', function () {
    seedSectors();
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell, ['floors' => 3]))
        ->json('id');

    $this->actingAs($officer)
        ->postJson('/api/field/enterprises', [
            'client_uuid' => (string) Str::uuid7(),
            'observation_uuid' => (string) Str::uuid7(),
            'structure_id' => $structureId,
            'unit_label' => 'F02',
            'floor' => 2,
            'trading_name' => 'Adeyemi Chambers',
            'sector_code' => '4711',
            'observed_at' => now()->toIso8601String(),
        ])
        ->assertCreated();

    expect(Enterprise::query()->firstOrFail()->floor)->toBe(2);
});

it('keeps the ground floor as a recorded answer rather than an empty one', function () {
    seedSectors();
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell, ['floors' => 2]))
        ->json('id');

    $this->actingAs($officer)
        ->postJson('/api/field/enterprises', [
            'client_uuid' => (string) Str::uuid7(),
            'observation_uuid' => (string) Str::uuid7(),
            'structure_id' => $structureId,
            'floor' => 0,
            'trading_name' => 'Mama Ngozi Provisions',
            'sector_code' => '4711',
            'observed_at' => now()->toIso8601String(),
        ])
        ->assertCreated();

    // Zero is the most common answer in the register. Read as falsy anywhere on
    // the path it would become "nobody said", and the ground floor would empty
    // itself out.
    expect(Enterprise::query()->firstOrFail()->floor)->toBe(0);
});

it('does not wipe a known storey when an older handset syncs without one', function () {
    seedSectors();
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell, ['floors' => 3]))
        ->json('id');

    $clientUuid = (string) Str::uuid7();

    $this->actingAs($officer)->postJson('/api/field/enterprises', [
        'client_uuid' => $clientUuid,
        'observation_uuid' => (string) Str::uuid7(),
        'structure_id' => $structureId,
        'floor' => 2,
        'trading_name' => 'Adeyemi Chambers',
        'sector_code' => '4711',
        'observed_at' => now()->toIso8601String(),
    ])->assertCreated();

    // The shipped client has no floor field and never will. Its revisits must
    // not quietly empty the column as the fleet syncs.
    $this->actingAs($officer)->postJson('/api/field/enterprises', [
        'client_uuid' => $clientUuid,
        'observation_uuid' => (string) Str::uuid7(),
        'structure_id' => $structureId,
        'trading_name' => 'Adeyemi Chambers Ltd',
        'sector_code' => '4711',
        'observed_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated();

    $enterprise = Enterprise::query()->firstOrFail();

    expect($enterprise->trading_name)->toBe('Adeyemi Chambers Ltd')
        ->and($enterprise->floor)->toBe(2);
});

it('carries the storey through the offline queue, not only the live post', function () {
    seedSectors();
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureUuid = uuidAt(0);
    $enterpriseUuid = uuidAt(1);

    $this->actingAs($officer)->postJson('/api/field/sync', [
        'mutations' => [
            structureMutation($cell->id, $structureUuid, ['floors' => 4]),
            [
                'client_uuid' => $enterpriseUuid,
                'entity' => 'enterprise',
                'op' => 'create',
                'payload' => [
                    'client_uuid' => $enterpriseUuid,
                    'observation_uuid' => (string) Str::uuid7(),
                    'structure_client_uuid' => $structureUuid,
                    'floor' => 3,
                    'trading_name' => 'Kano Textiles',
                    'sector_code' => '4711',
                    'observed_at' => now()->toIso8601String(),
                ],
            ],
        ],
    ])->assertOk();

    expect(Structure::query()->firstOrFail()->floors)->toBe(4)
        ->and(Enterprise::query()->firstOrFail()->floor)->toBe(3);
});

it('does not ask for storeys on the things that are not buildings', function () {
    expect(StructureType::Kiosk->expectsFloors())->toBeFalse()
        ->and(StructureType::UmbrellaStand->expectsFloors())->toBeFalse()
        ->and(StructureType::Mobile->expectsFloors())->toBeFalse()
        ->and(StructureType::Shophouse->expectsFloors())->toBeTrue()
        ->and(StructureType::CommercialBlock->expectsFloors())->toBeTrue();
});

it('gives the review screen the outline in metres, about its own centre', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structure = app(CaptureStructure::class)->capture(
        captureFor($cell, [
            'floors' => 3,
            'external_footprint_id' => footprintUnderCapture($cell->id),
        ]),
        $officer,
    );

    $record = app(AssembleReviewRecord::class)(
        $structure->observations()->latest('observed_at')->firstOrFail()
    );

    expect($record['footprint'])->not->toBeNull();

    /** @var array<string, mixed> $footprint */
    $footprint = $record['footprint'];

    // Metres on the ground, from the UTM zone the building stands in. Degrees
    // or Mercator metres would both be wrong here, and wrong in a way that
    // draws a building of the wrong shape.
    expect($footprint['widthM'])->toBeGreaterThan(10.0)
        ->and($footprint['widthM'])->toBeLessThan(20.0)
        ->and($footprint['depthM'])->toBeGreaterThan(5.0)
        ->and($footprint['depthM'])->toBeLessThan(14.0)
        ->and($footprint['ring'])->toBeArray()
        ->and(count($footprint['ring']))->toBeGreaterThanOrEqual(4);
});

it('offers no outline for a kiosk, rather than inventing one', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structure = app(CaptureStructure::class)->capture(
        captureFor($cell, ['structure_type' => 'kiosk', 'floors' => null]),
        $officer,
    );

    $record = app(AssembleReviewRecord::class)(
        $structure->observations()->latest('observed_at')->firstOrFail()
    );

    expect($record['footprint'])->toBeNull()
        ->and($record['floors'])->toBeNull();
});

it('tells the review screen which storey each business is on', function () {
    seedSectors();
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structureId = $this->actingAs($officer)
        ->postJson('/api/field/structures', structurePayload($cell, ['floors' => 2]))
        ->json('id');

    foreach ([['Ground Provisions', 0], ['Upstairs Tailor', 1]] as [$name, $floor]) {
        $this->actingAs($officer)->postJson('/api/field/enterprises', [
            'client_uuid' => (string) Str::uuid7(),
            'observation_uuid' => (string) Str::uuid7(),
            'structure_id' => $structureId,
            'floor' => $floor,
            'trading_name' => $name,
            'sector_code' => '4711',
            'observed_at' => now()->toIso8601String(),
        ])->assertCreated();
    }

    $structure = Structure::query()->findOrFail($structureId);
    $record = app(AssembleReviewRecord::class)(
        $structure->observations()->latest('observed_at')->firstOrFail()
    );

    /** @var list<array<string, mixed>> $enterprises */
    $enterprises = $record['enterprises'];
    $byName = collect($enterprises)->keyBy('tradingName');

    expect($byName['Ground Provisions']['floor'])->toBe(0)
        ->and($byName['Upstairs Tailor']['floor'])->toBe(1);
});
