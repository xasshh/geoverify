<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The street basemap, read by MapLibre in ranges.
 *
 * Open without a session: it is OpenStreetMap, public already, and says
 * nothing about this system's work. BinaryFileResponse answers Range requests
 * itself, which is all a PMTiles reader asks for. In production nginx can
 * serve the same file directly; this route is what works everywhere.
 */
final class BasemapController
{
    public function __invoke(): BinaryFileResponse
    {
        $path = (string) config('geoverify.basemap.path');

        abort_unless(is_file($path), 404);

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.pmtiles',
            'Cache-Control' => 'public, max-age=86400',
        ], true, null, true, true);
    }
}
