import { expect, test } from '@playwright/test';

const BASE = process.env.GV_BASE_URL ?? 'http://127.0.0.1:8123';

/**
 * The M1 gate: the design gallery has to be looked at, in both modes, before any
 * feature screen is allowed to use these primitives. This produces the artefacts
 * for that review and asserts the handful of things a screenshot cannot show.
 */
for (const mode of ['daylight', 'dusk'] as const) {
    test(`design gallery renders in ${mode}`, async ({ page }) => {
        const errors: string[] = [];
        page.on('console', (m) => {
            if (m.type() === 'error') errors.push(m.text());
        });
        page.on('pageerror', (e) => errors.push(e.message));

        await page.setViewportSize({ width: 1440, height: 940 });
        await page.goto(`${BASE}/design`, { waitUntil: 'networkidle' });

        if (mode === 'dusk') {
            await page.getByRole('button', { name: 'Dusk', exact: true }).click();
        }

        await expect(page.locator('html')).toHaveAttribute('data-mode', mode);

        // The seam bug: body paints from :root, so the mode must reach the root
        // element or the viewport edges stay in the other mode.
        const bodyBg = await page.evaluate(
            () => getComputedStyle(document.body).backgroundColor,
        );
        expect(bodyBg).toBe(mode === 'dusk' ? 'rgb(14, 30, 46)' : 'rgb(247, 246, 243)');

        // Tabular figures are a stated requirement, not a preference.
        const numeric = await page.evaluate(
            () => getComputedStyle(document.documentElement).fontVariantNumeric,
        );
        expect(numeric).toContain('tabular-nums');

        await page.screenshot({
            path: `tests/Browser/screenshots/design-${mode}.png`,
            fullPage: true,
        });

        expect(errors, `console errors in ${mode}`).toEqual([]);
    });
}

test('field touch targets meet the stated floors', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 940 });
    await page.goto(`${BASE}/design`, { waitUntil: 'networkidle' });

    // 52px primary, 48px everything else. These are floors from the brief, and a
    // gloved thumb in sunlight is the reason.
    const primary = page.getByRole('button', { name: 'Capture this building' }).first();
    const secondary = page.getByRole('button', { name: 'Add point' }).first();

    expect((await primary.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(52);
    expect((await secondary.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(48);
});

test('no control inside a field surface falls below the 44px floor', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 940 });
    await page.goto(`${BASE}/design`, { waitUntil: 'networkidle' });

    // The field surfaces section holds the capture screen and the detail sheet.
    // A console-sized control that leaks into either is a thumb-sized defect, and
    // it is the kind of thing that only shows up on a handset in the sun.
    const surfaces = page.locator('section', { has: page.getByText('Field surfaces') });
    const controls = surfaces.locator('button, input, select, [role="button"]');
    const count = await controls.count();

    expect(count).toBeGreaterThan(0);

    const undersized: string[] = [];
    for (let i = 0; i < count; i += 1) {
        const control = controls.nth(i);
        if (!(await control.isVisible())) continue;
        const box = await control.boundingBox();
        const height = box?.height ?? 0;
        if (height < 44) {
            undersized.push(`${(await control.textContent())?.trim() ?? "?"} at ${height}px`);
        }
    }

    expect(undersized, 'controls below the 44px field floor').toEqual([]);
});
