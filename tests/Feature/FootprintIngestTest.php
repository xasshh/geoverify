<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Actions\IngestFootprints;
use App\Domain\Coverage\Models\ExternalFootprint;
use App\Domain\Coverage\Models\GridCell;
use Illuminate\Support\Facades\DB;

/**
 * Writes newline-delimited GeoJSON, the format both Google Open Buildings and
 * Microsoft Global ML Building Footprints publish.
 *
 * @param  list<array{wkt: string, confidence?: float}>  $buildings
 */
function footprintFixture(array $buildings): string
{
    $path = tempnam(sys_get_temp_dir(), 'fp').'.geojsonl';
    $handle = fopen($path, 'wb');

    foreach ($buildings as $building) {
        $geometry = DB::scalar('select ST_AsGeoJSON(ST_GeomFromText(?, 4326))', [$building['wkt']]);

        fwrite($handle, json_encode([
            'type' => 'Feature',
            'properties' => ['confidence' => $building['confidence'] ?? -1.0, 'height' => -1.0],
            'geometry' => json_decode((string) $geometry, true),
        ], JSON_THROW_ON_ERROR)."\n");
    }

    fclose($handle);

    return $path;
}

/** A tiny building near the given corner, about 10 metres across. */
function buildingAt(float $lon, float $lat): string
{
    $d = 0.0001;

    return sprintf(
        'POLYGON((%1$.6f %2$.6f, %3$.6f %2$.6f, %3$.6f %4$.6f, %1$.6f %4$.6f, %1$.6f %2$.6f))',
        $lon, $lat, $lon + $d, $lat + $d,
    );
}

it('ingests only footprints inside the mandate', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $path = footprintFixture([
        ['wkt' => buildingAt(7.46, 9.05)],   // inside
        ['wkt' => buildingAt(7.47, 9.06)],   // inside
        ['wkt' => buildingAt(7.10, 9.05)],   // well outside
        ['wkt' => buildingAt(7.90, 9.05)],   // well outside
    ]);

    $result = app(IngestFootprints::class)->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    expect($result['read'])->toBe(4)
        ->and($result['ingested'])->toBe(2)
        ->and(ExternalFootprint::query()->count())->toBe(2);
});

it('never leaves an ingested footprint belonging to no cell', function () {
    // The two numbers a supervisor sees, the footprint table and the sum of the
    // per-cell denominators, have to agree. They only agree if membership and cell
    // assignment use the same predicate.
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $path = footprintFixture([
        ['wkt' => buildingAt(7.46, 9.05)],
        ['wkt' => buildingAt(7.4401, 9.0301)],  // hard against the mandate edge
        ['wkt' => buildingAt(7.4999, 9.0799)],  // the opposite edge
    ]);

    app(IngestFootprints::class)->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    $orphans = ExternalFootprint::query()->whereNull('grid_cell_id')->count();
    $tableTotal = ExternalFootprint::query()->count();
    $denominatorTotal = (int) GridCell::query()->where('coverage_area_id', $area->id)->sum('footprint_count');

    expect($orphans)->toBe(0)
        ->and($denominatorTotal)->toBe($tableTotal);
});

it('is idempotent, so a resumed ingest does not double the denominator', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $path = footprintFixture([
        ['wkt' => buildingAt(7.46, 9.05)],
        ['wkt' => buildingAt(7.47, 9.06)],
    ]);

    $ingester = app(IngestFootprints::class);
    $ingester->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);
    $second = $ingester->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    expect($second['ingested'])->toBe(0)
        ->and(ExternalFootprint::query()->count())->toBe(2)
        ->and((int) GridCell::query()->where('coverage_area_id', $area->id)->sum('footprint_count'))->toBe(2);
});

it('stores an unscored confidence as null rather than inventing a number', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    // Microsoft publishes -1 for Nigeria, meaning unscored. Google publishes a real
    // 0 to 1 value. Both have to survive this loader honestly.
    $path = footprintFixture([
        ['wkt' => buildingAt(7.46, 9.05), 'confidence' => -1.0],
        ['wkt' => buildingAt(7.47, 9.06), 'confidence' => 0.82],
    ]);

    app(IngestFootprints::class)->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    expect(ExternalFootprint::query()->whereNull('confidence')->count())->toBe(1)
        ->and(ExternalFootprint::query()->where('confidence', 0.82)->count())->toBe(1);
});

it('repairs a self-intersecting detection to its largest part', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    // A bowtie inside the mandate. Repairing it yields two triangles; it is one
    // bad detection, not two buildings, so the denominator must gain exactly one.
    $path = footprintFixture([
        ['wkt' => 'POLYGON((7.460 9.050, 7.462 9.052, 7.462 9.050, 7.460 9.052, 7.460 9.050))'],
    ]);

    $result = app(IngestFootprints::class)->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    expect($result['repaired'])->toBe(1)
        ->and($result['ingested'])->toBe(1)
        ->and(DB::scalar('select ST_IsValid(footprint) from external_footprints limit 1'))->toBeTrue();
});

it('drives the per-cell denominator, and dismissing a footprint lowers it', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $path = footprintFixture([
        ['wkt' => buildingAt(7.4600, 9.0500)],
        ['wkt' => buildingAt(7.4601, 9.0501)],
        ['wkt' => buildingAt(7.4602, 9.0502)],
    ]);

    $ingester = app(IngestFootprints::class);
    $ingester->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    // Asserted across the mandate rather than on one cell: buildings metres apart
    // can still fall either side of an H3 cell edge, and which cell they land in is
    // not what this is testing.
    $denominator = fn (): int => (int) GridCell::query()
        ->where('coverage_area_id', $area->id)->sum('footprint_count');

    expect($denominator())->toBe(3);

    // An officer's judgement that a detection is not a building. Kept, never
    // deleted, but it stops counting as work.
    $dismissed = ExternalFootprint::query()->orderBy('id')->firstOrFail();
    $dismissed->update(['dismissed' => true]);

    $ingester->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    expect($denominator())->toBe(2)
        ->and(ExternalFootprint::query()->count())->toBe(3);
});
