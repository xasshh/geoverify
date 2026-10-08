<?php

declare(strict_types=1);

use App\Domain\AreaCapture\Actions\CaptureAreaFeature;
use App\Domain\AreaCapture\Actions\ScoreAreaCapture;
use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Actions\ConfigureCapture;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Actions\AssignCells;
use App\Domain\Field\Models\FieldSession;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\FeatureClassTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Area capture, stage 3: drawing the land, syncing it, and judging it.
 *
 * What matters: a shape is accepted only if PostGIS says it is a good one and
 * it sits where the officer may work; area and length come from the server;
 * the sync contract's idempotency holds for the new entity exactly as for
 * buildings; and a walk that was not walked scores like one.
 */
beforeEach(function () {
    Storage::fake('media');
    Queue::fake();
    $this->seed(FeatureClassTemplateSeeder::class);
});

/**
 * An area campaign over the test mandate, with an officer holding the cell at
 * its centre.
 *
 * @return array{officer: User, campaign: Campaign, cell: GridCell, centre: array{0: float, 1: float}}
 */
function areaGround(?float $minimumHa = null): array
{
    $campaign = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['buildings', 'area_features'], 'min_mapping_unit_ha' => $minimumHa], null);

    $area = testMandate();
    $area->update(['campaign_id' => $campaign->id]);
    app(GenerateGrid::class)->generate($area, 9);

    $cell = GridCell::query()
        ->where('coverage_area_id', $area->id)
        ->orderByRaw('ST_Distance(centroid, ST_SetSRID(ST_Point(7.47, 9.055), 4326)::geography)')
        ->firstOrFail();

    $officer = person(Role::Officer);
    app(AssignCells::class)->assign([$cell->id], $officer, person(Role::Supervisor));

    $centre = DB::selectOne('SELECT ST_X(centroid::geometry) AS x, ST_Y(centroid::geometry) AS y FROM grid_cells WHERE id = ?', [$cell->id]);

    return ['officer' => $officer, 'campaign' => $campaign->refresh(), 'cell' => $cell, 'centre' => [(float) $centre->x, (float) $centre->y]];
}

function areaClass(Campaign $campaign, string $key): FeatureClass
{
    return FeatureClass::query()->where('campaign_id', $campaign->id)->where('key', $key)->with('latestVersion')->firstOrFail();
}

/**
 * A square of the given half-width in degrees, centred near the cell centre.
 *
 * @param  array{0: float, 1: float}  $centre
 * @return array{type: string, coordinates: list<list<array{0: float, 1: float}>>}
 */
function square(array $centre, float $half = 0.0004, float $dx = 0, float $dy = 0): array
{
    [$x, $y] = [$centre[0] + $dx, $centre[1] + $dy];

    return ['type' => 'Polygon', 'coordinates' => [[[$x - $half, $y - $half], [$x + $half, $y - $half], [$x + $half, $y + $half], [$x - $half, $y + $half], [$x - $half, $y - $half]]]];
}

/**
 * @param  array{officer: User, campaign: Campaign, cell: GridCell, centre: array{0: float, 1: float}}  $ground
 * @param  array<string, mixed>  $geometry
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function areaInput(array $ground, string $classKey, array $geometry, array $overrides = []): array
{
    $class = areaClass($ground['campaign'], $classKey);
    $assignment = DB::table('assignments')->where('grid_cell_id', $ground['cell']->id)->whereNull('closed_at')->value('id');

    $answers = [
        'farmland' => ['status' => 'Cultivated'],
        'river_stream' => ['flow' => 'Permanent'],
        'water_point' => ['kind' => 'Spring', 'functional' => true],
        'forest_woodland' => ['forest_type' => 'Plantation', 'canopy_density' => 'Open (10 to 40%)'],
    ][$classKey] ?? [];

    return array_merge([
        'client_uuid' => (string) Str::uuid7(),
        'feature_uuid' => (string) Str::uuid7(),
        'feature_class_id' => $class->id,
        'class_version' => $class->latestVersion?->version,
        'capture_method' => 'field_drawn',
        'assignment_id' => $assignment,
        'geometry' => $geometry,
        'answers' => $answers,
        'gps_accuracy_m' => 5,
    ], $overrides);
}

it('records a farm, measured by PostGIS, linked to its cells and verified by being there', function () {
    $ground = areaGround();

    $revision = app(CaptureAreaFeature::class)(areaInput($ground, 'farmland', square($ground['centre'])), $ground['officer']);
    $feature = $revision->feature;

    $expectedHa = (float) DB::scalar('SELECT ST_Area(ST_GeomFromGeoJSON(?)::geography) / 10000', [json_encode(square($ground['centre']))]);

    expect((float) $revision->area_ha)->toEqualWithDelta($expectedHa, 0.001)
        ->and($revision->length_m)->toBeNull()
        ->and($feature->verification_status)->toBe(AreaFeature::VERIFICATION_VERIFIED)
        ->and($feature->current_revision_id)->toBe($revision->id)
        ->and(DB::table('area_feature_cells')->where('area_feature_id', $feature->id)->pluck('grid_cell_id')->all())->toContain($ground['cell']->id);
});

it('computes a river\'s length on the server, whatever the phone thought', function () {
    $ground = areaGround();
    [$x, $y] = $ground['centre'];
    $line = ['type' => 'LineString', 'coordinates' => [[$x - 0.0005, $y], [$x, $y + 0.0002], [$x + 0.0005, $y]]];

    $revision = app(CaptureAreaFeature::class)(areaInput($ground, 'river_stream', $line, ['length_m' => 1]), $ground['officer']);

    expect((float) $revision->length_m)->toBeGreaterThan(100)->and($revision->area_ha)->toBeNull();
});

it('refuses a shape that is not good enough', function (string $class, Closure $shape, string $message) {
    $ground = areaGround(minimumHa: 0.5);

    expect(fn () => app(CaptureAreaFeature::class)(areaInput($ground, $class, $shape($ground['centre'])), $ground['officer']))
        ->toThrow(ValidationException::class, $message);
})->with([
    'a line that crosses itself' => ['river_stream', fn (array $c) => ['type' => 'LineString', 'coordinates' => [[$c[0] - 0.0004, $c[1]], [$c[0] + 0.0004, $c[1]], [$c[0], $c[1] + 0.0004], [$c[0], $c[1] - 0.0004]]], 'may not cross itself'],
    'a bow tie' => ['farmland', fn (array $c) => ['type' => 'Polygon', 'coordinates' => [[[$c[0] - 0.003, $c[1] - 0.003], [$c[0] + 0.003, $c[1] + 0.003], [$c[0] + 0.003, $c[1] - 0.003], [$c[0] - 0.003, $c[1] + 0.003], [$c[0] - 0.003, $c[1] - 0.003]]]], 'Redraw'],
    'below the minimum mapping unit' => ['farmland', fn (array $c) => square($c, 0.0002), 'smaller than the 0.5 ha'],
    'a point where an area is wanted' => ['farmland', fn (array $c) => ['type' => 'Point', 'coordinates' => $c], 'drawn as area'],
    'outside the officer\'s cells' => ['farmland', fn (array $c) => square($c, 0.003, dx: 0.02), 'outside your assigned cells'],
    'outside the campaign' => ['farmland', fn (array $c) => square($c, 0.003, dx: 0.2), 'outside the campaign'],
]);

it('refuses a land cover overlapping another, and allows a river across it', function () {
    $ground = areaGround();
    $first = app(CaptureAreaFeature::class)(areaInput($ground, 'farmland', square($ground['centre'])), $ground['officer']);

    try {
        app(CaptureAreaFeature::class)(areaInput($ground, 'forest_woodland', square($ground['centre'], dx: 0.0002)), $ground['officer']);
        $this->fail('An overlapping land cover was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors()['conflicts'] ?? [])->toBe([$first->feature->client_uuid]);
    }

    [$x, $y] = $ground['centre'];
    $river = app(CaptureAreaFeature::class)(areaInput($ground, 'river_stream', ['type' => 'LineString', 'coordinates' => [[$x - 0.0006, $y], [$x + 0.0006, $y]]]), $ground['officer']);

    expect($river->id)->toBeGreaterThan(0);
});

it('asks the questions of the version captured against, field-only ones included', function () {
    $ground = areaGround();
    [$x, $y] = $ground['centre'];

    expect(fn () => app(CaptureAreaFeature::class)(
        areaInput($ground, 'water_point', ['type' => 'Point', 'coordinates' => [$x, $y]], ['answers' => ['kind' => 'Spring']]),
        $ground['officer'],
    ))->toThrow(ValidationException::class, 'required');
});

it('keeps a revision append only, and adds a new one for a second drawing', function () {
    $ground = areaGround();
    $input = areaInput($ground, 'farmland', square($ground['centre']));
    $first = app(CaptureAreaFeature::class)($input, $ground['officer']);

    $second = app(CaptureAreaFeature::class)(
        areaInput($ground, 'farmland', square($ground['centre'], 0.0005), ['feature_uuid' => $input['feature_uuid'], 'capture_method' => 'field_verified']),
        $ground['officer'],
    );

    expect($second->area_feature_id)->toBe($first->area_feature_id)
        ->and($first->feature->refresh()->current_revision_id)->toBe($second->id)
        ->and(AreaFeatureRevision::query()->where('area_feature_id', $first->area_feature_id)->count())->toBe(2);

    expect(fn () => DB::transaction(fn () => DB::update('UPDATE area_feature_revisions SET notes = ? WHERE id = ?', ['edited', $first->id])))
        ->toThrow(QueryException::class, 'append only');
});

it('syncs an area feature through the same contract, once however often it is sent', function () {
    $ground = areaGround();
    $input = areaInput($ground, 'farmland', square($ground['centre']));
    $batch = ['mutations' => [['client_uuid' => $input['client_uuid'], 'entity' => 'area_feature', 'op' => 'create', 'payload' => $input]]];

    $first = $this->actingAs($ground['officer'])->postJson('/api/field/sync', $batch)->assertOk()->json();
    $second = $this->actingAs($ground['officer'])->postJson('/api/field/sync', $batch)->json();
    $third = $this->actingAs($ground['officer'])->postJson('/api/field/sync', $batch)->json();

    expect($first['results'][0]['status'])->toBe('applied')
        ->and($second['results'][0]['status'])->toBe('duplicate')
        ->and($third['results'][0]['status'])->toBe('duplicate')
        ->and($second['results'][0]['id'])->toBe($first['results'][0]['id'])
        ->and(AreaFeatureRevision::query()->count())->toBe(1)
        ->and(AreaFeature::query()->count())->toBe(1);
});

it('reports a refused shape as failed, so the phone stops retrying it', function () {
    $ground = areaGround();
    $input = areaInput($ground, 'farmland', square($ground['centre'], 0.003, dx: 0.2));

    $result = $this->actingAs($ground['officer'])
        ->postJson('/api/field/sync', ['mutations' => [['client_uuid' => $input['client_uuid'], 'entity' => 'area_feature', 'payload' => $input]]])
        ->json('results.0');

    expect($result['status'])->toBe('failed')->and($result['message'])->toContain('outside');
});

it('scores an honest walk at full marks and a faked one far lower', function () {
    $ground = areaGround();
    [$x, $y] = $ground['centre'];

    $walk = function (bool $fake) use ($ground, $x, $y): AreaFeatureRevision {
        $session = FieldSession::query()->create([
            'user_id' => $ground['officer']->id,
            'started_at' => now()->subMinutes(15),
            'client_uuid' => (string) Str::uuid(),
        ]);

        // Twenty fixes round the square's edge: an honest one wobbles in
        // spacing and accuracy, a fake one is spaced and reported identically.
        $elapsed = 0;

        for ($i = 0; $i < 20; $i++) {
            $elapsed += $fake ? 30 : 25 + ($i % 4) * 6;
            $t = $i / 20 * 4;
            $side = (int) floor($t);
            $f = $t - $side;
            [$px, $py] = match ($side) {
                0 => [$x - 0.0004 + 0.0008 * $f, $y - 0.0004],
                1 => [$x + 0.0004, $y - 0.0004 + 0.0008 * $f],
                2 => [$x + 0.0004 - 0.0008 * $f, $y + 0.0004],
                default => [$x - 0.0004, $y + 0.0004 - 0.0008 * $f],
            };
            $jitter = $fake ? 0 : (($i * 7) % 5 - 2) * 0.000004;

            DB::insert(
                'INSERT INTO position_fixes (field_session_id, recorded_at, accuracy_m, is_mock, point, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ST_SetSRID(ST_Point(?, ?), 4326), now(), now())',
                [$session->id, now()->subMinutes(15)->addSeconds($elapsed), $fake ? 4.0 : 3.0 + ($i % 5), $fake ? 'true' : 'false', $px + $jitter, $py - $jitter],
            );
        }

        return app(CaptureAreaFeature::class)(
            areaInput($ground, 'farmland', square($ground['centre']), [
                'capture_method' => 'field_walked',
                'field_session_client_uuid' => $session->client_uuid,
                'feature_uuid' => (string) Str::uuid7(),
            ]),
            $ground['officer'],
        );
    };

    $honest = $walk(false);
    expect(app(ScoreAreaCapture::class)($honest))->toBe(100);

    // A second farm on the same ground would overlap the first; withdraw it.
    AreaFeature::query()->whereKey($honest->area_feature_id)->update(['status' => 'withdrawn']);

    $faked = $walk(true);
    expect(app(ScoreAreaCapture::class)($faked))->toBeLessThanOrEqual(35);
});

it('holds a photograph until its capture has landed, then keeps its bearing', function () {
    $ground = areaGround();
    $input = areaInput($ground, 'farmland', square($ground['centre']));
    $photo = fn () => UploadedFile::fake()->image('farm.jpg', 800, 600);

    $this->actingAs($ground['officer'])
        ->post('/api/field/area-photographs', ['client_uuid' => (string) Str::uuid(), 'revision_client_uuid' => $input['client_uuid'], 'photo' => $photo()])
        ->assertStatus(409);

    app(CaptureAreaFeature::class)($input, $ground['officer']);

    $response = $this->actingAs($ground['officer'])
        ->post('/api/field/area-photographs', [
            'client_uuid' => (string) Str::uuid(), 'revision_client_uuid' => $input['client_uuid'], 'photo' => $photo(), 'bearing' => 271.4,
        ])
        ->assertCreated();

    expect((float) DB::table('media')->where('id', $response->json('id'))->value('bearing_deg'))->toBe(271.4);

    // Nobody else may attach photographs to it.
    $this->actingAs(person(Role::Officer, 'Another'))
        ->post('/api/field/area-photographs', ['client_uuid' => (string) Str::uuid(), 'revision_client_uuid' => $input['client_uuid'], 'photo' => $photo()])
        ->assertForbidden();
});

it('opens the area screen only for a campaign that maps land, and links to it from the building screen', function () {
    $ground = areaGround();
    $assignment = DB::table('assignments')->where('grid_cell_id', $ground['cell']->id)->value('id');

    $this->actingAs($ground['officer'])->get("/field/assignments/{$assignment}/area")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('field/AreaCapture')->has('classes', 13));

    $this->actingAs($ground['officer'])->get("/field/assignments/{$assignment}/capture")
        ->assertInertia(fn ($page) => $page->where('areaCaptureUrl', route('field.area', $assignment)));

    app(ConfigureCapture::class)($ground['campaign'], ['capture_modes' => ['buildings']], null);

    $this->actingAs($ground['officer'])->get("/field/assignments/{$assignment}/area")->assertNotFound();
    $this->actingAs($ground['officer'])->get("/field/assignments/{$assignment}/capture")
        ->assertInertia(fn ($page) => $page->where('areaCaptureUrl', null));
});
