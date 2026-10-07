<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Imagery\Actions\RequestSatelliteBasemap;
use App\Domain\Imagery\ImageryPipeline;
use App\Domain\Imagery\Jobs\BuildSatelliteBasemap;
use App\Domain\Imagery\Models\BasemapLayer;
use App\Domain\Imagery\SentinelCatalogue;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Area capture, stage 2: ground from a boundary file, and satellite imagery of it.
 *
 * Neither GDAL nor the network is touched here. The catalogue is answered by a
 * faked STAC response and the pipeline by a stand-in that writes a real PMTiles
 * header, so what is proved is everything around them: which scenes are
 * chosen, what is recorded, who may download what, and that the vector pack
 * an officer already depends on is answered exactly as before.
 */
beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('local');
});

/** A PMTiles v3 header (and nothing else), enough for the header reader. */
function pmtilesStandIn(int $minZoom = 10, int $maxZoom = 14, int $tileType = 4): string
{
    $header = 'PMTiles'.chr(3).str_repeat("\0", 88);
    $header .= chr(1).chr(1).chr(1).chr($tileType).chr($minZoom).chr($maxZoom);
    $header .= pack('l', (int) (8.50 * 1e7)).pack('l', (int) (7.71 * 1e7));
    $header .= pack('l', (int) (8.56 * 1e7)).pack('l', (int) (7.76 * 1e7));
    $header .= chr(12).pack('l', 0).pack('l', 0);

    return $header.str_repeat('tile bytes ', 20);
}

/** Stands in for GDAL: writes an archive and reports the stages it would. */
function fakePipeline(?string $body = null, ?Throwable $fails = null): void
{
    app()->instance(ImageryPipeline::class, new class($body ?? pmtilesStandIn(), $fails) extends ImageryPipeline
    {
        /** @var list<array{href: string}> */
        public array $given = [];

        public function __construct(private readonly string $body, private readonly ?Throwable $fails) {}

        public function build(array $scenes, string $boundaryGeoJson, string $workDir, callable $stage): string
        {
            $this->given = $scenes;
            $stage('Reading the satellite images over the mandate', 25);

            if ($this->fails !== null) {
                throw $this->fails;
            }

            file_put_contents("{$workDir}/imagery.pmtiles", $this->body);

            return "{$workDir}/imagery.pmtiles";
        }
    });
}

/**
 * A STAC search answer: [id, tile, date, cloud] per scene.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: float}>  $scenes
 * @return array<string, mixed>
 */
function stacAnswer(array $scenes, ?string $next = null): array
{
    return [
        'type' => 'FeatureCollection',
        'features' => array_map(static fn (array $s): array => [
            'id' => $s[0],
            'properties' => ['grid:code' => "MGRS-{$s[1]}", 'datetime' => "{$s[2]}T10:00:00Z", 'eo:cloud_cover' => $s[3]],
            'assets' => ['visual' => ['href' => "https://example.test/{$s[0]}/TCI.tif"]],
        ], $scenes),
        'links' => $next === null ? [] : [['rel' => 'next', 'href' => $next, 'body' => ['next' => 'page2']]],
    ];
}

function mandateFor(string $wkt = 'POLYGON((8.50 7.71, 8.56 7.71, 8.56 7.76, 8.50 7.76, 8.50 7.71))'): CoverageArea
{
    return testMandate($wkt);
}

/** @param array<string, mixed> $geometry */
function boundaryUpload(array $geometry, string $name = 'guma.geojson'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) json_encode([
        'type' => 'FeatureCollection',
        'features' => [['type' => 'Feature', 'properties' => ['name' => 'Block A'], 'geometry' => $geometry]],
    ]));
}

it('creates a mandate from a boundary file, resolves its state, keeps the file and tiles it', function () {
    DB::insert(
        "INSERT INTO admin_boundaries (level, code, name, source, boundary, created_at, updated_at)
         VALUES ('state', 'NG007', 'Benue', 'test', ST_Multi(ST_GeomFromText('POLYGON((8 7, 9.5 7, 9.5 8, 8 8, 8 7))', 4326)), now(), now())",
    );
    $admin = person(Role::Admin);

    $this->actingAs($admin)
        ->post('/admin/mandates/from-boundary', [
            'boundary' => boundaryUpload(['type' => 'Polygon', 'coordinates' => [[[8.50, 7.71], [8.53, 7.71], [8.53, 7.74], [8.50, 7.74], [8.50, 7.71]]]]),
            'client' => 'Benue State Ministry of Lands',
            'name' => 'Guma forest block',
            'resolution' => 8,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $area = CoverageArea::query()->where('name', 'Guma forest block')->firstOrFail();

    expect($area->boundary_source)->toBe('uploaded')
        ->and($area->state_code)->toBe('NG007')
        ->and($area->lga_code)->toBeNull()
        ->and($area->default_h3_resolution)->toBe(8)
        ->and(Storage::disk('local')->exists((string) $area->boundary_file))->toBeTrue()
        ->and(GridCell::query()->where('coverage_area_id', $area->id)->where('h3_resolution', 8)->count())->toBeGreaterThan(0)
        ->and(VerificationEvent::query()->where('event', 'mandate.created')->where('subject_id', $area->id)->exists())->toBeTrue();

    // Every existing mandate still says it came from its LGA.
    expect(DB::table('coverage_areas')->where('id', '<>', $area->id)->where('boundary_source', '<>', 'admin_boundary')->count())->toBe(0);
});

it('refuses a boundary file that holds no area, is not in degrees, or is a whole state', function (array $geometry, string $message) {
    $this->actingAs(person(Role::Admin))
        ->post('/admin/mandates/from-boundary', [
            'boundary' => boundaryUpload($geometry),
            'client' => 'Client', 'name' => 'Bad', 'resolution' => 8,
        ])
        ->assertSessionHasErrors('boundary');

    expect(session('errors')->first('boundary'))->toContain($message)
        ->and(CoverageArea::query()->where('name', 'Bad')->exists())->toBeFalse();
})->with([
    'a point' => [['type' => 'Point', 'coordinates' => [8.5, 7.7]], 'holds no area'],
    'metres, not degrees' => [['type' => 'Polygon', 'coordinates' => [[[500000, 850000], [510000, 850000], [510000, 860000], [500000, 850000]]]], 'not in degrees'],
    'a whole state' => [['type' => 'Polygon', 'coordinates' => [[[7, 6], [10, 6], [10, 9], [7, 9], [7, 6]]]], 'more than one mandate should'],
]);

it('keeps the clearest scene of each tile, across pages of the catalogue', function () {
    Http::fake([
        'example-stac.test/search' => Http::sequence()
            ->push(stacAnswer([
                ['S2A_32NMP_20250110_0_L2A', '32NMP', '2025-01-10', 4.1],
                ['S2C_32NMP_20251119_0_L2A', '32NMP', '2025-11-19', 0.23],
                ['S2B_32NNP_20251201_0_L2A', '32NNP', '2025-12-01', 2.0],
            ], 'https://example-stac.test/search'))
            ->push(stacAnswer([
                ['S2B_32NNP_20260105_0_L2A', '32NNP', '2026-01-05', 0.9],
            ])),
    ]);
    config()->set('geoverify.imagery.stac_url', 'https://example-stac.test');

    $scenes = app(SentinelCatalogue::class)->clearestScenes(['type' => 'Point', 'coordinates' => [8.5, 7.7]]);

    expect(array_column($scenes, 'id'))->toBe(['S2C_32NMP_20251119_0_L2A', 'S2B_32NNP_20260105_0_L2A'])
        ->and($scenes[0]['href'])->toBe('https://example.test/S2C_32NMP_20251119_0_L2A/TCI.tif');

    Http::assertSentCount(2);
});

it('queues a build on the imagery connection, and refuses a second while one runs', function () {
    Bus::fake();
    config()->set('geoverify.imagery.queue_connection', 'redis-long');
    $area = mandateFor();
    $admin = person(Role::Admin);

    $this->actingAs($admin)->post("/admin/mandates/{$area->id}/imagery")->assertRedirect()->assertSessionHasNoErrors();

    Bus::assertDispatched(BuildSatelliteBasemap::class, fn (BuildSatelliteBasemap $job): bool => $job->connection === 'redis-long' && $job->queue === 'imagery');

    $this->actingAs($admin)->post("/admin/mandates/{$area->id}/imagery")->assertSessionHasErrors(['imagery' => "Imagery for {$area->name} is already being built."]);

    expect(BasemapLayer::query()->where('coverage_area_id', $area->id)->count())->toBe(1);
});

it('refuses imagery for ground too large for a phone to carry', function () {
    Bus::fake();
    config()->set('geoverify.imagery.max_area_km2', 10);

    expect(fn () => app(RequestSatelliteBasemap::class)(mandateFor(), null))
        ->toThrow(ValidationException::class, 'a phone can carry');

    Bus::assertNothingDispatched();
});

it('builds imagery, reads its zooms from the archive, and supersedes the old one without deleting it', function () {
    Http::fake(['*' => Http::response(stacAnswer([['S2C_32NMP_20251119_0_L2A', '32NMP', '2025-11-19', 0.23]]))]);
    fakePipeline(pmtilesStandIn(minZoom: 9, maxZoom: 14));
    Bus::fake();
    $area = mandateFor();

    $first = app(RequestSatelliteBasemap::class)($area, null);
    (new BuildSatelliteBasemap($first->id))->handle(app(SentinelCatalogue::class), app(ImageryPipeline::class));
    $first->refresh();

    expect($first->status)->toBe(BasemapLayer::STATUS_READY)
        ->and($first->min_zoom)->toBe(9)
        ->and($first->max_zoom)->toBe(14)
        ->and((float) $first->west)->toBe(8.5)
        ->and($first->captured_from?->toDateString())->toBe('2025-11-19')
        ->and($first->capturedLabel())->toBe('19 Nov 2025')
        ->and($first->checksum)->toBe(hash('sha256', pmtilesStandIn(9, 14)))
        ->and(Storage::disk('media')->exists((string) $first->path))->toBeTrue();

    $second = app(RequestSatelliteBasemap::class)($area, null);
    (new BuildSatelliteBasemap($second->id))->handle(app(SentinelCatalogue::class), app(ImageryPipeline::class));

    expect($first->refresh()->superseded_at)->not->toBeNull()
        ->and($second->refresh()->status)->toBe(BasemapLayer::STATUS_READY)
        ->and(BasemapLayer::query()->where('coverage_area_id', $area->id)->current()->pluck('id')->all())->toBe([$second->id])
        ->and(Storage::disk('media')->exists((string) $first->path))->toBeTrue();
});

it('records why a build failed, for somebody to read and ask again', function () {
    Http::fake(['*' => Http::response(stacAnswer([]))]);
    fakePipeline();
    Bus::fake();
    $layer = app(RequestSatelliteBasemap::class)(mandateFor(), null);
    $job = new BuildSatelliteBasemap($layer->id);

    try {
        $job->handle(app(SentinelCatalogue::class), app(ImageryPipeline::class));
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expect($layer->refresh()->status)->toBe(BasemapLayer::STATUS_FAILED)
        ->and($layer->error)->toContain('No clear Sentinel-2 image');

    // A failed build no longer blocks asking again.
    expect(app(RequestSatelliteBasemap::class)($layer->coverageArea, null)->status)->toBe(BasemapLayer::STATUS_QUEUED);
});

it('refuses an archive that holds no raster tiles', function () {
    Http::fake(['*' => Http::response(stacAnswer([['S2C_32NMP_20251119_0_L2A', '32NMP', '2025-11-19', 0.23]]))]);
    fakePipeline(pmtilesStandIn(tileType: 1));
    Bus::fake();
    $layer = app(RequestSatelliteBasemap::class)(mandateFor(), null);

    expect(fn () => (new BuildSatelliteBasemap($layer->id))->handle(app(SentinelCatalogue::class), app(ImageryPipeline::class)))
        ->toThrow(RuntimeException::class, 'no raster tiles');
});

it('offers an officer the imagery of their own mandate only, and leaves the pack listing as it was', function () {
    Http::fake(['*' => Http::response(stacAnswer([['S2C_32NMP_20251119_0_L2A', '32NMP', '2025-11-19', 0.23]]))]);
    fakePipeline();
    Bus::fake();

    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $area = CoverageArea::query()->findOrFail($cell->coverage_area_id);
    $layer = app(RequestSatelliteBasemap::class)($area, null);
    (new BuildSatelliteBasemap($layer->id))->handle(app(SentinelCatalogue::class), app(ImageryPipeline::class));

    $this->actingAs($officer)->getJson('/api/field/imagery')
        ->assertOk()
        ->assertJsonPath('imagery.0.id', $layer->id)
        ->assertJsonPath('imagery.0.captured', '19 Nov 2025')
        ->assertJsonPath('imagery.0.maxZoom', 14);

    $this->actingAs($officer)->get("/api/field/imagery/{$layer->id}")->assertOk();

    // The vector pack listing a shipped handset reads is untouched by imagery.
    $this->actingAs($officer)->getJson('/api/field/packs')->assertOk()->assertJsonCount(0, 'packs');

    $stranger = person(Role::Officer, 'Elsewhere');
    $this->actingAs($stranger)->getJson('/api/field/imagery')->assertOk()->assertJsonCount(0, 'imagery');
    $this->actingAs($stranger)->get("/api/field/imagery/{$layer->id}")->assertForbidden();
});
