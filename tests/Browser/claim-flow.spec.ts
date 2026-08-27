import { test, expect, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * The claim flow, driven the way a shop owner would drive it.
 *
 * The domain tests prove the rules. This proves the screens exist, that the
 * four of them join up, and that the whole thing is usable on the 360px handset
 * this audience actually holds.
 *
 * The codes are read from the log because there is no SMS gateway procured.
 * That is the same seam the Pest suite reads through its issue event.
 */

/** The most recent code the app logged for a given purpose. */
function lastLoggedCode(pattern: RegExp): string {
    const log = execSync('tail -400 storage/logs/laravel.log').toString();
    const hits = [...log.matchAll(pattern)];
    const last = hits.at(-1);

    if (last === undefined) {
        throw new Error(`No code matching ${String(pattern)} in the log.`);
    }

    return last[1];
}

/**
 * The real sequence: phone first, code second, details last.
 *
 * Registration is not a form you fill and submit. The phone is proved before
 * anything else is asked, which means the account is created against a number
 * somebody demonstrably holds rather than one they typed.
 */
async function registerParty(page: Page, phone: string, name: string) {
    await page.goto('/portal/sign-in');
    await page.getByLabel('Phone number').fill(phone);
    await page.getByRole('button', { name: 'Send me a code' }).click();

    await expect(page).toHaveURL(/\/portal\/verify/);
    const signInCode = lastLoggedCode(/Portal sign-in code for \+\d+: (\d{6})/g);
    await page.getByLabel('Code').fill(signInCode);
    await page.getByRole('button', { name: /confirm|continue|sign in/i }).click();

    await expect(page).toHaveURL(/\/portal\/register/);
    await page.getByLabel('Your name').fill(name);
    await page.getByLabel('Business name').fill(name + ' Enterprises');
    await page.getByRole('button', { name: 'Create my account' }).click();

    // Waited for here rather than by each caller. Without it the next
    // navigation races the registration POST and lands on sign-in, which is a
    // confusing way for a test to fail at a step three screens later.
    await expect(page).toHaveURL(/\/portal$/);
}

/**
 * An unclaimed shop the seeder gave a phone number to.
 *
 * Read from the database rather than hard coded, and it excludes listings that
 * already have a controller: this test claims a shop, so running it twice must
 * not mean fighting its own first run for the same one.
 */
const TARGET = JSON.parse(
    execSync(
        `php artisan tinker --execute="echo json_encode(DB::selectOne(\\"select e.trading_name name, w.name ward from enterprises e join structures s on s.id=e.structure_id left join admin_boundaries w on w.id=s.ward_id left join party_businesses pb on pb.enterprise_id=e.id and pb.status='active' join lateral (select phone from enterprise_observations where enterprise_id=e.id order by observed_at desc limit 1) o on true where o.phone is not null and s.status <> 'rejected' and pb.id is null order by e.id desc limit 1\\"));"`,
    )
        .toString()
        .trim()
        .split('\n')
        .at(-1) ?? '{}',
) as { name: string; ward: string };

test('a shop owner finds their shop, proves it by phone, and manages it', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });

    const phone = '0805' + String(Date.now()).slice(-7);
    await registerParty(page, phone, 'Adaeze Nwosu');
    await page.screenshot({ path: 'tests/Browser/screenshots/claim-1-dashboard.png' });

    // Find the shop.
    await page.getByRole('link', { name: /find your business/i }).click();
    await expect(page).toHaveURL(/\/portal\/claim/);
    await page.getByLabel('Business name').fill(TARGET.name);
    await page.getByRole('button', { name: 'Search' }).click();

    // The register holds several shops of this name, and the row that offers
    // phone proof is the one this owner can actually finish. That marker is on
    // the row for exactly this reason: without it a searcher looking at similar
    // listings has nothing to choose by.
    const row = page
        .locator('li')
        .filter({ has: page.getByTestId('phone-available') })
        .first();
    await expect(row.getByRole('heading', { name: TARGET.name })).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/screenshots/claim-2-search.png' });

    // Claim it.
    await row.getByRole('button', { name: 'This is mine' }).click();
    await page.getByRole('button', { name: /claim this business/i }).click();

    await expect(page).toHaveURL(/\/portal\/claim\/\d+/);
    // The mask, and nothing more of the number.
    await expect(page.getByText(/•••/)).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/screenshots/claim-3-prove.png' });

    // Prove it.
    await page.getByRole('button', { name: /send the code/i }).click();
    await expect(page.getByText(/we sent a code/i)).toBeVisible();

    const claimCode = lastLoggedCode(/Claim code to \+\d+: GeoVerify: (\d{6})/g);
    await page.getByLabel(/six digit code/i).fill(claimCode);
    await page.getByRole('button', { name: 'Confirm' }).click();

    await expect(page.getByText(/this business is yours/i)).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/screenshots/claim-4-approved.png' });

    // Manage it.
    await page.getByRole('link', { name: /open the listing/i }).click();
    await expect(page).toHaveURL(/\/portal\/businesses\/\d+/);
    await expect(page.getByRole('heading', { name: TARGET.name })).toBeVisible();
    await expect(page.getByText(/not editable/i)).toBeVisible();
    await page.screenshot({
        path: 'tests/Browser/screenshots/claim-5-listing.png',
        fullPage: true,
    });

    // Nothing on this page offers to edit the officer's record.
    await expect(page.getByRole('button', { name: /edit|change|update/i })).toHaveCount(0);
});

test('a second claimant is told what happens next, and the supervisor sees both sides', async ({
    page,
    browser,
}) => {
    await page.setViewportSize({ width: 360, height: 780 });

    // A listing somebody already controls, taken from whatever the first test
    // (or a previous run) settled.
    const held = JSON.parse(
        execSync(
            `php artisan tinker --execute="echo json_encode(DB::selectOne(\\"select e.trading_name name, w.name ward from party_businesses pb join enterprises e on e.id=pb.enterprise_id join structures s on s.id=e.structure_id left join admin_boundaries w on w.id=s.ward_id where pb.status='active' order by pb.id desc limit 1\\"));"`,
        )
            .toString()
            .trim()
            .split('\n')
            .at(-1) ?? '{}',
    ) as { name: string; ward: string };

    const phone = '0807' + String(Date.now()).slice(-7);
    await registerParty(page, phone, 'Emeka Okonkwo');

    await page.goto('/portal/claim?q=' + encodeURIComponent(held.name));

    const row = page
        .locator('li')
        .filter({ hasText: held.ward })
        .filter({ hasText: 'Already claimed' })
        .first();

    await row.getByRole('button', { name: 'This is mine' }).click();

    // The consequence is stated before the button, not after it.
    await expect(page.getByText(/opens a dispute/i)).toBeVisible();
    await expect(page.getByText(/they keep managing it until then/i)).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/screenshots/claim-6-dispute-warning.png' });

    await page.getByRole('button', { name: /claim and open a dispute/i }).click();

    await expect(page.getByText(/someone else holds this listing/i)).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/screenshots/claim-7-disputed.png' });

    // And the supervisor sees it. In a second browser context, because a
    // supervisor is a different person on a different machine, and because the
    // two guards sharing one browser is a test artefact rather than a scenario.
    const console_ = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const desk = await console_.newPage();

    await desk.goto('/login');
    await desk.getByLabel(/email/i).fill('supervisor@geoverify.test');
    await desk.getByLabel(/password/i).fill('password');
    await desk.getByRole('button', { name: 'Sign in' }).click();
    await expect(desk).toHaveURL(/\/console\//);

    await desk.goto('/console/claims');

    await expect(desk.getByRole('heading', { name: 'Disputed listings' })).toBeVisible();
    await expect(desk.getByText('Holds it now').first()).toBeVisible();
    await expect(desk.getByText('Challenging').first()).toBeVisible();

    // The resolution is a decision with named outcomes, not a free text box.
    await expect(desk.getByRole('combobox').first()).toBeVisible();

    await desk.screenshot({
        path: 'tests/Browser/screenshots/claim-8-console-queue.png',
        fullPage: true,
    });

    await console_.close();
});
