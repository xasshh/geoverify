import { test, expect, type Page } from "@playwright/test";
import { execSync } from "node:child_process";

/**
 * Buying a verification, on the connection the audience actually has.
 *
 * The other half of the M8 gate. Registration over throttled 3G is proved by
 * self-registration.spec.ts; this is the flow that follows it, from a listing
 * somebody controls to an order waiting to be paid.
 *
 * It stops at the payment page on purpose. Everything past that button belongs
 * to the provider, and the one thing this system does with the answer is
 * refuse to believe anything a browser tells it: money moves on a signed
 * webhook, which no browser test can or should be able to produce.
 */

function lastLoggedCode(pattern: RegExp): string {
    const log = execSync("tail -400 storage/logs/laravel.log").toString();
    const last = [...log.matchAll(pattern)].at(-1);

    if (last === undefined) {
        throw new Error(`No code matching ${String(pattern)} in the log.`);
    }

    return last[1];
}

/** One row of SQL, read the way the other specs read the register. */
function queryOne<T>(sql: string): T {
    const out = execSync(
        `php artisan tinker --execute="echo json_encode(DB::selectOne(\\"${sql}\\"));"`,
    )
        .toString()
        .trim()
        .split("\n")
        .at(-1);

    return JSON.parse(out ?? "{}") as T;
}

async function registerParty(page: Page, phone: string, name: string) {
    await page.goto("/portal/sign-in?mode=code");
    await page.getByLabel("Phone number").fill(phone);
    await page.getByRole("button", { name: "Send me a code" }).click();

    await expect(page).toHaveURL(/\/portal\/verify/);
    await page.getByLabel("Code").fill(lastLoggedCode(/SMS to \+\d+: GeoVerify: (\d{6}) is your sign-in code/g));
    await page.getByRole("button", { name: /confirm|continue|sign in/i }).click();

    await expect(page).toHaveURL(/\/portal\/register/);
    await page.getByLabel("Your name").fill(name);
    await page.getByLabel("Business name").fill(name + " Enterprises");
    await page.getByRole("button", { name: "Create my account" }).click();
    await expect(page).toHaveURL(/\/portal$/);
}

/**
 * An unclaimed listing with a phone on its latest observation, and no claim on
 * it at all.
 *
 * Drawn from the same pool as the claim spec, from the opposite end, and
 * consumed the same way: this test claims one and does not give it back. The
 * "no claim at all" part matters for a run that failed halfway: a listing
 * carrying somebody's abandoned pending claim would make the next run open a
 * dispute instead of taking control, and fail somewhere that looks nothing
 * like the cause. An empty pool fails here in the JSON parse, which is
 * exhaustion rather than a regression. Reseed to refill.
 */
const TARGET = queryOne<{ name: string; ward: string }>(
    "select e.trading_name name, w.name ward from enterprises e join structures s on s.id=e.structure_id left join admin_boundaries w on w.id=s.ward_id left join party_businesses pb on pb.enterprise_id=e.id and pb.status='active' join lateral (select phone from enterprise_observations where enterprise_id=e.id order by observed_at desc limit 1) o on true where o.phone is not null and s.status <> 'rejected' and pb.id is null and not exists (select 1 from claims c where c.enterprise_id = e.id) order by e.id asc limit 1",
);

test("a business buys a verification of itself, over a throttled 3G link", async ({
    page,
    context,
}) => {
    await page.setViewportSize({ width: 360, height: 780 });

    const phone = "0806" + String(Date.now()).slice(-7);
    await registerParty(page, phone, "Bunmi Adeyemi");

    // Claiming is proved in claim-flow.spec.ts and is not what is being timed
    // here, so it runs at full speed. The throttle goes on afterwards.
    await page.goto("/portal/claim?q=" + encodeURIComponent(TARGET.name));

    const row = page
        .locator("li")
        .filter({ has: page.getByTestId("phone-available") })
        .first();

    await row.getByRole("button", { name: "This is mine" }).click();
    await page.getByRole("button", { name: /claim this business/i }).click();

    await expect(page).toHaveURL(/\/portal\/claim\/\d+/);
    await page.getByRole("button", { name: /send the code/i }).click();

    // Waited for before the log is read. Without it the read races the POST
    // that writes the code, picks up whatever claim code was last issued on
    // this machine, and fails five screens later saying the code is wrong.
    await expect(page.getByText(/we sent a code/i)).toBeVisible();

    await page.getByLabel(/six digit code/i).fill(
        lastLoggedCode(/SMS to \+\d+: GeoVerify: (\d{6}) is the code to confirm/g),
    );
    await page.getByRole("button", { name: "Confirm" }).click();
    await expect(page.getByText(/this business is yours/i)).toBeVisible();

    await page.getByRole("link", { name: /open the listing/i }).click();
    await expect(page).toHaveURL(/\/portal\/businesses\/\d+/);

    const seen = new Map<string, number>();

    page.on("response", (response) => {
        const length = response.headers()["content-length"];

        if (length !== undefined && !seen.has(response.url())) {
            seen.set(response.url(), Number(length));
        }
    });

    // Regular 3G: 1.6 Mbps down, 768 Kbps up, 300 ms round trip. The same
    // profile the registration spec uses, so the two numbers are comparable.
    const cdp = await context.newCDPSession(page);
    await cdp.send("Network.enable");
    await cdp.send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 300,
        downloadThroughput: (1_600 * 1024) / 8,
        uploadThroughput: (768 * 1024) / 8,
    });

    const started = Date.now();

    // The offer sits under the ladder, priced, because the ladder is the
    // argument for buying: what is not established yet, and what establishing
    // it costs.
    await page.getByRole("button", { name: /see what it involves/i }).click();
    await expect(page).toHaveURL(/\/portal\/businesses\/\d+\/verify\//);

    await page.screenshot({
        path: "tests/Browser/screenshots/order-1-offer.png",
        fullPage: true,
    });

    // The fee, the promise and the zone are on the same screen as the button.
    await expect(page.getByText(/₦/).first()).toBeVisible();
    await expect(page.getByText(/working days/i).first()).toBeVisible();

    await page.getByRole("button", { name: /continue to payment/i }).click();

    await expect(page).toHaveURL(/\/portal\/orders\/\d+/);

    const seconds = (Date.now() - started) / 1000;

    // Awaiting payment, with the reference the customer will quote on the
    // phone, and nothing claiming the money has arrived.
    await expect(page.getByText(/GV-/).first()).toBeVisible();
    await expect(page.getByRole("button", { name: /^Pay ₦/ })).toBeVisible();

    // No certificate, because nobody has been yet. The button appears when a
    // supervisor accepts the visit and not one moment earlier.
    await expect(page.getByRole("link", { name: /download the certificate/i })).toHaveCount(0);

    await page.screenshot({
        path: "tests/Browser/screenshots/order-2-awaiting-payment.png",
        fullPage: true,
    });

    const bytes = [...seen.values()].reduce((a, b) => a + b, 0);

    console.log(
        `3G ORDER: ${seconds.toFixed(1)} s, ${(bytes / 1024).toFixed(0)} KiB over ${String(seen.size)} responses`,
    );

    for (const [url, size] of [...seen.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8)) {
        console.log(
            `   ${(size / 1024).toFixed(1).padStart(7)} KiB  ${url.replace(/^https?:\/\/[^/]+/, "")}`,
        );
    }

    // The gate is one sitting, not a stopwatch record. Thirty seconds from
    // listing to placed order on 3G is the promise; the printed number above is
    // what makes a regression visible before somebody in a market finds it.
    expect(seconds).toBeLessThan(30);
});
