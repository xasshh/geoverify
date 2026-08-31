<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A media file from the local disk, behind a signature.
 *
 * Stands in for a presigned object storage URL on a machine with no S3. The
 * signature is the authorisation and it expires in minutes, which is exactly
 * the contract the object storage path offers.
 *
 * Served as a file response rather than a stream so the framework answers Range
 * requests, which is what lets a photograph seek and a large pack resume.
 */
final class MediaFileController
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        $disk = Storage::disk('media');

        // The signature already covers the path, so a traversal would have to be
        // signed by us to arrive here at all. Checked anyway: a bug that let a
        // path be signed is a bug that would otherwise read any file on the box.
        $root = realpath($disk->path(''));
        $file = realpath($disk->path($path));

        if ($root === false || $file === false || ! str_starts_with($file, $root)) {
            abort(404);
        }

        return response()->file($file, [
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
