<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\GenerateGrid;
use App\Domain\Coverage\Actions\IngestFootprints;
use App\Domain\Coverage\Models\ExternalFootprint;
use Inertia\Testing\AssertableInertia;

it('renders the coverage view with figures read from the database', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $this->get("/console/coverage/{$area->id}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('console/Coverage')
                ->where('area.name', 'Test mandate')
                ->where('summary.cells', fn (int $n) => $n > 300)
                ->has('summary.bounds', 4)
        );
});

it('serves cells as GeoJSON with the properties the map paints from', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $response = $this->get("/console/coverage/{$area->id}/cells.geojson");
    $response->assertOk();

    /** @var array{type: string, features: list<array{geometry: array{type: string}, properties: array<string, mixed>}>} $body */
    $body = $response->json();

    expect($body['type'])->toBe('FeatureCollection')
        ->and($body['features'])->not->toBeEmpty()
        ->and($body['features'][0]['geometry']['type'])->toBe('Polygon')
        ->and($body['features'][0]['properties'])
        ->toHaveKeys(['h3', 'status', 'footprints', 'captured', 'coverage']);
});

it('clips cells to a requested viewport so a large mandate stays usable', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $all = $this->get("/console/coverage/{$area->id}/cells.geojson")->json('features');
    $clipped = $this->get("/console/coverage/{$area->id}/cells.geojson?bbox=7.44,9.03,7.46,9.05")->json('features');

    expect($clipped)->not->toBeEmpty()
        ->and(count($clipped))->toBeLessThan(count($all));
});

it('rejects a malformed bounding box rather than scanning the mandate', function () {
    $area = testMandate();

    $this->get("/console/coverage/{$area->id}/cells.geojson?bbox=not-a-box")
        ->assertSessionHasErrors('bbox');
});

it('serves the mandate outline as GeoJSON', function () {
    $area = testMandate();

    $body = $this->get("/console/coverage/{$area->id}/boundary.geojson")->assertOk()->json();

    expect($body['type'])->toBe('MultiPolygon')
        ->and($body['coordinates'])->not->toBeEmpty();
});

it('reports a denominator that matches what was actually ingested', function () {
    $area = testMandate();
    app(GenerateGrid::class)->generate($area, 9);

    $path = footprintFixture([
        ['wkt' => buildingAt(7.46, 9.05)],
        ['wkt' => buildingAt(7.47, 9.06)],
        ['wkt' => buildingAt(7.48, 9.07)],
    ]);
    app(IngestFootprints::class)->ingest($area, [$path], ExternalFootprint::SOURCE_MICROSOFT);

    $this->get("/console/coverage/{$area->id}")
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('summary.footprints', 3)
                ->where('summary.cellsWithFootprints', fn (int $n) => $n >= 1)
        );
});
