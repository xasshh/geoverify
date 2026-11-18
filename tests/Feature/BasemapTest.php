<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

/*
 * The street basemap: a file on disk, served by range, and announced to the
 * pages only when it is there.
 */

function basemapPath(): string
{
    return (string) config('geoverify.basemap.path');
}

beforeEach(function (): void {
    config()->set('geoverify.basemap.path', sys_get_temp_dir().'/geoverify-basemap-'.uniqid().'.pmtiles');
});

afterEach(function (): void {
    @unlink(basemapPath());
});

it('answers not found until the basemap has been fetched', function (): void {
    $this->get('/basemap/nigeria.pmtiles')->assertNotFound();

    $this->get('/health')->assertInertia(fn (AssertableInertia $page) => $page->where('basemap', null));
});

it('serves the basemap in ranges, which is how a map reads it', function (): void {
    file_put_contents(basemapPath(), 'PMTiles'.str_repeat('x', 1000));

    $response = $this->get('/basemap/nigeria.pmtiles', ['Range' => 'bytes=0-6'])
        ->assertStatus(206)
        ->assertHeader('Content-Type', 'application/vnd.pmtiles')
        ->assertHeader('Content-Range', 'bytes 0-6/1007');

    ob_start();
    $response->baseResponse->sendContent();

    expect(ob_get_clean())->toBe('PMTiles');
});

it('tells every page where the basemap is once it exists', function (): void {
    file_put_contents(basemapPath(), 'PMTiles');

    $this->get('/health')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('basemap.url', '/basemap/nigeria.pmtiles')
        ->where('basemap.attribution', '© OpenStreetMap contributors, Protomaps'));
});
