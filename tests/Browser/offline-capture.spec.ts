import { expect, test, type Page } from '@playwright/test';

const BASE = process.env.GV_BASE_URL ?? 'http://127.0.0.1:8123';

/**
 * The claim this milestone rests on: an officer cannot lose work.
 *
 * The network is genuinely taken down for the whole capture, not throttled and
 * not mocked. If a request escapes, it fails, which is the point: a test that
 * lets one through is not testing offline.
 */

async function signIn(page: Page, email: string): Promise<void> {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/field', { timeout: 15_000 });
}

async function openFirstCell(page: Page): Promise<void> {
    await page.getByText('Open this cell').first().click();
    await page.waitForTimeout(3_000);
}

async function captureBuilding(page: Page, units: string): Promise<void> {
    await page.getByRole('button', { name: 'Capture this building' }).click();
    await page.waitForTimeout(600);
    await page.getByLabel('Units in this building').fill(units);
    await page.getByLabel('I read this out and they agreed').check();
    await page.getByRole('button', { name: 'Save capture' }).click();
    await page.waitForTimeout(1_200);
}

/**
 * What the device is holding, read from IndexedDB rather than from the screen.
 *
 * The queue is the claim being tested, so it is read directly. What the officer
 * sees is checked separately.
 */
async function held(page: Page): Promise<Array<{ state: string; entity: string }>> {
    return page.evaluate(
        () =>
            new Promise<Array<{ state: string; entity: string }>>((resolve, reject) => {
                const request = indexedDB.open('geoverify');

                request.onerror = () => {
                    reject(new Error('could not open the local database'));
                };

                request.onsuccess = () => {
                    const db = request.result;
                    const tx = db.transaction('mutations', 'readonly');
                    const all = tx.objectStore('mutations').getAll();

                    tx.oncomplete = () => {
                        resolve(all.result as Array<{ state: string; entity: string }>);
                        db.close();
                    };
                };
            }),
    );
}

async function localStructureCount(page: Page): Promise<number> {
    return page.evaluate(
        () =>
            new Promise<number>((resolve) => {
                const request = indexedDB.open('geoverify');

                request.onsuccess = () => {
                    const db = request.result;
                    const tx = db.transaction('structures', 'readonly');
                    const count = tx.objectStore('structures').count();

                    tx.oncomplete = () => {
                        resolve(count.result);
                        db.close();
                    };
                };
            }),
    );
}

test('an officer captures a full building with the network off, and loses nothing', async ({
    page,
    context,
}) => {
    await signIn(page, 'bello@geoverify.test');
    await openFirstCell(page);

    // The radio goes off. Everything from here happens on the device alone.
    await context.setOffline(true);

    const refused: string[] = [];
    page.on('requestfailed', (r) => {
        if (r.url().includes('/api/field/')) {
            refused.push(r.url());
        }
    });

    await captureBuilding(page, '14');

    // The officer is told the work is safe, not shown an error.
    const footer = await page.locator('footer p').first().textContent();
    expect(footer).toContain('Saved on this device');

    expect(await localStructureCount(page)).toBeGreaterThan(0);
    expect(await held(page)).not.toHaveLength(0);

    // Requests were genuinely attempted and genuinely refused. If this is empty
    // the browser was not actually offline and the test proves nothing.
    expect(refused.length).toBeGreaterThan(0);
});

test('work captured offline arrives once the connection comes back', async ({ page, context }) => {
    await signIn(page, 'okafor@geoverify.test');
    await openFirstCell(page);

    await context.setOffline(true);
    await captureBuilding(page, '6');

    const before = await held(page);
    expect(before).not.toHaveLength(0);
    expect(before.every((m) => m.state !== 'done')).toBe(true);

    // Signal returns. Nothing is asked of the officer: no button, no prompt.
    await context.setOffline(false);

    // Polled in the test rather than in the browser, so a failure reports what
    // the queue actually said instead of a bare false.
    let rows = await held(page);

    for (let attempt = 0; attempt < 20 && !rows.every((m) => m.state === 'done'); attempt += 1) {
        await page.waitForTimeout(2_000);
        rows = await held(page);
    }

    expect(rows.map((m) => m.state)).toEqual(rows.map(() => 'done'));
});

test('the app opens with no network at all', async ({ page, context }) => {
    // Precached by the service worker. An officer who opens the app in a dead
    // spot gets the interface, not a browser error page.
    await signIn(page, 'bello@geoverify.test');
    await page.waitForTimeout(3_000);

    await context.setOffline(true);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => undefined);
    await page.waitForTimeout(1_500);

    // Something of ours rendered rather than the browser's offline page.
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('ERR_INTERNET_DISCONNECTED');
});
