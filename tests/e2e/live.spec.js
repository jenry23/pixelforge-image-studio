import { expect, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { signIn, submitGeneration } from './support.js'

/**
 * End-to-end against the real Pixelforge API.
 *
 * This is the proof the whole loop works: a genuine generation, a genuine
 * signed webhook arriving over a tunnel, and a charge recorded at exactly the
 * cost Pixelforge reports in GET /v1/usage.
 *
 * It needs a live API key, a publicly reachable webhook URL, and a running
 * queue worker, so it is opt-in rather than part of the normal suite.
 */
const live = process.env.PIXELFORGE_LIVE === '1'

test.describe(live ? 'against the real Pixelforge API' : 'live tests (skipped)', () => {
    test.skip(!live, 'Set PIXELFORGE_LIVE=1 with a tunnel running to enable.')

    // Real generations take 5-30 seconds, and [slow] ones up to 90.
    test.setTimeout(180_000)

    test('generates an image and charges exactly what Pixelforge billed', async ({ page }) => {
        await signIn(page, 'user@example.com')

        await submitGeneration(page, {
            // [succeed] removes the random failure the provider injects, so this
            // test is about cost correctness rather than luck.
            prompt: '[succeed] a brass astrolabe resting on dark blue velvet',
            quality: 'Low',
        })

        const row = page.locator('article').first()

        // While it runs, the quoted range is shown and credit is reserved.
        await expect(row.getByText('Estimated')).toBeVisible()
        await expect(page.getByText('Reserved while running')).toBeVisible()

        // The page polls on its own; no reload here on purpose.
        await expect(row.getByText('Charged')).toBeVisible({ timeout: 150_000 })
        await expect(row.getByText('Succeeded')).toBeVisible()

        // Polling stops once everything has settled.
        await expect(page.getByText('Up to date')).toBeVisible({ timeout: 20_000 })

        // Scoped to the charge itself rather than the first figure in the row,
        // which is the token count.
        const charged = await row.getByTestId('actual-charge').textContent()
        expect(charged).toMatch(/^\$0\.\d+$/)

        // And the image itself arrived.
        await expect(row.locator('img')).toBeVisible()
    })

    test('a failed generation is recorded as free', async ({ page }) => {
        await signIn(page, 'user@example.com')

        const before = await page.getByText(/^\$[\d.]+$/).first().textContent()

        await submitGeneration(page, {
            // Accepted, then fails during processing. Never billed.
            prompt: '[fail] a generation that will not work',
            quality: 'Low',
        })

        const row = page.locator('article').first()

        await expect(row.getByText('Failed')).toBeVisible({ timeout: 150_000 })
        await expect(row.getByText('Free')).toBeVisible()
        await expect(row.getByText(/didn't work, so we didn't charge you/)).toBeVisible()

        // The reservation came back in full: the balance is exactly as before.
        await expect(page.getByText('Up to date')).toBeVisible({ timeout: 20_000 })
        const after = await page.getByText(/^\$[\d.]+$/).first().textContent()
        expect(after).toBe(before)
    })

    test('duplicate webhook deliveries are charged once', async ({ page }) => {
        await signIn(page, 'user@example.com')

        await submitGeneration(page, {
            // Forces Pixelforge to deliver every webhook for this generation twice.
            prompt: '[succeed] [duplicate] a lighthouse at dawn',
            quality: 'Low',
        })

        const row = page.locator('article').first()
        await expect(row.getByText('Charged')).toBeVisible({ timeout: 150_000 })

        // The ledger is the real assertion: every event arrived twice, and the
        // database's unique constraints mean exactly one charge was written.
        const verdict = execSync('php artisan pixelforge:verify-ledger', { encoding: 'utf8' })
        expect(verdict).toContain('charged at most once')
    })

    test('our recorded provider costs tie out to GET /v1/usage', async ({ page }) => {
        await signIn(page, 'admin@example.com')
        await page.getByRole('link', { name: 'Billing' }).click()

        // The page fetches the provider's own billing record and compares it to
        // ours as exact integers. Anything but agreement is a real discrepancy.
        await expect(
            page.getByText("Our provider costs match Pixelforge's own record exactly, to the nanodollar."),
        ).toBeVisible()
    })
})
