<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The service worker's precache list
|--------------------------------------------------------------------------
|
| A service worker installs all or nothing: one precache entry that 404s fails
| the install, the worker goes redundant, and the app silently stops being
| offline capable. For a field client that is the whole point of it, and it
| fails quietly enough that nobody notices until an officer is standing in a
| dead spot.
|
| This actually happened. The web app manifest was emitted into the build
| directory with every other asset but injected into the precache list at the
| web root, after the prefix rewrite that fixes up everything else.
|
*/

/** @return list<string> */
function precachedUrls(): array
{
    $worker = public_path('sw.js');

    if (! is_file($worker)) {
        return [];
    }

    preg_match_all('/url:"([^"]+)"/', (string) file_get_contents($worker), $matches);

    return $matches[1];
}

it('resolves every URL the service worker precaches', function () {
    $urls = precachedUrls();

    if ($urls === []) {
        expect(is_file(public_path('sw.js')))
            ->toBeFalse('sw.js exists but no precache entries were parsed from it.');

        $this->markTestSkipped('No service worker built. Run npm run build.');
    }

    $missing = [];

    foreach ($urls as $url) {
        $path = public_path(ltrim(parse_url($url, PHP_URL_PATH) ?: $url, '/'));

        if (! is_file($path)) {
            $missing[] = $url;
        }
    }

    expect($missing)->toBe([], 'These precached files do not exist, so the worker install will fail: '
        .implode(', ', $missing));
});

it('precaches the web app manifest, and the document points at one that exists', function () {
    $urls = precachedUrls();

    if ($urls === []) {
        $this->markTestSkipped('No service worker built. Run npm run build.');
    }

    $manifests = array_values(array_filter(
        $urls,
        static fn (string $url): bool => str_ends_with($url, 'manifest.webmanifest'),
    ));

    expect($manifests)->not->toBeEmpty('The web app manifest is not precached at all.');

    foreach ($manifests as $manifest) {
        expect(is_file(public_path(ltrim($manifest, '/'))))->toBeTrue("{$manifest} is precached but not on disk.");
    }
});
