import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: process.env.GV_BASE_URL ?? 'http://127.0.0.1:8123',
        trace: 'on-first-retry',

        // The field client refuses to capture without a position, which is
        // correct: a capture with no fix is not evidence. Tests therefore need a
        // real one. This point is inside the Abuja Municipal test grid.
        geolocation: { latitude: 9.05, longitude: 7.46, accuracy: 4.2 },
        permissions: ['geolocation'],
    },

    // Offline capture involves waiting for a sync to come back on its own, which
    // is deliberately unhurried.
    timeout: 90_000,
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
