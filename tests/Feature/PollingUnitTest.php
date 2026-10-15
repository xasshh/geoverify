<?php

declare(strict_types=1);

use App\Domain\Campaign\Actions\ConfigureCapture;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Election\Actions\ImportPollingUnits;
use App\Domain\Election\Actions\ManagePollingUnitChecks;
use App\Domain\Election\Models\PollingUnit;
use App\Domain\Election\Models\PollingUnitCheck;
use App\Domain\Field\Actions\ReadOfficerDay;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Polling unit checks (2026-10-15).
 *
 * What matters: a list is read whole and its boundary ids come from points,
 * never names; one officer per unit at a time; nothing an officer filed is
 * overwritten; a unit is confirmed at the position of the check a supervisor
 * accepted; and the client sees the findings without ever seeing an officer.
 */
beforeEach(function () {
    Storage::fake('media');
    Storage::fake('local');

    $state = (int) DB::scalar("insert into admin_boundaries (level, code, name, source, boundary, created_at, updated_at) values ('state', 'NG-PU', 'Plateau Test', 'test', ST_Multi(ST_GeomFromText('POLYGON((8.8 9.8, 9.0 9.8, 9.0 10.0, 8.8 10.0, 8.8 9.8))', 4326)), now(), now()) returning id");
    DB::statement("insert into admin_boundaries (level, code, name, source, parent_id, boundary, created_at, updated_at) values ('lga', 'NG-PU-01', 'Jos North Test', 'test', ?, ST_Multi(ST_GeomFromText('POLYGON((8.8 9.8, 9.0 9.8, 9.0 10.0, 8.8 10.0, 8.8 9.8))', 4326)), now(), now())", [$state]);
});

function puStaff(Role $role, string $name = 'Staff'): User
{
    return User::query()->create([
        'name' => $name, 'email' => Str::random(8).'@pu.test', 'password' => 'a-password-for-tests',
        'role' => $role, 'status' => User::STATUS_ACTIVE, 'staff_ref' => strtoupper(Str::random(5)),
    ]);
}

function puCampaign(): Campaign
{
    $campaign = Campaign::factory()->active()->create();

    return app(ConfigureCapture::class)($campaign, ['capture_modes' => ['polling_units']], null)->refresh();
}

function puCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'pu');
    file_put_contents($path, $body);

    return $path;
}

/** @return array{campaign: Campaign, supervisor: User, officer: User} */
function puLoaded(): array
{
    $campaign = puCampaign();
    $supervisor = puStaff(Role::Supervisor, 'Sade Supervisor');
    app(ImportPollingUnits::class)($campaign, $supervisor, puCsv(
        "PU Code,Polling Unit Name,State,LGA,Registration Area,Latitude,Longitude,Remarks\n"
        ."24-01-01-001,LEA Primary School Rikkos,Plateau,Jos North,Ali Kazaure,9.9000,8.9000,Near the market\n"
        ."24-01-01-002,Open Space Tudun Wada,Plateau,Jos North,Ali Kazaure,,,\n",
    ));

    return ['campaign' => $campaign, 'supervisor' => $supervisor, 'officer' => puStaff(Role::Officer, 'Musa Officer')];
}

/**
 * The officer's whole visit through the field endpoints.
 *
 * @param  array<string, mixed>  $overrides
 */
function puFile(object $test, PollingUnitCheck $check, User $officer, array $overrides = []): void
{
    $base = "/api/field/polling-units/{$check->id}";
    $test->actingAs($officer)->postJson("{$base}/arrive", ['longitude' => 8.9036, 'latitude' => 9.9010, 'accuracy_m' => 6])->assertOk();
    $test->postJson("{$base}/photos", ['client_uuid' => (string) Str::uuid(), 'angle' => 'site', 'photo' => UploadedFile::fake()->image('site.jpg')])->assertOk();

    $answers = [];
    foreach (PollingUnitCheck::CHECKLIST as $key => [$label, $options]) {
        $answers[$key] = ['answer' => array_key_first($options), 'detail' => null];
    }
    $answers['step_free'] = ['answer' => 'no', 'detail' => 'Two steps at the gate'];

    $test->postJson("{$base}/report", array_merge([
        'report_uuid' => (string) Str::uuid(),
        'found' => 'elsewhere',
        'answers' => $answers,
        'notes' => 'The school moved its gate.',
    ], $overrides))->assertOk();
}

it('reads a list whole, matches its headings loosely and reports the lines it cannot use', function () {
    $campaign = puCampaign();
    $result = app(ImportPollingUnits::class)($campaign, puStaff(Role::Supervisor), puCsv(
        "\xEF\xBB\xBFDelimitation,PU Name,LGA,Ward,Lat,Lng,Registered Voters\n"
        ."24-01-01-001,LEA Primary School,Jos North,Ali Kazaure,9.9,8.9,\"1,204\"\n"
        ."24-01-01-001,Duplicate,Jos North,Ali Kazaure,,,\n"
        .",No code,Jos North,,,,\n"
        ."24-01-01-003,Swapped columns,Jos North,,8.9,30.2,\n",
    ));

    expect($result['created'])->toBe(2)
        ->and($result['errors'])->toHaveCount(3)
        ->and(PollingUnit::query()->where('inec_code', '24-01-01-001')->value('registered_voters'))->toBe(1204);

    // Boundaries from the point, in PostGIS; the swapped unit has no point and no boundary.
    $listed = PollingUnit::query()->where('inec_code', '24-01-01-001')->firstOrFail();
    expect($listed->lga_id)->toBe((int) DB::table('admin_boundaries')->where('code', 'NG-PU-01')->value('id'))
        ->and(PollingUnit::query()->where('inec_code', '24-01-01-003')->value('lga_id'))->toBeNull();
});

it('refuses a list for a campaign that does not check polling units', function () {
    $campaign = Campaign::factory()->active()->create();

    expect(fn () => app(ImportPollingUnits::class)($campaign, puStaff(Role::Supervisor), puCsv("code,name\n1,A\n")))
        ->toThrow(RuntimeException::class, 'does not check polling units');
});

it('sends one officer per unit, and puts the checks on their Today screen', function () {
    ['campaign' => $campaign, 'supervisor' => $supervisor, 'officer' => $officer] = puLoaded();
    $ids = PollingUnit::query()->pluck('id')->map(fn ($id): int => (int) $id)->all();

    $first = app(ManagePollingUnitChecks::class)->assign($campaign->id, $ids, $officer, $supervisor);
    $again = app(ManagePollingUnitChecks::class)->assign($campaign->id, $ids, puStaff(Role::Officer), $supervisor);

    expect($first)->toBe(['assigned' => 2, 'skipped' => 0])
        ->and($again)->toBe(['assigned' => 0, 'skipped' => 2])
        ->and(PollingUnit::query()->where('status', PollingUnit::ASSIGNED)->count())->toBe(2);

    /** @var list<array{kind: string, orderRef: string}> $jobs */
    $jobs = app(ReadOfficerDay::class)($officer)['jobs'];
    expect(array_values(array_map(fn (array $j): string => $j['orderRef'], array_filter($jobs, fn (array $j): bool => $j['kind'] === 'polling_unit'))))->toBe(['24-01-01-001', '24-01-01-002']);
});

it('takes an officer through the check, and keeps the check to them', function () {
    ['campaign' => $campaign, 'supervisor' => $supervisor, 'officer' => $officer] = puLoaded();
    $unit = PollingUnit::query()->where('inec_code', '24-01-01-001')->firstOrFail();
    app(ManagePollingUnitChecks::class)->assign($campaign->id, [$unit->id], $officer, $supervisor);
    $check = PollingUnitCheck::query()->firstOrFail();

    $this->actingAs(puStaff(Role::Officer))->get("/field/polling-units/{$check->id}")->assertNotFound();
    $this->actingAs($officer)->get("/field/polling-units/{$check->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('field/PollingUnit')->where('check.code', '24-01-01-001')->has('check.checklist', 10));

    // No report before a photograph of the unit.
    $this->postJson("/api/field/polling-units/{$check->id}/arrive", ['longitude' => 8.9036, 'latitude' => 9.9010])->assertOk();
    $this->postJson("/api/field/polling-units/{$check->id}/report", ['report_uuid' => (string) Str::uuid(), 'found' => 'not_found', 'answers' => [], 'notes' => 'Asked the chief.'])
        ->assertStatus(422)->assertJsonPath('message', 'Take a photograph of where the unit should be.');

    puFile($this, $check, $officer);

    expect($check->refresh()->status)->toBe(PollingUnitCheck::SUBMITTED)
        ->and($check->arrival_distance_m)->toBeGreaterThan(350.0)
        ->and($unit->refresh()->status)->toBe(PollingUnit::FILED);
});

it('opens the next check on a return, and confirms the unit where the officer stood on acceptance', function () {
    ['campaign' => $campaign, 'supervisor' => $supervisor, 'officer' => $officer] = puLoaded();
    $unit = PollingUnit::query()->where('inec_code', '24-01-01-001')->firstOrFail();
    app(ManagePollingUnitChecks::class)->assign($campaign->id, [$unit->id], $officer, $supervisor);
    $first = PollingUnitCheck::query()->firstOrFail();
    puFile($this, $first, $officer);

    $this->actingAs($supervisor)->post("/console/polling-units/checks/{$first->id}/review", ['decision' => 'return', 'note' => 'short'])
        ->assertSessionHasErrors('review');
    $this->post("/console/polling-units/checks/{$first->id}/review", ['decision' => 'return', 'note' => 'The photo shows the wrong building, please retake it.'])
        ->assertSessionHasNoErrors();

    $second = PollingUnitCheck::query()->where('status', PollingUnitCheck::ASSIGNED)->firstOrFail();
    expect($first->refresh()->status)->toBe(PollingUnitCheck::RETURNED)
        ->and($first->answers)->not->toBeNull()
        ->and($second->agent_id)->toBe($officer->id);

    puFile($this, $second, $officer);
    $this->actingAs($supervisor)->post("/console/polling-units/checks/{$second->id}/review", ['decision' => 'accept'])->assertSessionHasNoErrors();

    $unit->refresh();
    $point = DB::selectOne('SELECT ST_X(confirmed_point::geometry) AS x, ST_Y(confirmed_point::geometry) AS y FROM polling_units WHERE id = ?', [$unit->id]);

    expect($unit->status)->toBe(PollingUnit::CONFIRMED)
        ->and($unit->settled_by_check_id)->toBe($second->id)
        ->and(round((float) $point->x, 4))->toBe(8.9036)
        ->and($unit->listed_distance_m)->toBeGreaterThan(350.0)
        ->and($unit->lga_id)->not->toBeNull();
});

it('records not found only with a note of where the officer looked', function () {
    ['campaign' => $campaign, 'supervisor' => $supervisor, 'officer' => $officer] = puLoaded();
    $unit = PollingUnit::query()->where('inec_code', '24-01-01-002')->firstOrFail();
    app(ManagePollingUnitChecks::class)->assign($campaign->id, [$unit->id], $officer, $supervisor);
    $check = PollingUnitCheck::query()->firstOrFail();

    $base = "/api/field/polling-units/{$check->id}";
    $this->actingAs($officer)->postJson("{$base}/arrive", ['longitude' => 8.95, 'latitude' => 9.95])->assertOk();
    $this->postJson("{$base}/photos", ['client_uuid' => (string) Str::uuid(), 'angle' => 'site', 'photo' => UploadedFile::fake()->image('s.jpg')])->assertOk();
    $this->postJson("{$base}/report", ['report_uuid' => (string) Str::uuid(), 'found' => 'not_found', 'answers' => [], 'notes' => 'no'])->assertStatus(422);
    $this->postJson("{$base}/report", ['report_uuid' => (string) Str::uuid(), 'found' => 'not_found', 'answers' => [], 'notes' => 'Asked the ward head and two traders; nobody knows it.'])->assertOk();

    app(ManagePollingUnitChecks::class)->review($check->refresh(), $supervisor, true, null);

    expect($unit->refresh()->status)->toBe(PollingUnit::NOT_FOUND)
        ->and(DB::table('polling_units')->where('id', $unit->id)->value('confirmed_point'))->toBeNull();
});

it('shows the client the findings and the exports, and never an officer', function () {
    ['campaign' => $campaign, 'supervisor' => $supervisor, 'officer' => $officer] = puLoaded();
    $unit = PollingUnit::query()->where('inec_code', '24-01-01-001')->firstOrFail();
    app(ManagePollingUnitChecks::class)->assign($campaign->id, [$unit->id], $officer, $supervisor);
    $check = PollingUnitCheck::query()->firstOrFail();
    puFile($this, $check, $officer);
    $this->actingAs($officer)->postJson("/api/field/polling-units/{$check->id}/photos", ['client_uuid' => (string) Str::uuid(), 'angle' => 'other', 'photo' => UploadedFile::fake()->image('o.jpg')]);
    app(ManagePollingUnitChecks::class)->review($check->refresh(), $supervisor, true, null);

    // The officer's staff session from the steps above is not the client's.
    auth()->guard('web')->logout();
    $client = ClientUser::factory()->create(['client_organisation_id' => $campaign->client_organisation_id]);
    $base = "/client/campaigns/{$campaign->id}/polling-units";

    $page = $this->actingAs($client, 'client')->get($base)->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('client/CampaignPollingUnits')
            ->where('summary.total', 2)->where('summary.confirmed', 1)->where('summary.moved', 1)
            ->where('summary.concerns.0.key', 'step_free')->where('summary.concerns.0.count', 1));

    $points = $this->getJson("{$base}/points.json")->assertOk();
    $detail = $this->getJson("{$base}/{$unit->id}")->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonCount(1, 'photos');
    $csv = $this->get("{$base}/export?format=csv")->assertOk()->streamedContent();

    foreach ([$page->getContent(), $points->getContent(), $detail->getContent(), $csv] as $body) {
        expect($body)->not->toContain('Musa Officer');
        expect($body)->not->toContain((string) $officer->staff_ref);
        expect($body)->not->toContain('Sade Supervisor');
    }

    expect($csv)->toContain('24-01-01-001')->toContain('Not fully accessible to wheelchair users');

    // Another organisation's client reaches none of it.
    $stranger = ClientUser::factory()->create();
    $this->actingAs($stranger, 'client')->get($base)->assertForbidden();
});

it('keeps the console to supervisors', function () {
    puLoaded();

    $this->actingAs(puStaff(Role::Officer))->get('/console/polling-units')->assertRedirect();
    $this->actingAs(puStaff(Role::Supervisor))->get('/console/polling-units')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('console/PollingUnits')->where('units.total', 2));
});
