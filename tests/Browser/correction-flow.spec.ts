import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const BASE = process.env.GV_BASE_URL ?? 'http://127.0.0.1:8123';

/**
 * M5, driven the way the two people involved drive it.
 *
 * The domain tests prove the rules. This proves the screens join up: a business
 * says the register has its name wrong, a supervisor reads that and rules on
 * it, and what the officer recorded is still there afterwards.
 */

/** The most recent code the app logged, read the same way the claim flow does. */
function lastLoggedCode(pattern: RegExp): string {
    const log = execSync('tail -400 storage/logs/laravel.log').toString();
    const last = [...log.matchAll(pattern)].at(-1);

    if (last === undefined) {
        throw new Error(`No code matching ${String(pattern)} in the log.`);
    }

    return last[1];
}

/**
 * A party already holding a listing, set up out of band.
 *
 * The claim flow has its own test. Re-driving five screens to reach the one
 * under test here would make this fail for reasons that have nothing to do with
 * corrections.
 *
 * Deliberately takes a listing whose latest observation has no phone. Both
 * specs share one database and both need a listing nobody controls, but only
 * the claim flow needs one it can prove ownership of by phone. Drawing from
 * disjoint pools is what stops this test quietly breaking that one.
 */
function controlledListing(phone: string): { name: string; id: number } {
    const out = execSync(
        `php artisan tinker --execute="
            \\$row = DB::selectOne(\\"select e.id from enterprises e join structures s on s.id=e.structure_id left join party_businesses pb on pb.enterprise_id=e.id and pb.status='active' join lateral (select phone from enterprise_observations where enterprise_id=e.id order by observed_at desc limit 1) o on true where o.phone is null and s.status <> 'rejected' and pb.id is null order by e.id limit 1\\");
            \\$shop = App\\\\Domain\\\\Registry\\\\Models\\\\Enterprise::query()->findOrFail(\\$row->id);
            \\$party = app(App\\\\Domain\\\\Party\\\\Actions\\\\RegisterParty::class)(
                verifiedPhone: '${phone}', personName: 'Correction Tester',
                displayName: 'Correction Tester', kind: App\\\\Domain\\\\Party\\\\Enums\\\\PartyKind::Individual);
            App\\\\Domain\\\\Claim\\\\Models\\\\PartyBusiness::query()->create([
                'party_id' => \\$party->id, 'enterprise_id' => \\$shop->id,
                'relationship' => 'owner', 'established_via' => 'claim',
                'established_at' => now(), 'status' => 'active']);
            echo json_encode(['name' => \\$shop->trading_name, 'id' => \\$shop->id]);
        "`,
    ).toString().trim().split('\n').at(-1) ?? '{}';

    return JSON.parse(out) as { name: string; id: number };
}

async function signInAsParty(page: Page, phone: string): Promise<void> {
    await page.goto(`${BASE}/portal/sign-in`);
    await page.getByLabel('Phone number').fill(phone);
    await page.getByRole('button', { name: 'Send me a code' }).click();

    await expect(page).toHaveURL(/\/portal\/verify/);
    await page.getByLabel('Code').fill(lastLoggedCode(/Portal sign-in code for \+\d+: (\d{6})/g));
    await page.getByRole('button', { name: /confirm|continue|sign in/i }).click();
    await expect(page).toHaveURL(/\/portal$/);
}

test('a business corrects its own name and an officer\'s record survives it', async ({ page }) => {
    const phone = '0807' + String(Date.now()).slice(-7);
    const shop = controlledListing(phone);
    const corrected = `${shop.name} Ltd`;

    await page.setViewportSize({ width: 390, height: 840 });
    await signInAsParty(page, phone);

    await page.goto(`${BASE}/portal/businesses/${String(shop.id)}`);
    await expect(page.getByRole('heading', { name: shop.name })).toBeVisible();

    // The ladder now says when, not just what.
    await expect(page.getByText(/\d{4}/).first()).toBeVisible();
    await page.screenshot({
        path: 'tests/Browser/screenshots/correction-1-listing.png',
        fullPage: true,
    });

    await page.getByRole('button', { name: 'Propose a correction' }).click();
    await page.getByLabel('What is wrong').selectOption('trading_name');
    await page.getByLabel('What it should say').fill(corrected);
    await page
        .getByLabel('Why')
        .fill('We registered the company last year and the signage was changed with it.');
    await page.getByRole('button', { name: 'Send for review' }).click();

    await expect(page.getByText(/sent for review/i)).toBeVisible();
    await expect(page.getByText('In review')).toBeVisible();
    await page.screenshot({
        path: 'tests/Browser/screenshots/correction-2-proposed.png',
        fullPage: true,
    });

    // Nothing has moved yet. That wait is the product.
    await expect(page.getByRole('heading', { name: shop.name })).toBeVisible();

    // The supervisor's side.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${BASE}/login`);
    await page.getByLabel('Email').fill('supervisor@geoverify.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/console'));

    await page.goto(`${BASE}/console/corrections`);
    const card = page.locator('li').filter({ hasText: corrected }).first();
    await expect(card).toBeVisible();

    // Both values, side by side and the same size.
    await expect(card.getByText(shop.name, { exact: false }).first()).toBeVisible();
    await page.screenshot({
        path: 'tests/Browser/screenshots/correction-3-queue.png',
        fullPage: true,
    });

    // Exact, because Playwright matches an accessible name by substring and
    // "Do not accept" contains "Accept".
    await card.getByRole('button', { name: 'Accept', exact: true }).click();
    await card
        .getByRole('textbox')
        .fill('CAC certificate attached to the account matches the proposed name.');
    await card.getByRole('button', { name: 'Confirm' }).click();

    await expect(page.getByText(/correction decided/i)).toBeVisible();

    // The register moved, and the officer's observation is still there beside
    // the party's. Read from the database, because the point is what was kept
    // rather than what a screen chose to show.
    const observations = execSync(
        `php artisan tinker --execute="echo json_encode(DB::select('select trading_name, captured_by, recorded_by_party_id from enterprise_observations where enterprise_id = ? order by observed_at', [${String(shop.id)}]));"`,
    ).toString().trim().split('\n').at(-1) ?? '[]';

    const rows = JSON.parse(observations) as {
        trading_name: string;
        captured_by: number | null;
        recorded_by_party_id: number | null;
    }[];

    expect(rows.length).toBeGreaterThanOrEqual(2);
    expect(rows[0].trading_name).toBe(shop.name);
    expect(rows[0].captured_by).not.toBeNull();
    expect(rows.at(-1)?.trading_name).toBe(corrected);
    expect(rows.at(-1)?.captured_by).toBeNull();
    expect(rows.at(-1)?.recorded_by_party_id).not.toBeNull();
});
