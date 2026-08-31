<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Registry\Actions\CaptureStructure;
use App\Domain\Verification\Actions\AssembleReviewRecord;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A supervisor can see the photograph, not just be told one exists.
 *
 * The review screen listed the kind and the metadata and never rendered the
 * picture, and the only route that served one sat behind the field middleware,
 * which redirects a supervisor away. So the column that asks "is this the thing
 * they say it is" was an assertion rather than evidence, and nobody could check
 * it without a database client.
 */
it('gives the review record a usable URL for every photograph', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();

    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    $this->actingAs($officer)->post('/api/field/photographs', [
        'client_uuid' => (string) Str::uuid7(),
        'structure_id' => $structure->id,
        'kind' => 'facade',
        'photo' => UploadedFile::fake()->image('facade.jpg', 1200, 1600),
    ])->assertCreated();

    $observation = $structure->observations()->latest('observed_at')->firstOrFail();
    $record = app(AssembleReviewRecord::class)($observation);

    expect($record['photographs'])->toHaveCount(1)
        ->and($record['photographs'][0]['kind'])->toBe('facade')
        // The bug: this key did not exist, so the screen had nothing to render.
        ->and($record['photographs'][0]['url'])->not->toBeNull();
});

it('serves a photograph to anyone holding the signature, and refuses without it', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    $this->actingAs($officer)->post('/api/field/photographs', [
        'client_uuid' => (string) Str::uuid7(),
        'structure_id' => $structure->id,
        'kind' => 'facade',
        'photo' => UploadedFile::fake()->image('facade.jpg', 800, 600),
    ])->assertCreated();

    $media = Media::query()->firstOrFail();
    $signed = $media->temporaryUrl();

    // A supervisor, who cannot reach the field routes at all, follows the same
    // link an officer would. The signature is the authorisation, exactly as it
    // is for the object storage this stands in for.
    $this->actingAs(person(Role::Supervisor))->get($signed)->assertOk();

    // And the same path without the signature is refused.
    $this->actingAs(person(Role::Supervisor))
        ->get(parse_url($signed, PHP_URL_PATH))
        ->assertForbidden();
});

it('signs media links relative to the path, so they survive a different host', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    $this->actingAs($officer)->post('/api/field/photographs', [
        'client_uuid' => (string) Str::uuid7(),
        'structure_id' => $structure->id,
        'kind' => 'facade',
        'photo' => UploadedFile::fake()->image('facade.jpg', 400, 400),
    ])->assertCreated();

    $signed = Media::query()->firstOrFail()->temporaryUrl();

    /*
     * An absolute signature covers APP_URL, which is the name the outside world
     * calls this application and not necessarily the one the request arrived
     * on. Reached over a tunnel, or by a laptop's LAN address, every photograph
     * would then 403 against a signature computed for somewhere else.
     */
    expect($signed)->toStartWith('/media/file/')
        ->and($signed)->not->toContain('http');
});

it('does not hand out a URL for a file that is gone from storage', function () {
    ['officer' => $officer, 'cell' => $cell] = fieldSetup();
    $structure = app(CaptureStructure::class)->capture(captureFor($cell), $officer);

    $this->actingAs($officer)->post('/api/field/photographs', [
        'client_uuid' => (string) Str::uuid7(),
        'structure_id' => $structure->id,
        'kind' => 'facade',
        'photo' => UploadedFile::fake()->image('facade.jpg', 400, 400),
    ])->assertCreated();

    $media = Media::query()->firstOrFail();
    Storage::disk($media->disk)->delete($media->disk_path);

    $observation = $structure->observations()->latest('observed_at')->firstOrFail();
    $record = app(AssembleReviewRecord::class)($observation);

    // Said plainly rather than rendered as a broken image. A missing file is a
    // finding about the capture, not a rendering accident.
    expect($record['photographs'][0]['url'])->toBeNull();
});
