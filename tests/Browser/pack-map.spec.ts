import { expect, test } from '@playwright/test';
import { execSync } from 'node:child_process';

const SHOTS =
    process.env.GV_SHOT_DIR ??
    '/private/tmp/claude-501/-Users-user-Desktop-GeoVerify/ca64e5ec-e7c8-44f7-8128-896b4caeada3/scratchpad/shots';

/**
 * The offline basemap, end to end.
 *
 * The pack is genuinely downloaded, genuinely stored on the device and genuinely
 * read back through the pmtiles protocol. A test that stubbed any of those would
 * prove nothing about whether an officer sees a map in Wuse.
 */

/**
 * The centre of the cell bello actually holds, read from the register.
 *
 * This was a hard coded pair of coordinates, which is only correct for the one
 * dataset it was written against: point it at a differently built register and
 * the officer stands kilometres outside the mandate, the map opens on empty
 * ground, and the failure reads as a broken map rather than as a stale fixture.
 * The other specs in this suite already read their fixtures from the database,
 * and this one now does too.
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
    viewport: { width: 412, height: 915 },
});

test('an officer downloads the pack and the map draws from it', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });

    // A throw inside a map event handler is an uncaught exception, not a console
    // error, and the selection fault below is exactly that shape.
    page.on('pageerror', (error) => {
        errors.push(error.message);
    });

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email').fill('bello@geoverify.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/field', { timeout: 20_000 });

    // The offline maps live on Sync & device since the enumeration redesign.
    await page.goto('/field/device', { waitUntil: 'domcontentloaded' });

    // The size is stated before anything is spent.
    const offer = page.getByRole('button', { name: 'Download the map' });
    await expect(offer).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/MB/).first()).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/01-pack-offered.png` });

    await offer.click();

    await expect(page.getByRole('progressbar', { name: 'Map download' })).toBeVisible();
    await page.waitForTimeout(1_200);
    await page.screenshot({ path: `${SHOTS}/02-pack-downloading.png` });

    // 67 MB over the loopback, so this is generous rather than optimistic.
    await expect(offer).toBeHidden({ timeout: 180_000 });
    await expect(page.getByRole('progressbar', { name: 'Map download' })).toBeHidden({
        timeout: 180_000,
    });

    await page.goto('/field', { waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: /Start a capture/ }).first().click();
    await page.waitForURL('**/capture', { timeout: 20_000 });
    await page.waitForTimeout(8_000);

    const canvas = page.locator('[data-testid="field-map"] canvas');
    await expect(canvas).toBeVisible({ timeout: 30_000 });
    await page.screenshot({ path: `${SHOTS}/03-field-map.png` });

    // Something was actually drawn out of the archive. The canvas itself cannot
    // answer this: MapLibre creates it without preserveDrawingBuffer, so reading
    // its pixels back gives a blank image whether the map worked or not. The
    // count of rendered pack features can, and an empty map reads as zero.
    // Polled rather than read once. The count is republished on every idle, and
    // the first idle happens when the cell and ward outlines are up but the
    // footprint tiles are still decoding: reading then gives a handful of
    // features and calls a working map broken. A large pack hid this by being
    // slow enough that the first idle already had everything.
    const drawn = page.locator('[data-testid="field-map"]');
    await expect
        .poll(async () => Number(await drawn.getAttribute('data-drawn')), { timeout: 30_000 })
        .toBeGreaterThan(100);

    // Selecting a second building has to clear the flag on the first, and that
    // removal is by id: MapLibre throws when a feature state is removed with a
    // source and a source layer but no id, and wiping the whole layer instead
    // would take the visited flags down with it. Neither shows up on one tap.
    const clear = page.getByRole('button', { name: 'Clear selection' });
    await expect(clear).toBeDisabled();

    const box = await canvas.boundingBox();
    expect(box).not.toBeNull();

    const tapABuilding = async (across: number): Promise<void> => {
        for (const down of [0.35, 0.45, 0.55, 0.65]) {
            await page.mouse.click(
                (box?.x ?? 0) + (box?.width ?? 0) * across,
                (box?.y ?? 0) + (box?.height ?? 0) * down,
            );
            await page.waitForTimeout(400);

            if (await clear.isEnabled()) {
                return;
            }
        }

        throw new Error(`Nothing was selectable down x=${across} of the map.`);
    };

    await tapABuilding(0.35);
    await tapABuilding(0.65);

    await expect(clear).toBeEnabled();

    // MapLibre answers a keyed removal with no id by firing an error and leaving
    // the state where it was, so the fault reads as a complaint in the chrome
    // and a building that stays gold, not as an exception.
    await expect(page.getByTestId('map-error')).toHaveCount(0);
    await page.screenshot({ path: `${SHOTS}/04-footprint-selected.png` });

    expect(errors.filter((e) => !e.includes('favicon'))).toEqual([]);
});
