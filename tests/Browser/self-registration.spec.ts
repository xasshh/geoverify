import { test, expect, type Page } from "@playwright/test";
import { execSync } from "node:child_process";

/**
 * Adding a business that is not in the register, on the connection the audience
 * actually has.
 *
 * The throttle is the point of this test rather than a flourish. The plan's
 * gate is that the whole thing completes in one sitting on 3G, and a form that
 * is pleasant on a desk and unusable on a handset in a market has not been
 * built for the people it is for.
 */

function lastLoggedCode(pattern: RegExp): string {
    const log = execSync("tail -400 storage/logs/laravel.log").toString();
    const last = [...log.matchAll(pattern)].at(-1);

    if (last === undefined) {
        throw new Error("No code in the log.");
    }

    return last[1];
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

test("a business not in the register adds itself, over a throttled 3G link", async ({
    page,
    context,
}) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await context.grantPermissions(["geolocation"]);
    await context.setGeolocation({ latitude: 9.05, longitude: 7.46, accuracy: 14 });

    const phone = "0809" + String(Date.now()).slice(-7);
    await registerParty(page, phone, "Ifeoma Balogun");

    // Everything from here is measured: this is the flow the gate is about.
    const seen = new Map<string, number>();

    page.on("response", (response) => {
        const length = response.headers()["content-length"];

        if (length !== undefined && !seen.has(response.url())) {
            seen.set(response.url(), Number(length));
        }
    });

    // Regular 3G: 1.6 Mbps down, 768 Kbps up, 300 ms round trip.
    const cdp = await context.newCDPSession(page);
    await cdp.send("Network.enable");
    await cdp.send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 300,
        downloadThroughput: (1_600 * 1024) / 8,
        uploadThroughput: (768 * 1024) / 8,
    });

    const started = Date.now();

    await page.goto("/portal/register-business");

    // Step one: what it is called.
    await page.getByLabel(/name on your signage/i).fill("Ifeoma Fabrics");
    await page.getByRole("radio", { name: /shop in a building/i }).check();
    await page.getByRole("button", { name: "Next" }).click();

    // Step two: where it is. The device answers, then the person corrects it.
    await expect(page.getByRole("heading", { name: "Where is it?" })).toBeVisible();
    await page.getByRole("button", { name: /use where i am now/i }).click();

    await expect(page.getByText(/we placed you in/i)).toBeVisible();
    await page.screenshot({
        path: "tests/Browser/screenshots/register-1-place.png",
        fullPage: true,
    });

    // The ward came from the server, not from anything typed.
    await expect(page.getByText(/worked out from your position/i)).toBeVisible();

    await page.getByRole("button", { name: /m away|building you are in/i }).first().click();
    await page.getByRole("button", { name: /this is my building/i }).click();

    // Step three: check and finish.
    await expect(page.getByRole("heading", { name: "Check and finish" })).toBeVisible();
    await page.getByLabel(/phone number for the business/i).fill("08031234567");
    await page.screenshot({
        path: "tests/Browser/screenshots/register-2-confirm.png",
        fullPage: true,
    });

    await page.getByRole("button", { name: /add my business/i }).click();

    await expect(page).toHaveURL(/\/portal\/businesses\/\d+/);
    await expect(page.getByRole("heading", { name: "Ifeoma Fabrics" })).toBeVisible();

    const seconds = (Date.now() - started) / 1000;

    // Listed, and nothing above it. A business that typed its own address has
    // not had a visit, and the page must not suggest otherwise.
    await page.screenshot({
        path: "tests/Browser/screenshots/register-3-listing.png",
        fullPage: true,
    });

    const ladder = page.getByLabel("Verification tiers");
    await expect(ladder.getByText("Listed").first()).toBeVisible();

    // The rung a visit establishes is explicitly not established, and says so
    // in words rather than by being absent.
    const locationRung = ladder.locator("li").filter({ hasText: "Location" });
    await expect(locationRung.getByText("not established").first()).toBeVisible();

    await page.screenshot({
        path: "tests/Browser/screenshots/register-3-listing.png",
        fullPage: true,
    });

    const bytes = [...seen.values()].reduce((a, b) => a + b, 0);

    console.log(
        `3G REGISTRATION: ${seconds.toFixed(1)} s, ${(bytes / 1024).toFixed(0)} KiB over ${String(seen.size)} responses`,
    );

    for (const [url, size] of [...seen.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8)) {
        console.log(`   ${(size / 1024).toFixed(1).padStart(7)} KiB  ${url.replace(/^https?:\/\/[^/]+/, "")}`);
    }
});
