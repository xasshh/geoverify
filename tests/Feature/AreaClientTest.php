<?php

declare(strict_types=1);

use App\Domain\AreaCapture\Actions\CaptureAreaFeature;
use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Database\Seeders\FeatureClassTemplateSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/**
 * Area capture, stage 5: what the client sees of the land, and takes away.
 *
 * What matters: the summary and the exports count the same live features; a
 * withdrawn feature is in neither; every format carries a dictionary; every
 * download is written down with the hash of what left; nothing names the
 * officer who captured a feature; and one client never reaches another's land.
 */
beforeEach(function () {
    Storage::fake('media');
    Queue::fake();
    $this->seed(FeatureClassTemplateSeeder::class);
});

/**
 * Ground with three features: one walked in the field, one drawn at the desk,
 * and one drawn at the desk then withdrawn.
 *
 * @return array{ground: array<string, mixed>, client: ClientUser, area: int}
 */
function mappedLand(): array
{
    $ground = areaGround();

    $field = areaInput($ground, 'farmland', square($ground['centre'], 0.0006));
    app(CaptureAreaFeature::class)($field, $ground['officer'], CaptureAreaFeature::FIELD);

    $admin = person(Role::Admin);
    $capture = app(CaptureAreaFeature::class);
    $capture(deskInput($ground, 'farmland', square($ground['centre'], 0.003, dx: 0.02), ['capture_method' => AreaFeatureRevision::METHOD_DESK, 'answers' => ['status' => 'Fallow']]), $admin, CaptureAreaFeature::DESK);
    $gone = $capture(deskInput($ground, 'farmland', square($ground['centre'], 0.003, dx: -0.02), ['capture_method' => AreaFeatureRevision::METHOD_DESK, 'answers' => ['status' => 'Fallow']]), $admin, CaptureAreaFeature::DESK);
    AreaFeature::query()->whereKey($gone->area_feature_id)->update(['status' => AreaFeature::STATUS_WITHDRAWN]);

    $client = ClientUser::factory()->create(['client_organisation_id' => $ground['campaign']->client_organisation_id]);

    return ['ground' => $ground, 'client' => $client, 'area' => (int) $ground['cell']->coverage_area_id];
}

/** @return array<string, string> name => contents */
function unzipped(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[$name] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($path);

    return $files;
}

it('shows the client a summary that counts live features only', function () {
    ['ground' => $ground, 'client' => $client] = mappedLand();
    $campaign = $ground['campaign'];

    $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$campaign->id}/land")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('client/CampaignLand')
            ->where('summary.totals.features', 2)
            ->where('summary.verification.fromDesk', 1)
            ->where('summary.classes', fn ($classes): bool => array_column(iterator_to_array($classes), 'fromField', 'key')['farmland'] === 1));
});

it('keeps one client away from another client\'s land, and land from a campaign that maps none', function () {
    ['ground' => $ground, 'area' => $area] = mappedLand();
    $campaign = $ground['campaign'];
    $stranger = ClientUser::factory()->create(['client_organisation_id' => ClientOrganisation::factory()->create()->id]);

    $this->actingAs($stranger, 'client')->get("/client/campaigns/{$campaign->id}/land")->assertForbidden();
    $this->actingAs($stranger, 'client')->get("/client/campaigns/{$campaign->id}/land/{$area}/features.json")->assertForbidden();
    $this->actingAs($stranger, 'client')->get("/client/campaigns/{$campaign->id}/land/export?format=geojson")->assertForbidden();

    $buildingsOnly = Campaign::factory()->forClient(ClientOrganisation::query()->findOrFail($campaign->client_organisation_id))->active()->create();
    $owner = ClientUser::factory()->create(['client_organisation_id' => $campaign->client_organisation_id]);
    $this->actingAs($owner, 'client')->get("/client/campaigns/{$buildingsOnly->id}/land")->assertNotFound();

    // Staff are not clients.
    $this->actingAs(person(Role::Admin))->get("/client/campaigns/{$campaign->id}/land")->assertRedirect();
});

it('draws the map from live features, and never names who captured them', function () {
    ['ground' => $ground, 'client' => $client, 'area' => $area] = mappedLand();

    $features = $this->actingAs($client, 'client')
        ->getJson("/client/campaigns/{$ground['campaign']->id}/land/{$area}/features.json")
        ->assertOk()
        ->json('features');

    expect($features)->toHaveCount(2)
        ->and(array_values(Arr::sort(array_column(array_column((array) $features, 'properties'), 'method'))))->toBe(['desk', 'field'])
        ->and(json_encode($features))->not->toContain($ground['officer']->name);
});

it('exports every format with a dictionary and a readme, and writes the hash of what left', function (string $format, string $data) {
    ['ground' => $ground, 'client' => $client] = mappedLand();
    $campaign = $ground['campaign'];

    $response = $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$campaign->id}/land/export?format={$format}")
        ->assertOk()
        ->assertDownload();

    $bytes = (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    $files = unzipped($bytes);

    expect(array_keys($files))->toContain('data-dictionary.csv', 'README.txt', $data)
        ->and($files['data-dictionary.csv'])->toContain('farmland', 'area_ha', 'verified')
        ->and($files['README.txt'])->toContain($campaign->code, 'Features:    2')
        ->and(implode('', $files))->not->toContain($ground['officer']->name);

    $event = VerificationEvent::query()->where('event', 'area_features.exported')->sole();
    expect($event->actor_type)->toBe(VerificationEvent::ACTOR_CLIENT)
        ->and($event->actor_id)->toBe($client->id)
        ->and($event->evidence['rows'])->toBe(2)
        ->and($event->evidence['sha256'])->toBe(hash('sha256', $bytes));
})->with([
    'GeoJSON' => ['geojson', 'features.geojson'],
    'GeoPackage' => ['gpkg', 'features.gpkg'],
    'Shapefile' => ['shp', 'shapefile/farmland.shp'],
    'KML' => ['kml', 'features.kml'],
    'CSV' => ['csv', 'features.csv'],
]);

it('exports what the filters show, and keeps answers by their own columns', function () {
    ['ground' => $ground, 'client' => $client] = mappedLand();
    $campaign = $ground['campaign'];

    $response = $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$campaign->id}/land/export?format=csv&method=field")
        ->assertOk();

    $csv = unzipped((string) file_get_contents($response->baseResponse->getFile()->getPathname()))['features.csv'];
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    $header = $rows[0];
    $row = array_combine($header, $rows[1]);

    expect($rows)->toHaveCount(2)
        ->and($row['method'])->toBe('field_drawn')
        ->and($row['status'])->toBe('Cultivated')
        ->and($row['wkt'])->toStartWith('POLYGON');
});

it('refuses a format it does not make', function () {
    ['ground' => $ground, 'client' => $client] = mappedLand();

    $this->actingAs($client, 'client')
        ->getJson("/client/campaigns/{$ground['campaign']->id}/land/export?format=xlsx")
        ->assertUnprocessable();
});

it('renders the area report only behind a fresh signature on the loopback interface', function () {
    ['ground' => $ground] = mappedLand();
    $campaign = $ground['campaign'];

    $this->get("/client/campaigns/{$campaign->id}/land/report.html")->assertForbidden();

    $signed = URL::temporarySignedRoute('client.campaigns.land.report.render', now()->addMinutes(2), ['campaign' => $campaign->id], absolute: false);

    $this->get($signed)
        ->assertOk()
        ->assertSee($campaign->name)
        ->assertSee('Farmland')
        ->assertSee('<svg', false)
        ->assertDontSee($ground['officer']->name);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($signed)->assertForbidden();
});
