import { expect, test, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';

/**
 * The street map on an officer's phone, with the network genuinely down.
 *
 * It downloads by itself beside the pack; the fonts for its names go into the
 * worker's cache with it. Then the connection is cut and the capture map is
 * reloaded: the buildings must still draw out of the pack, and the street
 * names must still draw, which they can only do if the fonts came off the
 * device. Requires a cut for bello's mandate (geoverify:streets-build).
 */

const HERE = JSON.parse(
    execSync(
        `php artisan tinker --execute="echo json_encode(DB::selectOne(\\"select ST_X(h3_cell_to_lat_lng(g.h3_index::h3index)::geometry) lng, ST_Y(h3_cell_to_lat_lng(g.h3_index::h3index)::geometry) lat from assignments a join grid_cells g on g.id = a.grid_cell_id join users u on u.id = a.user_id where u.email = 'bello@geoverify.test' and a.closed_at is null order by a.id limit 1\\"));"`,
    )
        .toString()
        .trim()
        .split('\n')
        .at(-1) ?? '{}',
) as { lat: number; lng: number };

test.use({
    geolocation: { latitude: Number(HERE.lat), longitude: Number(HERE.lng), accuracy: 6 },
    permissions: ['geolocation'],
    viewport: { width: 412, height: 915 },
});

/** Whether the street map is whole on the device, read from IndexedDB. */
async function streetsHeld(page: Page): Promise<boolean> {
    return page.evaluate(
        () =>
            new Promise<boolean>((resolve) => {
                const request = indexedDB.open('geoverify');

                request.onsuccess = () => {
                    const db = request.result;

                    if (!db.objectStoreNames.contains('streets')) {
                        db.close();
                        resolve(false);

                        return;
                    }

                    const all = db.transaction('streets', 'readonly').objectStore('streets').getAll();
                    all.onsuccess = () => {
                        const rows = all.result as Array<{ blob: Blob | null }>;
                        resolve(rows.some((row) => row.blob !== null));
                        db.close();
                    };
                };
                request.onerror = () => {
                    resolve(false);
                };
            }),
    );
}

/** A street map has been cut on this machine. CI has no national basemap to cut from. */
const CUT = 'storage/app/basemap/areas';
const HAS_CUT = existsSync(CUT) && readdirSync(CUT).some((name) => name.endsWith('.json'));

test('the street map and its names draw with the network down', async ({ page, context }) => {
    test.skip(!HAS_CUT, 'No street map cut here: run geoverify:basemap-fetch (or geoverify:streets-build) first.');
    test.setTimeout(300_000);

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email').fill('bello@geoverify.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/field', { timeout: 20_000 });

    await page.goto('/field/device', { waitUntil: 'domcontentloaded' });

    // A fresh browser holds no pack, so it is offered and fetched first.
    const offer = page.getByRole('button', { name: 'Download the map' });
    await expect(offer).toBeVisible({ timeout: 20_000 });
    await offer.click();
    await expect(offer).toBeHidden({ timeout: 180_000 });

    // Nobody pressed anything for this one.
    await expect.poll(() => streetsHeld(page), { timeout: 60_000 }).toBe(true);

    // Online once, so the worker controls the page and the screen is cached.
    await page.goto('/field', { waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: /Start a capture/ }).first().click();
    await page.waitForURL('**/capture', { timeout: 20_000 });

    const map = page.locator('[data-testid="field-map"]');
    await expect.poll(async () => Number(await map.getAttribute('data-street-labels')), { timeout: 30_000 }).toBeGreaterThan(0);

    // Loaded whole once with signal, as a working day would, so the screen
    // itself is on the device; then the connection goes and it is reloaded.
    await page.goto(page.url(), { waitUntil: 'networkidle' });
    await page.waitForTimeout(2_000);
    await context.setOffline(true);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => undefined);

    await expect.poll(async () => Number(await map.getAttribute('data-drawn')), { timeout: 45_000 }).toBeGreaterThan(100);
    await expect.poll(async () => Number(await map.getAttribute('data-street-labels')), { timeout: 45_000 }).toBeGreaterThan(0);

    await context.setOffline(false);
});
