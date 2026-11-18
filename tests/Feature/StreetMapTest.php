<?php

declare(strict_types=1);

use App\Domain\Imagery\StreetMaps;
use App\Enums\Role;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The offline street map: the national basemap cut to each mandate, offered
 * to the officers working it and to nobody else, beside the packs.
 */

beforeEach(function (): void {
    $root = sys_get_temp_dir().'/geoverify-streets-'.uniqid();
    mkdir($root);
    config()->set('geoverify.basemap.path', "{$root}/nigeria.pmtiles");
    file_put_contents("{$root}/nigeria.pmtiles", 'national');

    // pmtiles extract, stood in for: it writes the cut where it was told to.
    Process::fake(function (PendingProcess $process) {
        $command = (array) $process->command;
        file_put_contents((string) $command[3], 'PMTiles'.str_repeat('s', 2000));

        return Process::result();
    });
});

afterEach(function (): void {
    File::deleteDirectory(dirname((string) config('geoverify.basemap.path')));
});

it('cuts a mandate to its own box and notes its size and checksum', function (): void {
    $cell = assignedCell(person(Role::Officer), person(Role::Supervisor));

    $meta = app(StreetMaps::class)->build($cell->coverage_area_id);

    expect($meta['bytes'])->toBe(2007)
        ->and($meta['checksum'])->toBe(md5('PMTiles'.str_repeat('s', 2000)))
        ->and($meta['bounds'][0])->toBeLessThan($meta['bounds'][2])
        ->and($meta['bounds'][1])->toBeLessThan($meta['bounds'][3]);

    Process::assertRan(fn (PendingProcess $process) => in_array('extract', (array) $process->command, true)
        && collect((array) $process->command)->contains(fn ($part) => str_starts_with((string) $part, '--bbox=')));
});

it('offers the street map to the officer working the mandate, by range, and to nobody else', function (): void {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));
    $this->artisan('geoverify:streets-build')->assertSuccessful();

    $offer = $this->actingAs($officer)->getJson('/api/field/streets')
        ->assertOk()
        ->assertJsonCount(1, 'streets')
        ->json('streets.0');

    expect($offer['coverageAreaId'])->toBe($cell->coverage_area_id)
        ->and($offer['bytes'])->toBe(2007);

    $this->actingAs($officer)
        ->get("/api/field/streets/{$cell->coverage_area_id}", ['Range' => 'bytes=0-6'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-6/2007');

    $stranger = person(Role::Officer, 'Elsewhere');
    $this->actingAs($stranger)->getJson('/api/field/streets')->assertOk()->assertJsonCount(0, 'streets');
    $this->actingAs($stranger)->get("/api/field/streets/{$cell->coverage_area_id}")->assertForbidden();
});

it('offers nothing for a mandate that has not been cut, rather than a dead download', function (): void {
    $officer = person(Role::Officer);
    $cell = assignedCell($officer, person(Role::Supervisor));

    $this->actingAs($officer)->getJson('/api/field/streets')->assertOk()->assertJsonCount(0, 'streets');
    $this->actingAs($officer)->get("/api/field/streets/{$cell->coverage_area_id}")->assertNotFound();
});
