<?php

declare(strict_types=1);

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Enums\PartyRole;
use App\Domain\Registry\Actions\NearbyFootprints;
use App\Domain\Registry\Actions\RegisterBusiness;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Models\BusinessRegistrationDraft;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Self-registration
|--------------------------------------------------------------------------
|
| The other way onto the register: a business no officer has reached, putting
| itself on. What matters here is that the result is honestly weaker than a
| field capture and cannot be mistaken for one, that its location is real and
| resolved by us, and that it does not quietly duplicate a record an officer
| already made.
|
*/

/**
 * A state, an LGA and a ward over the ground the field fixtures use.
 *
 * Boundaries are reference data loaded by geoverify:boundaries-load rather than
 * by a migration, so a refreshed test database has none. Self-registration
 * refuses a point it cannot place inside a state, which is the correct
 * behaviour and means these tests have to supply somewhere real to stand.
 */
function coveredGround(): void
{
    if (DB::scalar("SELECT count(*) FROM admin_boundaries WHERE code = 'TEST-WARD'") > 0) {
        return;
    }

    $box = 'MULTIPOLYGON(((7.40 9.00, 7.55 9.00, 7.55 9.12, 7.40 9.12, 7.40 9.00)))';

    $stateId = DB::scalar(
        "INSERT INTO admin_boundaries (level, code, name, source, boundary, created_at, updated_at)
         VALUES ('state', 'TEST-STATE', 'Federal Capital Territory', 'test',
                 ST_GeomFromText(?, 4326), now(), now()) RETURNING id",
        [$box],
    );

    $lgaId = DB::scalar(
        "INSERT INTO admin_boundaries (level, code, name, parent_id, source, boundary, created_at, updated_at)
         VALUES ('lga', 'TEST-LGA', 'Abuja Municipal', ?, 'test',
                 ST_GeomFromText(?, 4326), now(), now()) RETURNING id",
        [$stateId, $box],
    );

    DB::insert(
        "INSERT INTO admin_boundaries (level, code, name, parent_id, source, boundary, created_at, updated_at)
         VALUES ('ward', 'TEST-WARD', 'Garki 1', ?, 'test',
                 ST_GeomFromText(?, 4326), now(), now())",
        [$lgaId, $box],
    );
}

/**
 * A point inside that ground.
 *
 * @return array{0: float, 1: float}
 */
function coveredPoint(): array
{
    coveredGround();

    return [7.4650, 9.0550];
}

/**
 * Two building outlines beside the covered point.
 *
 * Footprints are ingested from an external source rather than migrated, so a
 * refreshed database has none, and a picker test with nothing to pick proves
 * only that an empty loop terminates.
 *
 * @return list<int>
 */
function buildingsBeside(float $lng, float $lat): array
{
    $ids = [];

    // Roughly 12m and 35m east of the point, in degrees at this latitude.
    foreach ([0.00011, 0.00032] as $offset) {
        $ids[] = (int) DB::scalar(
            "INSERT INTO external_footprints (source, source_id, area_m2, h3_index, footprint, created_at, updated_at)
             VALUES ('test', ?, 180, 1, ST_GeomFromText(?, 4326), now(), now()) RETURNING id",
            [
                'fp-'.$offset.'-'.$lng,
                sprintf(
                    'POLYGON((%1$.6f %2$.6f, %3$.6f %2$.6f, %3$.6f %4$.6f, %1$.6f %4$.6f, %1$.6f %2$.6f))',
                    $lng + $offset, $lat, $lng + $offset + 0.0001, $lat + 0.0001,
                ),
            ],
        );
    }

    return $ids;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationInput(array $overrides = []): array
{
    [$lng, $lat] = coveredPoint();

    return array_merge([
        'trading_name' => 'Adaora Cold Room',
        'sector_code' => null,
        'structure_type' => 'shophouse',
        'longitude' => $lng,
        'latitude' => $lat,
        'external_footprint_id' => null,
        'phone' => '0803 222 1111',
        'accuracy_m' => 8.5,
    ], $overrides);
}

it('puts a business on the register as unconfirmed, with no officer behind it', function () {
    $who = claimant('Adaora Eze', '08032221111');

    $enterprise = app(RegisterBusiness::class)->register($who['party'], registrationInput());
    $structure = $enterprise->structure;

    expect($enterprise->origin)->toBe(Enterprise::ORIGIN_SELF_REGISTERED)
        ->and($enterprise->captured_by)->toBeNull()
        ->and($enterprise->registered_by_party_id)->toBe($who['party']->id)
        ->and($structure->origin)->toBe(Structure::ORIGIN_SELF_REGISTERED)
        ->and($structure->status)->toBe(Structure::STATUS_UNCONFIRMED)
        ->and($structure->captured_by)->toBeNull();

    // No cell, and that is load bearing rather than tidiness: cell progress is
    // a count of structures in the cell, so a self-registration holding one
    // would inflate the coverage an officer is judged by.
    expect($structure->grid_cell_id)->toBeNull()
        ->and($structure->coverage_area_id)->toBeNull()
        // The h3 index still exists, at the field grid's resolution, so the two
        // kinds of record remain spatially comparable.
        ->and($structure->h3_index)->toBeGreaterThan(0);
});

it('establishes listed and nothing above it', function () {
    $who = claimant('Modest Trader', '08033332222');

    $enterprise = app(RegisterBusiness::class)->register($who['party'], registrationInput());

    $tier = app(ResolveListingTier::class)->forOrigin(
        (string) $enterprise->structure->origin,
        (string) $enterprise->structure->status,
    );

    // The rule the whole platform rests on. Typing your own address, however
    // honestly, is not somebody going to look.
    expect($tier)->toBe('listed');

    $rungs = app(ResolveListingTier::class)->rungs(
        (string) $enterprise->structure->origin,
        (string) $enterprise->structure->status,
        'August 2026',
    );

    $location = collect($rungs)->firstWhere('tier', 'location_verified');

    expect($location['state'])->toBe('not_established');
});

it('resolves the ward from the point rather than from anything the client says', function () {
    $who = claimant('Hierarchy Test', '08034443333');

    $enterprise = app(RegisterBusiness::class)->register($who['party'], registrationInput());
    $structure = $enterprise->structure;

    expect($structure->ward_id)->not->toBeNull()
        ->and($structure->lga_id)->not->toBeNull()
        ->and($structure->state_id)->not->toBeNull();

    // And it matches what PostGIS says contains the point, rather than being
    // whatever was convenient.
    $contains = DB::scalar(<<<'SQL'
        SELECT ST_Contains(b.boundary::geometry, s.centroid::geometry)
          FROM admin_boundaries b, structures s
         WHERE b.id = s.ward_id AND s.id = ?
    SQL, [$structure->id]);

    expect($contains)->toBeTrue();
});

it('refuses a location it could not place inside any state', function () {
    $who = claimant('Off The Map', '08035554444');

    // The Gulf of Guinea. Nothing contains it, so nothing could ever verify it.
    expect(fn () => app(RegisterBusiness::class)->register(
        $who['party'],
        registrationInput(['longitude' => 3.0, 'latitude' => 2.0]),
    ))->toThrow(RuntimeException::class);

    expect(Enterprise::query()->where('trading_name', 'Adaora Cold Room')->count())->toBe(0);
});

it('rejects a hierarchy supplied by the browser instead of quietly ignoring it', function () {
    $who = claimant('Forger', '08036665555');
    [$lng, $lat] = coveredPoint();

    $draft = BusinessRegistrationDraft::query()->create([
        'party_id' => $who['party']->id,
        'portal_account_id' => $who['account']->id,
        'step' => BusinessRegistrationDraft::STEP_PLACE,
        'payload' => ['trading_name' => 'Forged Ward Stores', 'structure_type' => 'kiosk'],
    ]);

    // Silently dropping it would let the next version of a client rely on it.
    $this->actingAs($who['account'], 'portal')
        ->post('/portal/register-business/place', [
            'latitude' => $lat,
            'longitude' => $lng,
            'ward_id' => 99999,
        ])
        ->assertSessionHasErrors('location');

    expect($draft->refresh()->hasPlace())->toBeFalse();
});

it('offers the businesses an officer already recorded before creating a second one', function () {
    $ground = sweptGround();
    $shop = enumeratedShop('Chidi Motors Spare Parts', '0803 777 1111', $ground);

    $point = DB::selectOne(
        'SELECT ST_X(centroid::geometry) lng, ST_Y(centroid::geometry) lat FROM structures WHERE id = ?',
        [$shop->structure_id],
    );

    $duplicates = app(RegisterBusiness::class)->possibleDuplicates(
        (float) $point->lng,
        (float) $point->lat,
        'Chidi Motors Spare Parts',
    );

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0]['enterprise_id'])->toBe($shop->id)
        ->and($duplicates[0]['distance_m'])->toBeLessThan(5.0);

    // Asked before anything is written, so the answer can be an offer rather
    // than a flag on a duplicate that now exists.
    expect(Enterprise::query()->where('trading_name', 'Chidi Motors Spare Parts')->count())->toBe(1);
});

it('hands the party control without needing a claim behind it', function () {
    $who = claimant('Immediate Control', '08037776666');

    $enterprise = app(RegisterBusiness::class)->register($who['party'], registrationInput());

    $control = PartyBusiness::query()
        ->where('enterprise_id', $enterprise->id)
        ->where('status', PartyBusiness::STATUS_ACTIVE)
        ->firstOrFail();

    expect($control->party_id)->toBe($who['party']->id)
        ->and($control->established_via)->toBe(PartyBusiness::VIA_SELF_REGISTRATION)
        ->and($control->claim_id)->toBeNull();

    $this->actingAs($who['account'], 'portal')
        ->get("/portal/businesses/{$enterprise->id}")
        ->assertOk();
});

it('will not let a self-registration be written as field work', function () {
    $who = claimant('Constraint Test', '08038887777');
    [$lng, $lat] = coveredPoint();

    // The database is what stops this, not the code that happens to call it.
    // Nullable columns with no constraint would let a record typed by its own
    // subject sit in the register wearing an officer's clothes.
    expect(fn () => DB::insert(<<<'SQL'
        INSERT INTO structures (
            grid_cell_id, coverage_area_id, ward_id, lga_id, state_id, h3_index,
            captured_by, registered_by_party_id, origin, captured_at,
            structure_type, occupancy_status, status, client_uuid,
            centroid, created_at, updated_at
        ) VALUES (
            NULL, NULL, NULL, NULL, NULL, 1,
            NULL, ?, 'field', now(),
            'kiosk', 'occupied', 'unconfirmed', ?,
            ST_SetSRID(ST_Point(?, ?), 4326)::geography, now(), now()
        )
    SQL, [$who['party']->id, (string) Str::uuid7(), $lng, $lat]))
        ->toThrow(QueryException::class);
});

it('names the party as the author of its own observation', function () {
    $who = claimant('Author Test', '08039998888');

    $enterprise = app(RegisterBusiness::class)->register($who['party'], registrationInput());

    $observation = DB::selectOne(
        'SELECT captured_by, recorded_by_party_id, signage_observed FROM enterprise_observations WHERE enterprise_id = ?',
        [$enterprise->id],
    );

    expect($observation->captured_by)->toBeNull()
        ->and((int) $observation->recorded_by_party_id)->toBe($who['party']->id)
        // False because nobody looked at any signage, not because there is none.
        ->and($observation->signage_observed)->toBeFalse();

    expect(VerificationEvent::query()
        ->where('subject_id', $enterprise->id)
        ->where('event', 'business.self_registered')
        ->exists())->toBeTrue();
});

it('keeps what was answered so a dropped connection costs a retry, not the form', function () {
    $who = claimant('Patchy Signal', '08031119999');
    [$lng, $lat] = coveredPoint();

    $as = $this->actingAs($who['account'], 'portal');

    $as->post('/portal/register-business/name', [
        'trading_name' => 'Half Finished Stores',
        'structure_type' => 'kiosk',
    ])->assertRedirect();

    // Reading a position does not advance the step: the question of which
    // building it is has not been answered yet.
    $as->post('/portal/register-business/place', [
        'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => 12,
    ])->assertRedirect();

    $as->get('/portal/register-business')
        ->assertInertia(fn ($page) => $page->where('step', 'place'));

    $as->post('/portal/register-business/place', [
        'latitude' => $lat, 'longitude' => $lng, 'confirm' => true,
    ])->assertRedirect();

    // Coming back later, the answers are still there and the ward is resolved
    // afresh from the saved point rather than carried in a hidden field.
    $as->get('/portal/register-business')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('portal/RegisterBusiness')
            ->where('step', 'confirm')
            ->where('answers.tradingName', 'Half Finished Stores')
            ->where('place.covered', true)
            ->whereNot('place.ward', null));
});

it('keeps one unfinished registration per party', function () {
    $who = claimant('One Draft', '08032228888');

    BusinessRegistrationDraft::query()->create([
        'party_id' => $who['party']->id,
        'portal_account_id' => $who['account']->id,
        'step' => 'name',
        'payload' => [],
    ]);

    expect(fn () => BusinessRegistrationDraft::query()->create([
        'party_id' => $who['party']->id,
        'portal_account_id' => $who['account']->id,
        'step' => 'name',
        'payload' => [],
    ]))->toThrow(QueryException::class);
});

it('refuses a viewer who tries to add a business for the party', function () {
    $who = claimant('Read Only Adder', '08033339999');
    $who['membership']->forceFill(['role' => PartyRole::Viewer])->save();

    $this->actingAs($who['account'], 'portal')
        ->post('/portal/register-business', ['phone' => '08031112222'])
        ->assertForbidden();
});

it('draws the buildings around a point without shipping a map', function () {
    [$lng, $lat] = coveredPoint();
    $expected = buildingsBeside($lng, $lat);

    $buildings = app(NearbyFootprints::class)->around($lng, $lat);

    expect($buildings)->toHaveCount(count($expected))
        ->and(array_column($buildings, 'id'))->toEqualCanonicalizing($expected);

    foreach ($buildings as $building) {
        expect($building['path'])->toStartWith('M ')
            ->and($building['metres'])->toBeLessThanOrEqual(60)
            ->and($building['area_m2'])->toBeGreaterThan(0);
    }

    // Nearest first, because the building somebody is standing in should be the
    // first thing they are offered.
    expect($buildings[0]['metres'])->toBeLessThanOrEqual($buildings[1]['metres']);

    // The whole picker, as bytes on the wire. A tile map is three orders of
    // magnitude more than this before it has drawn anything.
    expect(strlen((string) json_encode($buildings)))->toBeLessThan(4096);
});

it('puts the record inside the building somebody chose, not where they stood', function () {
    $who = claimant('Across The Road', '08034441111');
    [$lng, $lat] = coveredPoint();
    $chosen = buildingsBeside($lng, $lat)[1];

    $enterprise = app(RegisterBusiness::class)->register(
        $who['party'],
        registrationInput(['external_footprint_id' => $chosen]),
    );

    $row = DB::selectOne(
        'SELECT ST_Contains(footprint, centroid::geometry) AS inside, external_footprint_id
           FROM structures WHERE id = ?',
        [$enterprise->structure_id],
    );

    expect($row->inside)->toBeTrue()
        ->and((int) $row->external_footprint_id)->toBe($chosen);

    // And the footprint is spoken for, so it cannot back a second listing.
    expect((int) DB::scalar('SELECT matched_structure_id FROM external_footprints WHERE id = ?', [$chosen]))
        ->toBe($enterprise->structure_id);
});
