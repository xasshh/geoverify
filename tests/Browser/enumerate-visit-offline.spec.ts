import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

const BASE = process.env.GV_BASE_URL ?? 'http://127.0.0.1:8123';

/** The smallest PNG there is: a photograph as far as the upload is concerned. */
const PIXEL = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

function tinker(code: string): string {
    return execSync(`php artisan tinker --execute="${code}"`).toString().trim().split('\n').at(-1) ?? '';
}

/**
 * Enumerate E2 on the officer's side: a verification visit filed with the
 * network genuinely down, then delivered once, in order, when it returns.
 *
 * Like the capture spec, the connection is taken away rather than mocked:
 * a request that escapes fails, which is what makes this a test of offline.
 */
test('an officer files a verification visit with no signal, and it arrives when signal returns', async ({ page, context }) => {
    const job = JSON.parse(execSync('php artisan tinker tests/Browser/support/enumerate-visit.php').toString().trim().split('\n').at(-1) ?? '{}') as {
        visitId: number;
        reference: string;
    };

    await context.grantPermissions(['geolocation'], { origin: BASE });
    await context.setGeolocation({ latitude: 9.04244, longitude: 7.4912, accuracy: 5 });

    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email').fill('suleiman@geoverify.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/field', { timeout: 15_000 });

    // Today lists it, and opening Today stores its page for later.
    await expect(page.getByText(`Verification visit · ${job.reference}`)).toBeVisible();
    await page.goto(`${BASE}/field/visits/${String(job.visitId)}`, { waitUntil: 'networkidle' });

    await context.setOffline(true);
    // A reload with no network: the page comes from the device.
    await page.reload();
    await expect(page.getByRole('heading', { name: 'SAHEL SOLAR SYSTEMS LIMITED' })).toBeVisible();

    await page.getByRole('button', { name: 'I have arrived' }).click();
    await expect(page.getByText('Arrival recorded.')).toBeVisible();

    for (const label of ['Storefront', 'Signage']) {
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: new RegExp(`^\\+ ${label}`) }).click();
        await (await chooser).setFiles({ name: `${label}.png`, mimeType: 'image/png', buffer: PIXEL });
        await page.waitForTimeout(400);
    }

    for (const button of await page.getByRole('button', { name: 'Yes', exact: true }).all()) {
        await button.click();
    }

    await page.getByRole('button', { name: 'File the report' }).click();
    await expect(page.getByRole('heading', { name: 'Report filed' })).toBeVisible();
    await expect(page.getByText(/Kept on this device, \d+ items to send/)).toBeVisible();

    // Nothing reached the server.
    expect(tinker(`echo App\\Domain\\Enumerate\\Models\\EnumerateVisit::find(${String(job.visitId)})->status;`)).toBe('assigned');

    // Signal returns; nothing is asked of the officer.
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect(page.getByText('Sent. Your supervisor reviews it before the requester sees it.')).toBeVisible({ timeout: 30_000 });

    // Filed once, with its arrival and both photographs.
    const filed = JSON.parse(
        tinker(`\\$v = App\\Domain\\Enumerate\\Models\\EnumerateVisit::find(${String(job.visitId)}); echo json_encode(['status' => \\$v->status, 'photos' => \\$v->photos()->count(), 'arrived' => \\$v->arrived_at !== null]);`),
    ) as { status: string; photos: number; arrived: boolean };

    expect(filed).toEqual({ status: 'submitted', photos: 2, arrived: true });
});
