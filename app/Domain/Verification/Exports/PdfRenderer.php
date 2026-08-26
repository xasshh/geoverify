<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Prints a URL to PDF with a headless browser.
 *
 * The evidence pack is rendered by a real engine on purpose. Its gate is that
 * it looks like a survey document, and the pure PHP alternatives cannot draw
 * inline SVG, a CSS grid contact sheet, or running heads and page breaks well
 * enough to reach that. Rendering the same HTML and CSS the console already
 * serves also means the pack inherits the self hosted fonts and their subsets,
 * rather than growing a second typographic pipeline that can drift from the
 * first without anyone noticing.
 */
final class PdfRenderer
{
    /** Where a browser usually is, when nobody has said. */
    private const LIKELY_PATHS = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/snap/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ];

    public function render(string $url, string $destination): void
    {
        $binary = $this->binary();

        $process = new Process([
            $binary,
            '--headless=new',
            // The renderer runs as the web user in a container more often than
            // not, where a sandbox it cannot set up is a hang rather than an
            // error. Nothing untrusted is loaded: the page is this application.
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            // Backgrounds are the document. Without this the paper comes out
            // white and every rule and panel on the page disappears.
            '--print-to-pdf-no-header',
            '--no-pdf-header-footer',
            '--print-to-pdf='.$destination,
            // Long enough for the fonts and the map geometry to settle.
            '--virtual-time-budget=8000',
            $url,
        ]);

        $process->setTimeout((float) config('services.chromium.timeout', 120));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Almost always one thing: the browser is fetching the document
            // from this application, and this application is busy serving the
            // request that started the browser. Anything with one worker
            // deadlocks here, and php artisan serve has one by default.
            throw new RuntimeException(
                'The browser timed out fetching the pack. It renders the document from this '
                .'application, so the server has to be able to answer a second request while '
                .'this one is open. Run it with more than one worker: PHP_CLI_SERVER_WORKERS=4 '
                .'php artisan serve locally, or more than one php-fpm worker in production.',
            );
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException(
                'The browser did not produce a PDF. '.trim($process->getErrorOutput() ?: $process->getOutput()),
            );
        }
    }

    /**
     * The configured browser, or the first one in the usual places.
     *
     * Fails with the name of the setting rather than a file-not-found, because
     * the person hitting this is deploying rather than debugging.
     */
    public function binary(): string
    {
        $configured = config('services.chromium.binary');

        if (is_string($configured) && $configured !== '') {
            if (! is_executable($configured)) {
                throw new RuntimeException("CHROMIUM_BINARY is set to {$configured}, which is not executable.");
            }

            return $configured;
        }

        foreach ($this->candidates() as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            'No headless browser was found. Install Chromium, or set CHROMIUM_BINARY to its path.',
        );
    }

    /** Whether a pack can be printed at all, for a screen that should say so. */
    public function available(): bool
    {
        try {
            $this->binary();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function candidates(): array
    {
        $paths = self::LIKELY_PATHS;

        // A developer machine usually has one already, downloaded by Playwright
        // for the browser tests. Worth finding before asking them to install a
        // second copy of the same thing.
        $cache = getenv('HOME');

        if (is_string($cache) && $cache !== '') {
            foreach (glob($cache.'/Library/Caches/ms-playwright/chromium*/chrome-*/*') ?: [] as $found) {
                if (is_executable($found) && ! is_dir($found)) {
                    $paths[] = $found;
                }
            }

            foreach (glob($cache.'/.cache/ms-playwright/chromium*/chrome-*/chrome') ?: [] as $found) {
                $paths[] = $found;
            }
        }

        return $paths;
    }
}
