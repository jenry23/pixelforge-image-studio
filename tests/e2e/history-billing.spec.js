import { expect, test } from '@playwright/test'
import { signIn } from './support.js'

test.describe('history pagination', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, 'user@example.com')
        await page.goto('/history')
    })

    test('shows the row range and pages through generations', async ({ page }) => {
        const bar = page.locator('nav[aria-label*="pagination"]')
        await expect(bar.locator('p')).toContainText(/Showing 1–\d+ of \d+ generations/)

        await bar.getByRole('button', { name: '2', exact: true }).click()
        await expect(page).toHaveURL(/page=2/)
        await expect(bar.locator('p')).toContainText(/Showing \d+–\d+ of \d+ generations/)

        await bar.getByRole('button', { name: 'Previous' }).click()
        await expect(bar.locator('p')).toContainText(/Showing 1–/)
    })

    test('disables Previous on the first page', async ({ page }) => {
        const bar = page.locator('nav[aria-label*="pagination"]')
        await expect(bar.getByRole('button', { name: 'Previous' })).toBeDisabled()
    })
})

test.describe('the generation preview', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, 'user@example.com')
        await page.goto('/history')
    })

    test('opens from the keyboard and shows the full detail', async ({ page }) => {
        const thumb = page.locator('tbody button[aria-label^="View details"]').first()
        await thumb.focus()
        await page.keyboard.press('Enter')

        const dialog = page.locator('dialog')
        await expect(dialog).toBeVisible()
        await expect(dialog.getByRole('heading', { name: 'Generation details' })).toBeVisible()
        await expect(dialog.locator('img')).toBeVisible()

        // Resolution, quality and token usage all surface in the modal.
        await expect(dialog.getByText('Resolution')).toBeVisible()
        await expect(dialog.getByText('Quality')).toBeVisible()
        await expect(dialog.getByText('Tokens')).toBeVisible()
    })

    test('traps focus, closes on Escape and returns focus to the thumbnail', async ({ page }) => {
        const thumb = page.locator('tbody button[aria-label^="View details"]').first()
        const label = await thumb.getAttribute('aria-label')

        await thumb.focus()
        await page.keyboard.press('Enter')

        // Native <dialog> modality keeps focus inside the dialog.
        expect(await page.evaluate(() =>
            document.querySelector('dialog')?.contains(document.activeElement))).toBe(true)

        await page.keyboard.press('Escape')
        await expect(page.locator('dialog')).toBeHidden()

        expect(await page.evaluate(() =>
            document.activeElement?.getAttribute('aria-label'))).toBe(label)
    })

    test('does not make a button of a generation with no image', async ({ page }) => {
        // Failed generations keep their reason and "No charge" but are not clickable.
        const failedRow = page.locator('tbody tr').filter({ hasText: 'Failed' }).first()

        if (await failedRow.count()) {
            await expect(failedRow.locator('button[aria-label^="View details"]')).toHaveCount(0)
            await expect(failedRow).toContainText('No charge')
            await expect(failedRow).toContainText(/could not produce|not charged/i)
        }
    })
})

test.describe('billing', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, 'admin@example.com')
        await page.goto('/admin/report')
    })

    test('summarises the period and states the reconciliation', async ({ page }) => {
        await expect(page.getByText('Charged to users')).toBeVisible()
        await expect(page.getByText('Paid to Pixelforge')).toBeVisible()
        await expect(page.getByText('Profit', { exact: true }).first()).toBeVisible()
        await expect(page.getByText(/match Pixelforge to the nanodollar|disagree with Pixelforge|unverified/))
            .toBeVisible()
    })

    test('pages the daily table without changing the period totals', async ({ page }) => {
        const bar = page.locator('nav[aria-label*="days pagination"]')
        const charged = page.locator('.figure').nth(1)

        const before = await charged.textContent()
        const banner = await page
            .getByText(/match Pixelforge to the nanodollar|disagree with Pixelforge|unverified/)
            .textContent()

        await bar.getByRole('button', { name: '2', exact: true }).click()
        await expect(page).toHaveURL(/days_page=2/)
        await expect(bar.locator('p')).toContainText(/Showing 7–/)

        // The cards and the reconciliation describe the period, not the page.
        expect(await charged.textContent()).toBe(before)
        expect(
            await page
                .getByText(/match Pixelforge to the nanodollar|disagree with Pixelforge|unverified/)
                .textContent(),
        ).toBe(banner)
    })

    test('keeps the UTC window across pages', async ({ page }) => {
        const period = await page.locator('p.figure').first().textContent()

        await page
            .locator('nav[aria-label*="days pagination"]')
            .getByRole('button', { name: 'Next' })
            .click()
        await expect(page).toHaveURL(/days_page=2/)

        expect(await page.locator('p.figure').first().textContent()).toBe(period)
    })
})
