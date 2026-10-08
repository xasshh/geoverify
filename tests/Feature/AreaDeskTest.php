<?php

declare(strict_types=1);

use App\Domain\AreaCapture\Actions\CaptureAreaFeature;
use App\Domain\AreaCapture\Actions\GenerateVerificationTasks;
use App\Domain\AreaCapture\Jobs\SeedFromLandCover;
use App\Domain\AreaCapture\LandCoverPipeline;
use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaFeatureBatch;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\AreaCapture\Models\AreaVerificationTask;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Coverage\Models\GridCell;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\FeatureClassTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Area capture, stage 4: the desk, imports, land cover, and officers sent to
 * check.
 *
 * What matters: the desk is its own role's and nobody else's; a phone can
 * never pass itself off as the desk; an import is all or nothing; the
 * verification sample is the same every time it is asked for; and a check
 * goes to, and is closed by, the officer it was given to.
 */
beforeEach(function () {
    Storage::fake('media');
    Storage::fake('local');
    Queue::fake();
    $this->seed(FeatureClassTemplateSeeder::class);
});

/**
 * @param  array{officer: User, campaign: Campaign, cell: GridCell, centre: array{0: float, 1: float}}  $ground
 * @param  array<string, mixed>  $geometry
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function deskInput(array $ground, string $classKey, array $geometry, array $overrides = []): array
{
    $class = areaClass($ground['campaign'], $classKey);

    return array_merge([
        'client_uuid' => (string) Str::uuid7(),
        'feature_uuid' => (string) Str::uuid7(),
        'feature_class_id' => $class->id,
        'class_version' => $class->latestVersion?->version,
        'capture_method' => AreaFeatureRevision::METHOD_IMPORTED,
        'coverage_area_id' => $ground['cell']->coverage_area_id,
        'geometry' => $geometry,
        'answers' => [],
    ], $overrides);
}

function digitiser(): User
{
    return person(Role::DeskDigitiser, 'Desk person');
}

it('opens the desk to digitisers and administrators, and to nobody else', function () {
    $ground = areaGround();
    $area = $ground['cell']->coverage_area_id;

    $this->actingAs(digitiser())->get('/desk')->assertOk();
    $this->actingAs(digitiser())->get("/desk/mandates/{$area}")->assertOk();
    $this->actingAs(person(Role::Admin))->get('/desk')->assertOk();
    $this->actingAs(person(Role::Supervisor))->get('/desk')->assertForbidden();
    $this->actingAs($ground['officer'])->get('/desk')->assertForbidden();

    // A digitiser supervises nothing and administers nothing.
    $this->actingAs(digitiser())->get('/admin/people')->assertForbidden();
    expect(digitiser()->supervises())->toBeFalse()
        ->and(digitiser()->capturesInTheField())->toBeFalse();
});

it('signs a digitiser in to the desk', function () {
    $user = digitiser();
    $user->forceFill(['email' => 'desk.person@geoverify.test'])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-for-tests'])->assertRedirect('/desk');
});

it('never lets a phone pass itself off as the desk', function () {
    $ground = areaGround();
    $input = deskInput($ground, 'farmland', square($ground['centre'], 0.003, dx: 0.02));

    $result = $this->actingAs($ground['officer'])
        ->postJson('/api/field/sync', ['mutations' => [['client_uuid' => $input['client_uuid'], 'entity' => 'area_feature', 'payload' => $input]]])
        ->json('results.0');

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toContain('A phone captures in the field')
        ->and(AreaFeature::query()->count())->toBe(0);
});

it('draws at the desk, asking only what imagery can answer', function () {
    $ground = areaGround();
    $class = areaClass($ground['campaign'], 'farmland');

    $this->actingAs(digitiser())
        ->post("/desk/mandates/{$ground['cell']->coverage_area_id}/features", [
            'feature_class_id' => $class->id,
            'class_version' => $class->latestVersion?->version,
            'geometry' => square($ground['centre'], 0.003, dx: 0.02),
            // Main crop and the rest are field only; status is not.
            'answers' => ['status' => 'Cultivated'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $feature = AreaFeature::query()->firstOrFail();

    expect($feature->verification_status)->toBe('unverified')
        ->and($feature->currentRevision?->capture_method)->toBe('desk_digitised');
});

it('imports all or nothing, and maps a property to classes', function () {
    $ground = areaGround();
    $area = $ground['cell']->coverage_area_id;
    $farm = areaClass($ground['campaign'], 'farmland');
    $water = areaClass($ground['campaign'], 'water_body');

    $file = fn (array $features) => UploadedFile::fake()->createWithContent('client.geojson', (string) json_encode(['type' => 'FeatureCollection', 'features' => $features]));
    $feature = fn (string $use, array $geometry) => ['type' => 'Feature', 'properties' => ['landuse' => $use], 'geometry' => $geometry];

    // One shape outside the campaign: nothing is kept.
    $bad = $this->actingAs(digitiser())->post("/desk/mandates/{$area}/import", ['file' => $file([
        $feature('farm', square($ground['centre'], 0.002, dx: 0.02)),
        $feature('farm', square($ground['centre'], 0.003, dx: 0.2)),
    ])])->assertOk()->json();

    $this->actingAs(digitiser())->post("/desk/imports/{$bad['batchId']}/commit", ['class_id' => $farm->id])->assertRedirect();

    expect(AreaFeature::query()->count())->toBe(0)
        ->and(AreaFeatureBatch::query()->find($bad['batchId'])?->status)->toBe('failed')
        ->and(AreaFeatureBatch::query()->find($bad['batchId'])?->refused_count)->toBe(1);

    $good = $this->actingAs(digitiser())->post("/desk/mandates/{$area}/import", ['file' => $file([
        $feature('farm', square($ground['centre'], 0.002, dx: 0.02)),
        $feature('pond', square($ground['centre'], 0.001, dx: -0.02)),
        $feature('unknown', square($ground['centre'], 0.001, dy: 0.015)),
    ])])->json();

    expect($good['total'])->toBe(3)->and($good['properties']['landuse'])->toBe(['farm', 'pond', 'unknown']);

    $this->actingAs(digitiser())
        ->post("/desk/imports/{$good['batchId']}/commit", ['property' => 'landuse', 'values' => ['farm' => $farm->id, 'pond' => $water->id]])
        ->assertRedirect();

    $batch = AreaFeatureBatch::query()->findOrFail($good['batchId']);

    expect($batch->status)->toBe('done')
        ->and($batch->created_count)->toBe(2)
        ->and(AreaFeatureRevision::query()->where('area_feature_batch_id', $batch->id)->count())->toBe(2)
        ->and(AreaFeature::query()->where('feature_class_id', $water->id)->count())->toBe(1);
});

it('pre-draws land cover through the same rules, mapping WorldCover classes', function () {
    $ground = areaGround();
    $c = $ground['centre'];

    app()->instance(LandCoverPipeline::class, new class($c) extends LandCoverPipeline
    {
        /** @param array{0: float, 1: float} $centre */
        public function __construct(private readonly array $centre) {}

        public function build(string $boundaryGeoJson, array $bounds, int $minimumPixels, string $workDir): string
        {
            $features = [
                ['type' => 'Feature', 'properties' => ['class' => 10], 'geometry' => square($this->centre, 0.002, dx: 0.02)],
                ['type' => 'Feature', 'properties' => ['class' => 40], 'geometry' => square($this->centre, 0.002, dx: -0.02)],
                // Outside the campaign: refused and counted, not forced in.
                ['type' => 'Feature', 'properties' => ['class' => 80], 'geometry' => square($this->centre, 0.002, dx: 0.3)],
                // Snow: not a class that maps to anything here.
                ['type' => 'Feature', 'properties' => ['class' => 70], 'geometry' => square($this->centre, 0.002, dy: 0.015)],
            ];
            file_put_contents("{$workDir}/cover.geojson", (string) json_encode(['type' => 'FeatureCollection', 'features' => $features]));

            return "{$workDir}/cover.geojson";
        }
    });

    Bus::fake([SeedFromLandCover::class]);
    $this->actingAs(digitiser())->post("/desk/mandates/{$ground['cell']->coverage_area_id}/landcover")->assertRedirect();

    $batch = AreaFeatureBatch::query()->where('kind', 'landcover')->firstOrFail();
    (new SeedFromLandCover($batch->id))->handle(app(LandCoverPipeline::class), app(CaptureAreaFeature::class));
    $batch->refresh();

    expect($batch->status)->toBe('done')
        ->and($batch->created_count)->toBe(2)
        ->and($batch->refused_count)->toBe(1)
        ->and(AreaFeature::query()->whereHas('featureClass', fn ($q) => $q->where('key', 'forest_woodland'))->count())->toBe(1);
});

it('names WorldCover tiles the way ESA does', function () {
    expect(LandCoverPipeline::tiles(['west' => 8.4, 'south' => 7.6, 'east' => 8.7, 'north' => 7.9]))->toBe(['N06E006'])
        ->and(LandCoverPipeline::tiles(['west' => 8.9, 'south' => 8.8, 'east' => 9.2, 'north' => 9.1]))->toBe(['N06E006', 'N06E009', 'N09E006', 'N09E009']);
});

it('sends the same sample every time, to the nearest officer in the field', function () {
    $ground = areaGround();
    $ground['campaign']->update(['verification_sample_pct' => 50]);

    foreach ([0.02, -0.02, 0.012, -0.012] as $dx) {
        app(CaptureAreaFeature::class)(deskInput($ground, 'farmland', square($ground['centre'], 0.001, dx: $dx)), digitiser(), CaptureAreaFeature::DESK);
    }

    $first = app(GenerateVerificationTasks::class)($ground['campaign']->refresh(), digitiser());
    $again = app(GenerateVerificationTasks::class)($ground['campaign'], digitiser());

    expect($first)->toBe(['sampled' => 2, 'assigned' => 2, 'unassigned' => 0])
        ->and($again['sampled'])->toBe(0)
        ->and(AreaVerificationTask::query()->pluck('assigned_to')->unique()->all())->toBe([$ground['officer']->id]);
});

it('lets the officer sent check a feature outside their own cells, and closes the task', function () {
    $ground = areaGround();
    $desk = app(CaptureAreaFeature::class)(deskInput($ground, 'farmland', square($ground['centre'], 0.002, dx: 0.02)), digitiser(), CaptureAreaFeature::DESK);
    AreaVerificationTask::query()->create(['area_feature_id' => $desk->area_feature_id, 'assigned_to' => $ground['officer']->id, 'status' => 'open']);

    $grass = areaClass($ground['campaign'], 'grassland_savanna');
    $check = [
        'client_uuid' => (string) Str::uuid7(),
        'feature_uuid' => $desk->feature->client_uuid,
        'feature_class_id' => $grass->id,
        'class_version' => $grass->latestVersion?->version,
        'capture_method' => 'field_verified',
        'geometry' => square($ground['centre'], 0.002, dx: 0.02),
        'answers' => ['cover' => 'Open grassland'],
    ];

    $result = $this->actingAs($ground['officer'])
        ->postJson('/api/field/sync', ['mutations' => [['client_uuid' => $check['client_uuid'], 'entity' => 'area_feature', 'payload' => $check]]])
        ->json('results.0');

    $task = AreaVerificationTask::query()->firstOrFail();

    expect($result['status'])->toBe('applied')
        ->and($task->status)->toBe('done')
        ->and($task->outcome)->toBe('reclassified')
        ->and($desk->feature->refresh()->verification_status)->toBe('verified')
        ->and($desk->feature->feature_class_id)->toBe($grass->id);

    // Without a task, the same shape outside the officer's cells is refused.
    $stranger = $check + [];
    $stranger['client_uuid'] = (string) Str::uuid7();
    $stranger['feature_uuid'] = (string) Str::uuid7();
    $stranger['capture_method'] = 'field_drawn';
    $stranger['assignment_id'] = DB::table('assignments')->where('user_id', $ground['officer']->id)->value('id');

    expect(fn () => app(CaptureAreaFeature::class)($stranger, $ground['officer']))->toThrow(ValidationException::class, 'outside your assigned cells');
});

it('records "not there" from the officer sent, through sync, and from nobody else', function () {
    $ground = areaGround();
    $desk = app(CaptureAreaFeature::class)(deskInput($ground, 'farmland', square($ground['centre'], 0.002, dx: 0.02)), digitiser(), CaptureAreaFeature::DESK);
    AreaVerificationTask::query()->create(['area_feature_id' => $desk->area_feature_id, 'assigned_to' => $ground['officer']->id, 'status' => 'open']);

    $outcome = ['client_uuid' => (string) Str::uuid7(), 'feature_uuid' => $desk->feature->client_uuid, 'outcome' => 'rejected'];
    $mutation = fn (array $payload) => ['mutations' => [['client_uuid' => $payload['client_uuid'], 'entity' => 'area_feature_outcome', 'payload' => $payload]]];

    $other = person(Role::Officer, 'Not sent');
    expect($this->actingAs($other)->postJson('/api/field/sync', $mutation($outcome))->json('results.0.status'))->toBe('failed');

    $outcome['client_uuid'] = (string) Str::uuid7();
    expect($this->actingAs($ground['officer'])->postJson('/api/field/sync', $mutation($outcome))->json('results.0.status'))->toBe('applied')
        ->and($desk->feature->refresh()->verification_status)->toBe('rejected')
        ->and(AreaVerificationTask::query()->value('outcome'))->toBe('rejected')
        ->and(AreaFeature::query()->count())->toBe(1);
});

it('withdraws a feature without deleting it, and cancels its check', function () {
    $ground = areaGround();
    $desk = app(CaptureAreaFeature::class)(deskInput($ground, 'farmland', square($ground['centre'], 0.002, dx: 0.02)), digitiser(), CaptureAreaFeature::DESK);
    AreaVerificationTask::query()->create(['area_feature_id' => $desk->area_feature_id, 'assigned_to' => $ground['officer']->id, 'status' => 'open']);

    $this->actingAs(digitiser())->post("/desk/features/{$desk->feature->client_uuid}/withdraw")->assertRedirect();

    expect($desk->feature->refresh()->status)->toBe('withdrawn')
        ->and(AreaFeatureRevision::query()->where('area_feature_id', $desk->area_feature_id)->exists())->toBeTrue()
        ->and(AreaVerificationTask::query()->value('status'))->toBe('cancelled');
});
