<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Registry\Actions\CaptureEnterprise;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Domain\Verification\Exports\EnterpriseCsv;
use App\Domain\Verification\Exports\ExportScope;
use App\Domain\Verification\Exports\StructureGeoJson;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Evidence output
|--------------------------------------------------------------------------
|
| A register leaves this system as a file somebody else will hold. What is
| tested here is that the file says what it claims to say, that it never
| contains an identity number, and that the handing over is written down.
|
*/

/** Captures a structure with a business in it, and accepts it. */
function acceptedRecord(User $officer, User $supervisor, GridCell $cell, string $tradingName): Structure
{
    $session = sessionFor($officer, $cell);
    $observation = captureIn($cell, $officer, $session, now()->subHours(2));

    app(CaptureEnterprise::class)->capture([
        'client_uuid' => (string) Str::uuid7(),
        'observation_uuid' => (string) Str::uuid7(),
        'structure_id' => $observation->structure_id,
        'trading_name' => $tradingName,
        'sector_code' => '4711',
        'scale_band' => 'micro',
        'operating_status' => 'operating',
        'observed_at' => now()->subHours(2)->toIso8601String(),
        'field_session_id' => $session->id,
    ], $officer);

    app(ReviewObservation::class)($observation, ReviewDecision::Accept, $supervisor);

    return Structure::query()->findOrFail($observation->structure_id);
}

/**
 * The whole export, gathered for assertion.
 *
 * @param  iterable<int, string>  $chunks
 */
function gather(iterable $chunks): string
{
    $out = '';

    foreach ($chunks as $chunk) {
        $out .= $chunk;
    }

    return $out;
}

it('exports accepted structures as valid GeoJSON with geometry from PostGIS', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    $structure = acceptedRecord($officer, $supervisor, $cell, 'Mama Ngozi Provisions');

    $area = CoverageArea::query()->findOrFail($structure->coverage_area_id);
    $geojson = gather(app(StructureGeoJson::class)->stream(new ExportScope($area)));

    $decoded = json_decode($geojson, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['type'])->toBe('FeatureCollection')
        ->and($decoded['features'])->toHaveCount(1);

    $feature = $decoded['features'][0];

    expect($feature['geometry']['type'])->toBe('Point')
        // Degrees, from ST_AsGeoJSON, not assembled anywhere else.
        ->and($feature['geometry']['coordinates'][0])->toBeFloat()
        ->and($feature['properties']['status'])->toBe(Structure::STATUS_ACCEPTED)
        ->and($feature['properties']['enterprises'])->toBe(1)
        // Present whether or not admin boundaries are loaded in this fixture:
        // a consumer parsing the file gets the same columns either way.
        ->and($feature['properties'])->toHaveKeys(['ward', 'lga', 'state', 'h3', 'confidence_score']);
});

it('leaves unaccepted work out of a delivery, and puts it in an audit export', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    acceptedRecord($officer, $supervisor, $cell, 'Accepted Shop');

    // Captured but never decided, so it is not part of the register.
    $session = sessionFor($officer, $cell);
    captureIn($cell, $officer, $session, now()->subHour());

    $area = CoverageArea::query()->findOrFail($cell->coverage_area_id);

    $delivery = json_decode(
        gather(app(StructureGeoJson::class)->stream(new ExportScope($area))),
        true, 512, JSON_THROW_ON_ERROR,
    );

    $audit = json_decode(
        gather(app(StructureGeoJson::class)->stream(new ExportScope($area, null, true))),
        true, 512, JSON_THROW_ON_ERROR,
    );

    expect($delivery['features'])->toHaveCount(1)
        ->and($audit['features'])->toHaveCount(2);
});

it('never lets an identity number reach an export', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    $structure = acceptedRecord($officer, $supervisor, $cell, 'Sunrise Pharmacy');

    $enterprise = Enterprise::query()->where('structure_id', $structure->id)->firstOrFail();

    // A CAC number is public record. A NIN is not, and what is held for it is a
    // keyed hash that would still let a recipient link a person across files.
    IdentityClaim::query()->create([
        'claimable_type' => $enterprise->getMorphClass(),
        'claimable_id' => $enterprise->id,
        'kind' => 'cac',
        'reference_token' => 'RC1234567',
        'reference_last4' => '4567',
        'verified_at' => now(),
        'verifier' => 'cac',
        'client_uuid' => (string) Str::uuid7(),
    ]);

    IdentityClaim::query()->create([
        'claimable_type' => $enterprise->getMorphClass(),
        'claimable_id' => $enterprise->id,
        'kind' => 'nin',
        'reference_token' => 'hashed-nin-value-that-must-never-be-exported',
        'reference_last4' => '4821',
        'verified_at' => now(),
        'verifier' => 'nimc',
        'client_uuid' => (string) Str::uuid7(),
    ]);

    $area = CoverageArea::query()->findOrFail($structure->coverage_area_id);
    $csv = gather(app(EnterpriseCsv::class)->stream(new ExportScope($area)));

    expect($csv)->toContain('RC1234567')
        ->and($csv)->toContain('Sunrise Pharmacy')
        // The hash itself, the last four digits, and the word nin: none of them.
        ->and($csv)->not->toContain('hashed-nin-value-that-must-never-be-exported')
        ->and($csv)->not->toContain('4821')
        // What a client needs is that a check happened, and when.
        ->and($csv)->toContain('identity_verified');

    $row = explode("\n", trim($csv))[1] ?? '';
    expect($row)->toContain('yes');
});

it('quotes a trading name that would otherwise break the file open', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    acceptedRecord($officer, $supervisor, $cell, 'Chidi, "The Boss", Motors');

    $area = CoverageArea::query()->findOrFail($cell->coverage_area_id);
    $csv = gather(app(EnterpriseCsv::class)->stream(new ExportScope($area)));

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // One header and one record. A comma inside a name that split the row would
    // show up here as a third line or a shifted column.
    expect($lines)->toHaveCount(2);

    $parsed = str_getcsv($lines[1], ',', '"', '\\');
    expect($parsed[0])->toBe('Chidi, "The Boss", Motors');
});

it('writes every download to the log with the hash of what was sent', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    $structure = acceptedRecord($officer, $supervisor, $cell, 'Blessing Hair Studio');

    $response = $this->actingAs($supervisor)->get(
        route('console.exports.structures', ['area' => $structure->coverage_area_id]),
    );

    $response->assertOk();
    $body = $response->streamedContent();

    $event = VerificationEvent::query()
        ->where('event', 'export.taken')
        ->latest('id')
        ->firstOrFail();

    expect($event->actor_id)->toBe($supervisor->id)
        ->and($event->evidence['format'])->toBe('geojson')
        ->and($event->evidence['accepted_only'])->toBeTrue()
        // The hash of the exact bytes that went out, so a file produced in
        // evidence later can be checked against this rather than trusted.
        ->and($event->evidence['sha256'])->toBe(hash('sha256', $body))
        ->and($event->evidence['bytes'])->toBe(strlen($body));
});

it('names the scope in the filename so a file says what it holds', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);
    acceptedRecord($officer, $supervisor, $cell, 'Garki Cold Room');

    $response = $this->actingAs($supervisor)->get(route('console.exports.enterprises', [
        'area' => $cell->coverage_area_id,
        'cell' => $cell->id,
        'all' => 1,
    ]));

    $response->assertOk();

    $disposition = $response->headers->get('content-disposition') ?? '';

    expect($disposition)->toContain('cell-'.$cell->h3())
        ->and($disposition)->toContain('all-records')
        ->and($response->headers->get('content-type'))->toContain('text/csv');
});

it('refuses a cell that belongs to another mandate', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    // Real ground, because boundary is NOT NULL and a mandate without one is
    // not a mandate.
    $other = testMandate('POLYGON((7.60 9.20, 7.66 9.20, 7.66 9.25, 7.60 9.25, 7.60 9.20))');

    // A cell id from the first mandate against the second would widen the
    // export past what was asked for, which is how one client receives
    // another's register.
    $this->actingAs($supervisor)
        ->get(route('console.exports.structures', ['area' => $other->id, 'cell' => $cell->id]))
        ->assertNotFound();
});

it('keeps an officer out of the export screen and its files', function () {
    $supervisor = person(Role::Supervisor);
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, $supervisor);

    $this->actingAs($supervisor)->get(route('console.exports'))->assertOk();
    $this->actingAs($officer)->get(route('console.exports'))->assertRedirect('/field');
    $this->actingAs($officer)
        ->get(route('console.exports.structures', ['area' => $cell->coverage_area_id]))
        ->assertRedirect('/field');
});
