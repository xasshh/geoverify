<?php

declare(strict_types=1);

namespace App\Domain\Imagery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Finds the clearest Sentinel-2 images over a piece of ground.
 *
 * Asks the Earth Search STAC catalogue (AWS open data, no account) for scenes
 * intersecting the mandate, then keeps the least cloudy scene for each
 * Sentinel-2 tile. A mandate that straddles two or three tiles therefore gets
 * one clear image of each, possibly from different passes, and the officer is
 * shown the date range.
 *
 * Only the catalogue is read here. The pixels are fetched later, by GDAL, by
 * byte range straight from the archive, so nothing larger than the mandate is
 * ever downloaded.
 */
final class SentinelCatalogue
{
    private const PAGE_SIZE = 200;

    private const MAX_PAGES = 5;

    /**
     * @param  array<string, mixed>  $intersects  GeoJSON geometry, in EPSG:4326
     * @return list<array{id: string, tile: string, date: string, cloud: float, href: string}>
     *
     * @throws RuntimeException when the catalogue cannot be reached
     */
    public function clearestScenes(array $intersects): array
    {
        $base = rtrim((string) config('geoverify.imagery.stac_url'), '/');
        $to = now();
        $from = $to->copy()->subDays((int) config('geoverify.imagery.lookback_days'));

        $body = [
            'collections' => [(string) config('geoverify.imagery.collection')],
            'intersects' => $intersects,
            'datetime' => $from->toIso8601ZuluString().'/'.$to->toIso8601ZuluString(),
            'query' => ['eo:cloud_cover' => ['lt' => (float) config('geoverify.imagery.max_cloud_pct')]],
            'sortby' => [['field' => 'properties.eo:cloud_cover', 'direction' => 'asc']],
            'limit' => self::PAGE_SIZE,
        ];

        $best = [];
        $url = "{$base}/search";

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            try {
                $response = Http::acceptJson()->timeout(60)->post($url, $body);
            } catch (ConnectionException) {
                throw new RuntimeException('The satellite catalogue could not be reached. Try again shortly.');
            }

            if (! $response->successful()) {
                throw new RuntimeException("The satellite catalogue refused the search ({$response->status()}).");
            }

            /** @var list<array<string, mixed>> $features */
            $features = $response->json('features', []);

            foreach ($features as $item) {
                $scene = self::scene($item);

                if ($scene === null) {
                    continue;
                }

                // Least cloudy wins; on a tie, the more recent pass.
                $held = $best[$scene['tile']] ?? null;

                if ($held === null
                    || $scene['cloud'] < $held['cloud']
                    || ($scene['cloud'] === $held['cloud'] && $scene['date'] > $held['date'])) {
                    $best[$scene['tile']] = $scene;
                }
            }

            [$url, $body] = self::next($response->json('links', []), $body);
        }

        ksort($best);

        return array_values($best);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, tile: string, date: string, cloud: float, href: string}|null
     */
    private static function scene(array $item): ?array
    {
        $id = $item['id'] ?? null;
        $href = $item['assets']['visual']['href'] ?? null;
        $props = is_array($item['properties'] ?? null) ? $item['properties'] : [];
        $cloud = $props['eo:cloud_cover'] ?? null;
        $date = $props['datetime'] ?? null;

        if (! is_string($id) || ! is_string($href) || ! is_numeric($cloud) || ! is_string($date)) {
            return null;
        }

        // grid:code is "MGRS-32NMP"; older items carry the tile only in the id
        // (S2C_32NMP_20251119_0_L2A).
        $tile = is_string($props['grid:code'] ?? null)
            ? str_replace('MGRS-', '', $props['grid:code'])
            : (preg_match('/_(\d{2}[A-Z]{3})_/', $id, $m) === 1 ? $m[1] : $id);

        return [
            'id' => $id,
            'tile' => $tile,
            'date' => substr($date, 0, 10),
            'cloud' => round((float) $cloud, 2),
            'href' => $href,
        ];
    }

    /**
     * The next page, as the catalogue describes it.
     *
     * @param  array<int, mixed>  $links
     * @param  array<string, mixed>  $body
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private static function next(array $links, array $body): array
    {
        foreach ($links as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'next' && is_string($link['href'] ?? null)) {
                $merged = is_array($link['body'] ?? null) ? array_merge($body, $link['body']) : $body;

                return [$link['href'], $merged];
            }
        }

        return [null, $body];
    }
}
