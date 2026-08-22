<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\LoadAdminBoundaries;
use App\Domain\Coverage\Models\AdminBoundary;
use Illuminate\Support\Facades\DB;

/**
 * @param  list<array{name: string, parent?: string, wkt: string}>  $features
 */
function geojsonFixture(array $features): string
{
    $collection = [
        'type' => 'FeatureCollection',
        'features' => array_map(static function (array $f): array {
            $geometry = DB::scalar('select ST_AsGeoJSON(ST_GeomFromText(?, 4326))', [$f['wkt']]);

            return [
                'type' => 'Feature',
                'properties' => [
                    'nm' => $f['name'],
                    'cd' => strtolower(str_replace(' ', '-', $f['name'])),
                    'parent' => $f['parent'] ?? null,
                ],
                'geometry' => json_decode((string) $geometry, true),
            ];
        }, $features),
    ];

    $path = tempnam(sys_get_temp_dir(), 'gv').'.geojson';
    file_put_contents($path, json_encode($collection, JSON_THROW_ON_ERROR));

    return $path;
}

/**
 * @return array{name: string, code: Closure(array<string, mixed>): string, alt: Closure(array<string, mixed>): array<int, string>, parent: string}
 */
function fixtureMapping(): array
{
    return [
        'name' => 'nm',
        'code' => static fn (array $p): string => (string) ($p['cd'] ?? ''),
        'alt' => static fn (array $p): array => [],
        'parent' => 'parent',
    ];
}

it('loads boundaries and is idempotent across repeated runs', function () {
    $path = geojsonFixture([
        ['name' => 'Alpha State', 'wkt' => 'POLYGON((7.0 9.0, 7.6 9.0, 7.6 9.6, 7.0 9.6, 7.0 9.0))'],
    ]);

    $loader = app(LoadAdminBoundaries::class);

    $first = $loader->load($path, AdminBoundary::LEVEL_STATE, 'test', fixtureMapping());
    $second = $loader->load($path, AdminBoundary::LEVEL_STATE, 'test', fixtureMapping());

    expect($first['loaded'])->toBe(1)
        ->and($second['loaded'])->toBe(1)
        ->and(AdminBoundary::query()->where('level', 'state')->count())->toBe(1);
});

it('links a child to its container by geometry, not by matching names', function () {
    $loader = app(LoadAdminBoundaries::class);

    $lgaPath = geojsonFixture([
        ['name' => 'Municipal Area Council', 'wkt' => 'POLYGON((7.0 9.0, 7.6 9.0, 7.6 9.6, 7.0 9.6, 7.0 9.0))'],
    ]);
    $wardPath = geojsonFixture([
        // The ward's own source calls its parent something else entirely, which is
        // exactly the OCHA against GRID3 case: "Abuja Municipal" against
        // "Municipal Area Council". Name matching fails here; containment does not.
        ['name' => 'Wuse', 'parent' => 'Abuja Municipal', 'wkt' => 'POLYGON((7.1 9.1, 7.2 9.1, 7.2 9.2, 7.1 9.2, 7.1 9.1))'],
    ]);

    $loader->load($lgaPath, AdminBoundary::LEVEL_LGA, 'test', fixtureMapping());
    $loader->load($wardPath, AdminBoundary::LEVEL_WARD, 'test', fixtureMapping());

    $linked = $loader->resolveHierarchy(AdminBoundary::LEVEL_WARD, AdminBoundary::LEVEL_LGA);

    $ward = AdminBoundary::query()->where('level', 'ward')->firstOrFail();

    expect($linked)->toBe(1)
        ->and($ward->parent?->name)->toBe('Municipal Area Council')
        ->and($ward->source_parent_name)->toBe('Abuja Municipal');
});

it('leaves a child unlinked when it falls outside every parent, rather than guessing', function () {
    $loader = app(LoadAdminBoundaries::class);

    $lgaPath = geojsonFixture([
        ['name' => 'Somewhere Else', 'wkt' => 'POLYGON((1.0 1.0, 1.5 1.0, 1.5 1.5, 1.0 1.5, 1.0 1.0))'],
    ]);
    $wardPath = geojsonFixture([
        ['name' => 'Orphan Ward', 'wkt' => 'POLYGON((7.1 9.1, 7.2 9.1, 7.2 9.2, 7.1 9.2, 7.1 9.1))'],
    ]);

    $loader->load($lgaPath, AdminBoundary::LEVEL_LGA, 'test', fixtureMapping());
    $loader->load($wardPath, AdminBoundary::LEVEL_WARD, 'test', fixtureMapping());
    $loader->resolveHierarchy(AdminBoundary::LEVEL_WARD, AdminBoundary::LEVEL_LGA);

    expect(AdminBoundary::query()->where('level', 'ward')->firstOrFail()->parent_id)->toBeNull();
});

it('keeps codes distinct for wards whose names differ only past 32 characters', function () {
    // The real collision: truncating these to 32 characters merges them, and a
    // capture in one ward then resolves into the other.
    $loader = app(LoadAdminBoundaries::class);

    $path = geojsonFixture([
        ['name' => 'Balogun Fulani I', 'wkt' => 'POLYGON((7.1 9.1, 7.15 9.1, 7.15 9.15, 7.1 9.15, 7.1 9.1))'],
        ['name' => 'Balogun Fulani II', 'wkt' => 'POLYGON((7.2 9.1, 7.25 9.1, 7.25 9.15, 7.2 9.15, 7.2 9.1))'],
        ['name' => 'Balogun Fulani III', 'wkt' => 'POLYGON((7.3 9.1, 7.35 9.1, 7.35 9.15, 7.3 9.15, 7.3 9.1))'],
    ]);

    $mapping = fixtureMapping();
    $mapping['code'] = static fn (array $p): string => 'kw-ilorin-south-'.(string) ($p['cd'] ?? '');

    $loader->load($path, AdminBoundary::LEVEL_WARD, 'test', $mapping);

    expect(AdminBoundary::query()->where('level', 'ward')->count())->toBe(3);
});

it('repairs invalid boundary geometry rather than rejecting the feature', function () {
    $loader = app(LoadAdminBoundaries::class);

    // A bowtie. Published administrative data contains these, and dropping the
    // feature would leave a hole in the country.
    $path = geojsonFixture([
        ['name' => 'Bowtie', 'wkt' => 'POLYGON((7.0 9.0, 7.2 9.2, 7.2 9.0, 7.0 9.2, 7.0 9.0))'],
    ]);

    $result = $loader->load($path, AdminBoundary::LEVEL_LGA, 'test', fixtureMapping());

    $valid = DB::scalar('select ST_IsValid(boundary) from admin_boundaries limit 1');

    expect($result['loaded'])->toBe(1)
        ->and($valid)->toBeTrue();
});
