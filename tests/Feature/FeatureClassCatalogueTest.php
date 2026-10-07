<?php

declare(strict_types=1);

use App\Domain\Campaign\Actions\AssembleCampaignDossier;
use App\Domain\Campaign\Actions\ConfigureCapture;
use App\Domain\Campaign\Actions\ManageFeatureClasses;
use App\Domain\Campaign\Actions\ValidateFeatureAttributes;
use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Campaign\Models\FeatureClassVersion;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Database\Seeders\FeatureClassTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Area capture, stage 1: capture modes and the feature class catalogue.
 *
 * What is worth proving: an existing campaign is untouched, a form once used
 * can never change under the features captured against it, a campaign's
 * catalogue is its own, and answers are judged against the version they were
 * given.
 */
beforeEach(function () {
    $this->seed(FeatureClassTemplateSeeder::class);
});

function catalogueClass(Campaign $campaign, string $key): FeatureClass
{
    return FeatureClass::query()->where('campaign_id', $campaign->id)->where('key', $key)->with('latestVersion')->firstOrFail();
}

it('leaves every campaign capturing buildings only until somebody says otherwise', function () {
    $campaign = Campaign::factory()->active()->create();
    $campaign->refresh();

    expect($campaign->capture_modes)->toBe(['buildings'])
        ->and($campaign->captures(CaptureMode::Buildings))->toBeTrue()
        ->and($campaign->captures(CaptureMode::AreaFeatures))->toBeFalse()
        ->and($campaign->featureClasses()->count())->toBe(0);
});

it('refuses an unknown or empty capture mode at the database', function (string $modes) {
    $campaign = Campaign::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::update('update campaigns set capture_modes = ?::jsonb where id = ?', [$modes, $campaign->id])))
        ->toThrow(QueryException::class);
})->with(['[]', '["drones"]', '"buildings"']);

it('seeds thirteen templates once, and a second run writes no new version', function () {
    $this->seed(FeatureClassTemplateSeeder::class);

    expect(FeatureClass::query()->templates()->count())->toBe(13)
        ->and(FeatureClassVersion::query()->count())->toBe(13)
        ->and(FeatureClass::query()->templates()->where('exclusivity_group', 'land_cover')->pluck('key')->sort()->values()->all())
        ->toBe(['bare_land_rock', 'farmland', 'forest_woodland', 'grassland_savanna', 'settlement_cluster', 'water_body', 'wetland']);
});

it('gives a campaign its own catalogue when area capture is first switched on', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->active()->create();

    app(ConfigureCapture::class)($campaign, [
        'capture_modes' => ['area_features', 'buildings'],
        'min_mapping_unit_ha' => 0.25,
        'verification_sample_pct' => 15,
    ], $admin);

    $campaign->refresh();
    $farmland = catalogueClass($campaign, 'farmland');

    expect($campaign->capture_modes)->toBe(['buildings', 'area_features'])
        ->and((float) $campaign->min_mapping_unit_ha)->toBe(0.25)
        ->and($campaign->verification_sample_pct)->toBe(15)
        ->and($campaign->featureClasses()->count())->toBe(13)
        ->and($farmland->copied_from_id)->not->toBeNull()
        ->and($farmland->latestVersion?->version)->toBe(1)
        ->and(VerificationEvent::query()->where('subject_id', $campaign->id)->where('event', 'campaign.capture_configured')->exists())->toBeTrue();

    // Switching it off and on again copies nothing twice.
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['buildings']], $admin);
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['buildings', 'area_features']], $admin);

    expect($campaign->featureClasses()->count())->toBe(13);
});

it('versions the form on revision and never lets an old version change', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['area_features']], $admin);

    $farmland = catalogueClass($campaign, 'farmland');
    $v1 = $farmland->latestVersion;
    assert($v1 instanceof FeatureClassVersion);

    $attributes = $v1->attribute_schema;
    $attributes[] = ['key' => 'tenure', 'label' => 'Tenure', 'type' => 'select', 'options' => ['Owned', 'Rented', 'Communal'], 'required' => false, 'field_only' => true];

    app(ManageFeatureClasses::class)->revise($farmland, ['attributes' => $attributes, 'label' => 'Farm plot'], $admin);

    $farmland->refresh()->load('latestVersion');

    expect($farmland->label)->toBe('Farm plot')
        ->and($farmland->latestVersion?->version)->toBe(2)
        ->and(collect($v1->refresh()->attribute_schema)->pluck('key'))->not->toContain('tenure');

    // Saving the same form again is not a revision.
    app(ManageFeatureClasses::class)->revise($farmland, ['attributes' => $attributes], $admin);
    expect($farmland->versions()->count())->toBe(2);

    // And the database itself refuses to rewrite or remove a version.
    // Each attempt in its own savepoint, so the first refusal does not abort
    // the test's transaction before the second is tried.
    expect(fn () => DB::transaction(fn () => DB::update('update feature_class_versions set attribute_schema = ?::jsonb where id = ?', ['[]', $v1->id])))
        ->toThrow(QueryException::class, 'append only');
    expect(fn () => DB::transaction(fn () => DB::delete('delete from feature_class_versions where id = ?', [$v1->id])))
        ->toThrow(QueryException::class, 'append only');
});

it('keeps a class its shape: a river does not become an area', function () {
    $campaign = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['area_features']], null);

    expect(fn () => app(ManageFeatureClasses::class)->revise(catalogueClass($campaign, 'river_stream'), ['geometry_type' => 'polygon'], null))
        ->toThrow(ValidationException::class, 'keeps its shape');
});

it('never edits a template through a campaign, and a template change reaches only later copies', function () {
    $admin = person(Role::Admin);
    $early = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($early, ['capture_modes' => ['area_features']], $admin);

    $template = FeatureClass::query()->templates()->where('key', 'water_point')->with('latestVersion')->firstOrFail();
    app(ManageFeatureClasses::class)->revise($template, ['label' => 'Water source'], $admin);

    $late = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($late, ['capture_modes' => ['area_features']], $admin);

    expect(catalogueClass($early, 'water_point')->label)->toBe('Water point')
        ->and(catalogueClass($late, 'water_point')->label)->toBe('Water source');

    // The campaign controller only ever finds classes of its own campaign.
    $this->actingAs($admin)
        ->post("/admin/campaigns/{$early->id}/feature-classes", [
            'id' => $template->id, 'key' => 'water_point', 'label' => 'Hijacked',
            'geometry_type' => 'point', 'attributes' => [],
        ])
        ->assertNotFound();

    expect($template->refresh()->label)->toBe('Water source');
});

it('refuses a malformed attribute form', function (array $attributes, string $message) {
    $campaign = Campaign::factory()->active()->create();

    expect(fn () => app(ManageFeatureClasses::class)->create($campaign, [
        'key' => 'test_class', 'label' => 'Test', 'geometry_type' => 'point', 'attributes' => $attributes,
    ], null))->toThrow(ValidationException::class, $message);
})->with([
    'duplicate key' => [[
        ['key' => 'a', 'label' => 'A', 'type' => 'text'],
        ['key' => 'a', 'label' => 'Again', 'type' => 'text'],
    ], 'used twice'],
    'choice with no options' => [[['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['', ' ']]], 'at least one option'],
    'bad key' => [[['key' => 'Bad Key', 'label' => 'B', 'type' => 'text']], 'lower case'],
    'unknown type' => [[['key' => 'photo', 'label' => 'Photo', 'type' => 'photo']], 'unknown type'],
]);

it('judges answers against the version they were given, and lets a desk capture leave field questions', function () {
    $campaign = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['area_features']], null);

    $point = catalogueClass($campaign, 'water_point')->latestVersion;
    assert($point instanceof FeatureClassVersion);
    $validate = app(ValidateFeatureAttributes::class);

    // In the field, the required field-only questions must be answered.
    expect(fn () => $validate($point, [], inTheField: true))->toThrow(ValidationException::class, 'required');

    // From imagery, they cannot be, so they may wait for the officer.
    expect($validate($point, [], inTheField: false))->toBe([]);

    expect($validate($point, ['kind' => 'Spring', 'functional' => true, 'users_per_day' => '120'], inTheField: true))
        ->toBe(['kind' => 'Spring', 'functional' => true, 'users_per_day' => 120]);

    expect(fn () => $validate($point, ['kind' => 'Swimming pool', 'functional' => true], inTheField: true))
        ->toThrow(ValidationException::class, 'not a valid answer');

    // An answer to a question this version never asked is refused.
    expect(fn () => $validate($point, ['kind' => 'Spring', 'functional' => true, 'colour' => 'blue'], inTheField: true))
        ->toThrow(ValidationException::class, 'does not ask colour');
});

it('shows a client the classes in use and nothing about money', function () {
    $campaign = Campaign::factory()->active()->create();
    app(ConfigureCapture::class)($campaign, ['capture_modes' => ['buildings', 'area_features']], null);
    app(ManageFeatureClasses::class)->revise(catalogueClass($campaign, 'individual_tree'), ['is_active' => false], null);

    $client = app(AssembleCampaignDossier::class)($campaign);
    $inside = app(AssembleCampaignDossier::class)($campaign, includeInternal: true);

    /** @var list<array{key: string}> $clientClasses */
    $clientClasses = $client['capture']['classes'];
    /** @var list<array{key: string}> $insideClasses */
    $insideClasses = $inside['capture']['classes'];

    expect($client['capture']['areaFeatures'])->toBeTrue()
        ->and(array_column($clientClasses, 'key'))->not->toContain('individual_tree')
        ->and(array_column($insideClasses, 'key'))->toContain('individual_tree')
        ->and(count($clientClasses))->toBe(12);
});

it('keeps the catalogue screens to administrators', function () {
    $campaign = Campaign::factory()->active()->create();

    $this->actingAs(person(Role::Supervisor))->get('/admin/feature-templates')->assertForbidden();
    $this->actingAs(person(Role::Supervisor))
        ->put("/admin/campaigns/{$campaign->id}/capture", ['capture_modes' => ['area_features']])
        ->assertForbidden();

    $admin = person(Role::Admin);
    $this->actingAs($admin)->get('/admin/feature-templates')->assertOk();
    $this->actingAs($admin)
        ->put("/admin/campaigns/{$campaign->id}/capture", ['capture_modes' => ['buildings', 'area_features'], 'verification_sample_pct' => 20])
        ->assertRedirect();

    expect($campaign->refresh()->capture_modes)->toBe(['buildings', 'area_features']);
});
