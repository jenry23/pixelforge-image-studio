import { defineConfig, devices } from '@playwright/test'

/**
 * Browser tests run against a real server with a real database.
 *
 * Two suites, deliberately separated:
 *
 *   - `ui`       exercises the interface against seeded data and a faked
 *                provider, so it is fast and deterministic and can run in CI.
 *   - `live`     drives a genuine generation through Pixelforge end to end, and
 *                only runs when PIXELFORGE_LIVE=1 and a tunnel is receiving
 *                webhooks. It is the proof the whole loop works, not a test we
 *                would gate a merge on.
 */
export default defineConfig({
    testDir: './tests/e2e',
    // Restores the seeded fixture accounts so a previous run cannot change
    // what this one sees.
    globalSetup: './tests/e2e/global-setup.js',
    // Money assertions must not race a poll interval.
    timeout: 60_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: process.env.CI ? 'line' : [['list'], ['html', { open: 'never' }]],

    use: {
        baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'ui',
            testIgnore: /live\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'live',
            testMatch: /live\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
    ],
})
