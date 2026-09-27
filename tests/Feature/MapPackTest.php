<?php

declare(strict_types=1);

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Coverage\Models\MapPack;
use App\Enums\Role;
use Illuminate\Support\Facades\Storage;

/**
 * The offline basemap.
 *
 * Two claims are worth a test and the rest is not: an officer gets the packs for
 * their own mandates and no others, and a download that dies partway can be
 * resumed rather than started again. The second is the one that decides whether
 * 67 MB is fetchable at all on the connections this is for.
 */

// The media disk is faked for every case here. Without it a test suite writes
// stub archives into the same directory a real 67 MB pack lives in.
beforeEach(function (): void {
    Storage::fake('media');
});

/** @param array<string, mixed> $overrides */
function packFor(GridCell $cell, string $body = 'PMTiles archive stand in', array $overrides = []): MapPack
{
    $path = 'packs/coverage-'.$cell->coverage_area_id.'/'.uniqid().'.pmtiles';
    Storage::disk('media')->put($path, $body);

    return MapPack::query()->create(array_merge([
        'coverage_area_id' => $cell->coverage_area_id,
        'path' => $path,
        'bytes' => strlen($body),
        'checksum' => hash('sha256', $body),
        'min_zoom' => 10,
        'max_zoom' => 16,
        'layer_counts' => ['footprints' => 12, 'cells' => 3, 'wards' => 1, 'roads' => 40],
        'west' => 7.3, 'south' => 8.9, 'east' => 7.6, 'north' => 9.2,
        'built_at' => now(),
    ], $overrides));
}

it('offers an officer the pack for a mandate they are working', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $pack = packFor($cell);

    $response = $this->actingAs($officer)->getJson('/api/field/packs');

    $response->assertOk()
        ->assertJsonPath('packs.0.id', $pack->id)
        ->assertJsonPath('packs.0.bytes', $pack->bytes)
        ->assertJsonPath('packs.0.checksum', $pack->checksum)
        ->assertJsonPath('packs.0.layers.roads', 40);
});

it('does not offer a pack for a mandate the officer does not work', function () {
    $officer = person(Role::Officer);
    $other = person(Role::Officer, 'Someone else');
    $cell = assignedCell($other, person(Role::Supervisor));
    packFor($cell);

    $this->actingAs($officer)->getJson('/api/field/packs')->assertOk()->assertJsonCount(0, 'packs');
});

it('refuses the bytes to an officer with no assignment in that mandate', function () {
    $intruder = person(Role::Officer);
    $cell = assignedCell(person(Role::Officer, 'Assigned officer'), person(Role::Supervisor));
    $pack = packFor($cell);

    $this->actingAs($intruder)->get("/api/field/packs/{$pack->id}")->assertForbidden();
});

it('serves the pack, and serves a range of it so a broken download resumes', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $body = str_repeat('GEOVERIFY', 200);
    $pack = packFor($cell, $body);

    $whole = $this->actingAs($officer)->get("/api/field/packs/{$pack->id}");
    $whole->assertOk();
    expect($whole->streamedContent())->toBe($body);

    // The half that is missing after a connection drops at 900 bytes. Without a
    // 206 here the client has no way to finish and starts the 67 MB again.
    $rest = $this->actingAs($officer)
        ->withHeaders(['Range' => 'bytes=900-'])
        ->get("/api/field/packs/{$pack->id}");

    $rest->assertStatus(206);
    expect($rest->streamedContent())->toBe(substr($body, 900));
});

it('supersedes the previous pack rather than removing it', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    $old = packFor($cell, 'first build');
    $new = packFor($cell, 'second build');

    $old->update(['superseded_at' => now()]);

    // The old row is still there, and still points at a file, because a handset
    // may be reading it and an officer's day is evidence of what they were shown.
    expect(MapPack::query()->count())->toBe(2)
        ->and(Storage::disk('media')->exists($old->path))->toBeTrue();

    $this->actingAs($officer)->getJson('/api/field/packs')
        ->assertJsonCount(1, 'packs')
        ->assertJsonPath('packs.0.id', $new->id);
});

it('sends a supervisor to the console rather than handing them a field pack', function () {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $pack = packFor($cell);

    $supervisor = person(Role::Supervisor, 'Another supervisor');

    // Not a 403: the field client is not a supervisor's screen at all, so they
    // are put back where their work is.
    $this->actingAs($supervisor)
        ->get("/api/field/packs/{$pack->id}")
        ->assertRedirect(route('console.team'));
});
