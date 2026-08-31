<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use Illuminate\Http\Request;

/**
 * Printing a page of this application with the browser on this host.
 *
 * Extracted when the campaign brief became the second thing printed this way.
 * The evidence pack worked out both of these the hard way and neither is
 * obvious, so the second caller inherits them rather than rediscovering them.
 */
final class LoopbackPrint
{
    public function __construct(private readonly PdfRenderer $renderer) {}

    /**
     * The URL the local browser should fetch.
     *
     * Not APP_URL. That is what the outside world calls this application, and it
     * is not necessarily a name this host can resolve or a port it answers on: a
     * document that only prints when DNS agrees with itself is a document that
     * fails in production. The loopback address with the port this very request
     * arrived on is always somewhere the application is listening.
     */
    public function url(Request $request, string $signedUrl): string
    {
        $parts = parse_url($signedUrl);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $base = config('services.chromium.base_url');

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').$path;
        }

        return sprintf('%s://127.0.0.1:%d%s', $request->getScheme(), $request->getPort(), $path);
    }

    /** The rendered bytes, with the temporary file cleaned up either way. */
    public function toString(string $url): string
    {
        $file = tempnam(sys_get_temp_dir(), 'geoverify-print-').'.pdf';

        try {
            $this->renderer->render($url, $file);

            return (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    public function available(): bool
    {
        return $this->renderer->available();
    }
}
