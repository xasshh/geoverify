import { expect, test } from '@playwright/test';

const BASE = process.env.GV_BASE_URL ?? 'http://127.0.0.1:8123';

/**
 * The two sign in screens: staff at /login, a commissioning client at
 * /client/sign-in. They share the backdrop component, so what is asserted once
 * about the video is asserted for both.
 *
 * The backdrop is decoration and is tested as decoration: it must play when it
 * can, it must fall back to a still when it should not, and it must never be
 * the reason somebody cannot reach the form.
 */
test('the backdrop plays and the form is usable over it', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

    const video = page.locator('video');
    await expect(video).toHaveCount(1);

    // Playing, not merely present. A poster with a stalled video behind it
    // looks identical in a screenshot and is not what was asked for.
    await expect
        .poll(async () => video.evaluate((v: HTMLVideoElement) => v.currentTime), { timeout: 8000 })
        .toBeGreaterThan(0.2);

    await expect(video).toHaveJSProperty('loop', true);
    await expect(video).toHaveJSProperty('muted', true);

    await page.getByLabel('Email').fill('supervisor@geoverify.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/console'));
});

test('it shows a still instead of the video when motion is unwelcome', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();

    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

    // Not hidden: never mounted. Somebody who has asked for less motion should
    // not be paying for a video they will not be shown.
    await expect(page.locator('video')).toHaveCount(0);
    await expect(page.locator('img[src="/brand/skyline.jpg"]')).toHaveCount(1);

    await context.close();
});

test('it fits a 360px handset without scrolling sideways', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);

    // The primary action has to stay reachable by a thumb at the stated floor.
    const height = (await page.getByRole('button', { name: 'Sign in' }).boundingBox())?.height ?? 0;
    expect(height).toBeGreaterThanOrEqual(48);
});

test('it says nothing where there is nothing to say', async ({ page }) => {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

    // session('status') arrives as null on an ordinary visit, and the guard used
    // to test only for undefined, so an empty status bar drew above the form.
    await expect(page.locator('form p')).toHaveCount(0);
});

test('the client sign in carries the same treatment', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(`${BASE}/client/sign-in`, { waitUntil: 'networkidle' });

    await expect(page.locator('video')).toHaveCount(1);

    // The card is separated by its shadow, not by the keyline that used to box
    // it in. On a photograph a hard border reads as a cutout.
    const card = page.locator('form').locator('..');
    const border = await card.evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(border).toBe('0px');
});

test('the client sign in fits a 360px handset', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto(`${BASE}/client/sign-in`, { waitUntil: 'networkidle' });

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
    expect(
        (await page.getByRole('button', { name: 'Sign in' }).boundingBox())?.height ?? 0,
    ).toBeGreaterThanOrEqual(48);
});

test('the mark is bundled, so it is there with no signal', async ({ page }) => {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

    const mark = page.locator('img[alt="GeoVerify"]');
    await expect(mark).toHaveCount(1);

    // Served from the build, not from public/. The service worker precaches the
    // build, which is what keeps the mark on screen for an officer signing in
    // offline; the backdrop video deliberately sits outside it.
    await expect(mark).toHaveAttribute('src', /\/build\/assets\/geoverify-mark-.*\.png$/);
    await expect
        .poll(async () => mark.evaluate((i: HTMLImageElement) => i.naturalWidth))
        .toBeGreaterThan(0);
});
