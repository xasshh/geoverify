import { expect, test } from '@playwright/test';

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

// Inside the cell bello holds, so the position is on the map rather than off it.
test.use({
    geolocation: { latitude: 9.068962, longitude: 7.383084, accuracy: 6 },
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

    await page.getByText('Open this cell').first().click();
    await page.waitForTimeout(8_000);

    const canvas = page.locator('[data-testid="field-map"] canvas');
    await expect(canvas).toBeVisible({ timeout: 30_000 });
    await page.screenshot({ path: `${SHOTS}/03-field-map.png` });

    // Something was actually drawn out of the archive. The canvas itself cannot
    // answer this: MapLibre creates it without preserveDrawingBuffer, so reading
    // its pixels back gives a blank image whether the map worked or not. The
    // count of rendered pack features can, and an empty map reads as zero.
    const drawn = page.locator('[data-testid="field-map"]');
    await expect(drawn).toHaveAttribute('data-drawn', /^[1-9][0-9]*$/, { timeout: 30_000 });

    expect(Number(await drawn.getAttribute('data-drawn'))).toBeGreaterThan(100);

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
