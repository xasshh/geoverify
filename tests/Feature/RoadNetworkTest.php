<?php

declare(strict_types=1);

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Coverage\Actions\IngestRoads;
use App\Domain\Coverage\Actions\ReadRoadNetwork;
use App\Domain\Coverage\Models\Road;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/**
 * The street network.
 *
 * Roads are the difference between a map and a set of outlines. Everything here
 * turns on the clip: the network is national, the map is one mandate, and a
 * query that forgets to intersect ships the whole country to a browser.
 */

/** A few ways written straight to the table, without going through a file. */
function road(string $id, string $highway, string $wkt, ?string $name = null): void
{
    DB::statement(
        'insert into roads (source, source_id, name, highway, geometry, created_at, updated_at)
         values (?, ?, ?, ?, ST_GeomFromText(?, 4326), now(), now())',
        ['test', $id, $name, $highway, $wkt],
    );
}

it('loads a network from newline delimited GeoJSON', function () {
    $path = tempnam(sys_get_temp_dir(), 'roads').'.geojsonl';

    file_put_contents($path, implode("\n", [
        json_encode([
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[7.46, 9.05], [7.47, 9.06]]],
            'properties' => ['id' => '101', 'highway' => 'trunk', 'name' => 'Ahmadu Bello Way'],
        ]),
        // No class is not a road, and is skipped rather than stored as one.
        json_encode([
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[7.46, 9.05], [7.47, 9.06]]],
            'properties' => ['id' => '102'],
        ]),
    ])."\n");

    $result = app(IngestRoads::class)->ingest([$path]);

    expect($result['read'])->toBe(2)
        ->and($result['ingested'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and(Road::query()->first()?->name)->toBe('Ahmadu Bello Way');

    // Running it again repairs rather than duplicates, which is what makes an
    // interrupted national extract safe to resume.
    app(IngestRoads::class)->ingest([$path]);

    expect(Road::query()->count())->toBe(1);

    @unlink($path);
});

it('clips the network to the boundary it is asked for', function () {
    $area = testMandate();

    // Inside the test square, and a long way outside it.
    road('inside', 'trunk', 'LINESTRING(7.45 9.04, 7.49 9.07)', 'Inside Way');
    road('outside', 'trunk', 'LINESTRING(3.30 6.50, 3.40 6.60)', 'Lagos Road');

    $collection = app(ReadRoadNetwork::class)->forBoundary(
        'select boundary from coverage_areas where id = ?',
        [$area->id],
    );

    $names = array_map(
        static fn (array $feature): ?string => $feature['properties']['name'],
        $collection['features'],
    );

    expect($names)->toContain('Inside Way')
        ->and($names)->not->toContain('Lagos Road');
});

it('leaves the minor network out of an overview and puts it into detail', function () {
    $area = testMandate();

    road('major', 'primary', 'LINESTRING(7.45 9.04, 7.49 9.07)', 'Primary Way');
    road('minor', 'tertiary', 'LINESTRING(7.45 9.05, 7.49 9.06)', 'Tertiary Street');

    $read = app(ReadRoadNetwork::class);
    $sql = 'select boundary from coverage_areas where id = ?';

    $overview = $read->forBoundary($sql, [$area->id], ReadRoadNetwork::OVERVIEW);
    $detail = $read->forBoundary($sql, [$area->id], ReadRoadNetwork::DETAIL);

    expect($overview['features'])->toHaveCount(1)
        ->and($detail['features'])->toHaveCount(2);
});

it('labels a road once, however many ways it was split into', function () {
    $area = testMandate();

    // One road, three segments, as an extract almost always gives it.
    road('a', 'trunk', 'LINESTRING(7.45 9.04, 7.46 9.05)', 'Ahmadu Bello Way');
    road('b', 'trunk', 'LINESTRING(7.46 9.05, 7.47 9.06)', 'Ahmadu Bello Way');
    road('c', 'trunk', 'LINESTRING(7.47 9.06, 7.48 9.07)', 'Ahmadu Bello Way');

    $labels = app(ReadRoadNetwork::class)->labelsFor(
        'select boundary from coverage_areas where id = ?',
        [$area->id],
    );

    expect($labels)->toHaveCount(1)
        ->and($labels[0]['name'])->toBe('Ahmadu Bello Way')
        // Placed on the line rather than at a bounding box centre, so a label
        // never floats off the road it names.
        ->and($labels[0]['lon'])->toBeGreaterThan(7.44)
        ->and($labels[0]['lon'])->toBeLessThan(7.50);
});

it('serves a client the network under their own campaign, and nobody else\'s', function () {
    $mine = ClientOrganisation::factory()->create();
    $theirs = ClientOrganisation::factory()->create();

    $client = ClientUser::factory()->create(['client_organisation_id' => $mine->id]);
    $ours = Campaign::factory()->forClient($mine)->active()->create();
    $notOurs = Campaign::factory()->forClient($theirs)->active()->create();

    $this->actingAs($client, 'client')
        ->getJson("/client/campaigns/{$ours->id}/roads.json")
        ->assertOk()
        ->assertJsonStructure(['roads' => ['type', 'features'], 'labels']);

    $this->actingAs($client, 'client')
        ->getJson("/client/campaigns/{$notOurs->id}/roads.json")
        ->assertForbidden();
});

it('serves the network to a supervisor for a mandate', function () {
    $area = testMandate();
    road('inside', 'trunk', 'LINESTRING(7.45 9.04, 7.49 9.07)', 'Inside Way');

    $this->actingAs(person(Role::Supervisor))
        ->getJson("/console/coverage/{$area->id}/roads.json")
        ->assertOk()
        ->assertJsonPath('roads.features.0.properties.name', 'Inside Way');
});
