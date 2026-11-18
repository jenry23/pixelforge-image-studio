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

    test('shows a loader and blocks repeat clicks while a page is in flight', async ({ page }) => {
        // Hold the response so the in-flight state is observable at all.
        let hold = true
        await page.route('**/history?**', async (route) => {
            if (hold) await new Promise((r) => setTimeout(r, 2000))
            await route.continue()
        })

        let requests = 0
        page.on('request', (r) => {
            if (r.url().includes('/history?')) requests++
        })

        const bar = page.locator('nav[aria-label*="pagination"]')
        await bar.getByRole('button', { name: '2', exact: true }).click()

        await expect(bar.locator('p')).toHaveText(/Loading page 2…/)
        await expect(bar).toHaveAttribute('aria-busy', 'true')
        await expect(bar.getByRole('status')).toHaveText(/Loading page 2 of \d+/)
        await expect(page.locator('tbody[aria-hidden="true"]')).toBeVisible()

        // Every control is inert, so a second click cannot race the first.
        for (const button of await bar.getByRole('button').all()) {
            await expect(button).toBeDisabled()
        }

        requests = 0
        await bar.getByRole('button', { name: 'Next' }).click({ force: true }).catch(() => {})
        await page.waitForTimeout(250)
        expect(requests).toBe(0)

        hold = false
        await page.waitForURL(/page=2/)

        await expect(bar).toHaveAttribute('aria-busy', 'false')
        await expect(bar.locator('p')).toContainText(/Showing \d+–\d+ of \d+ generations/)
        await expect(page.locator('tbody[aria-hidden="true"]')).toHaveCount(0)
        await expect(bar.getByRole('button', { name: '2', exact: true })).toHaveAttribute(
            'aria-current',
            'page',
        )
    })

    test('keeps the table geometry and scroll position steady while loading', async ({ page }) => {
        const measure = () =>
            page.evaluate(() => {
                const table = document.querySelector('table')
                const panel = table.closest('div.overflow-hidden')
                return {
                    tableHeight: Math.round(table.getBoundingClientRect().height),
                    columns: [...table.querySelectorAll('thead th')].map((th) =>
                        Math.round(th.getBoundingClientRect().width),
                    ),
                    border: getComputedStyle(panel).borderTopColor,
                    scrollY: Math.round(window.scrollY),
                    maxScrollY: Math.max(
                        0,
                        document.documentElement.scrollHeight - window.innerHeight,
                    ),
                }
            })

        // Settle the viewport on the footer first, so clicking it cannot be what
        // moves the page.
        const next = page
            .locator('nav[aria-label*="pagination"]')
            .getByRole('button', { name: 'Next' })
        await next.scrollIntoViewIfNeeded()
        await page.waitForTimeout(150)
        const before = await measure()

        let hold = true
        await page.route('**/history?**', async (route) => {
            if (hold) await new Promise((r) => setTimeout(r, 1500))
            await route.continue()
        })

        await next.click()
        await expect(page.locator('tbody[aria-hidden="true"]')).toBeVisible()

        // The skeleton stands in for the rows without moving anything around it.
        const during = await measure()
        expect(during.tableHeight).toBe(before.tableHeight)
        expect(during.columns).toEqual(before.columns)
        expect(during.border).toBe(before.border)

        expect(during.scrollY).toBe(before.scrollY)

        hold = false
        await page.waitForURL(/page=2/)

        // The last page holds fewer rows, so the document is genuinely shorter
        // and the browser clamps; what matters is that nothing reset to the top.
        const after = await measure()
        expect(after.scrollY).toBe(Math.min(before.scrollY, after.maxScrollY))
    })

    test('restores the previous rows and offers a retry when the request fails', async ({ page }) => {
        const bar = page.locator('nav[aria-label*="pagination"]')
        const rowsBefore = await page.locator('tbody tr').allTextContents()

        let fail = true
        await page.route('**/history?**', async (route) => {
            if (fail) await route.abort('failed')
            else await route.continue()
        })

        await bar.getByRole('button', { name: 'Next' }).click()

        await expect(bar.locator('p')).toContainText("Couldn't load page 2.")
        await expect(bar).toHaveAttribute('aria-busy', 'false')
        await expect(page.locator('tbody[aria-hidden="true"]')).toHaveCount(0)
        expect(await page.locator('tbody tr').allTextContents()).toEqual(rowsBefore)

        fail = false
        await bar.getByRole('button', { name: 'Try again' }).click()

        await page.waitForURL(/page=2/)
        await expect(bar.locator('p')).toContainText(/Showing \d+–\d+ of \d+ generations/)
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

    test('loads the daily and By user tables independently', async ({ page }) => {
        let hold = true
        await page.route('**/admin/report?**', async (route) => {
            if (hold) await new Promise((r) => setTimeout(r, 1500))
            await route.continue()
        })

        const days = page.locator('nav[aria-label*="days pagination"]')
        await days.getByRole('button', { name: 'Next' }).click()

        await expect(days).toHaveAttribute('aria-busy', 'true')
        await expect(days.locator('p')).toHaveText(/Loading page 2…/)

        // Only the daily table is standing in; By user keeps its real rows.
        const skeletons = page.locator('tbody[aria-hidden="true"]')
        await expect(skeletons).toHaveCount(1)
        await expect(page.getByText('user@example.com')).toBeVisible()

        hold = false
        await page.waitForURL(/days_page=2/)
        await expect(skeletons).toHaveCount(0)
    })

    test('exports the period from a keyboard-accessible menu', async ({ page }) => {
        const trigger = page.getByRole('button', { name: 'Export' })
        await expect(trigger).toHaveAttribute('aria-expanded', 'false')

        await trigger.focus()
        await page.keyboard.press('ArrowDown')

        const menu = page.getByRole('menu')
        await expect(menu).toBeVisible()
        await expect(trigger).toHaveAttribute('aria-expanded', 'true')
        await expect(menu.getByRole('menuitem')).toHaveText([
            'Billing report (.xlsx)',
            'Daily breakdown (.csv)',
            'By user (.csv)',
        ])
        await expect(menu).toContainText('Full UTC reporting period')

        // Opening with the keyboard lands on the first item and End reaches the last.
        await expect(menu.getByRole('menuitem').first()).toBeFocused()
        await page.keyboard.press('End')
        await expect(menu.getByRole('menuitem').last()).toBeFocused()

        await page.keyboard.press('Escape')
        await expect(menu).toBeHidden()
        await expect(trigger).toBeFocused()
    })

    test('exports the whole period no matter which page is open', async ({ page }) => {
        const download = async () => {
            await page.getByRole('button', { name: 'Export' }).click()
            const [file] = await Promise.all([
                page.waitForEvent('download'),
                page.getByRole('menuitem', { name: 'Daily breakdown (.csv)' }).click(),
            ])
            const stream = await file.createReadStream()
            let body = ''
            for await (const chunk of stream) body += chunk
            return body
        }

        const fromPageOne = await download()

        await page
            .locator('nav[aria-label*="days pagination"]')
            .getByRole('button', { name: 'Next' })
            .click()
        await page.waitForURL(/days_page=2/)

        // The file describes the period, so the open page cannot change it.
        expect(await download()).toBe(fromPageOne)
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
