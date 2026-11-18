import { expect, test } from '@playwright/test'
import { signIn } from './support.js'

test.describe('signed-out access', () => {
    test('a fresh visitor cannot open Generate or History', async ({ page }) => {
        for (const path of ['/generate', '/history', '/admin/report']) {
            await page.goto(path, { waitUntil: 'networkidle' })
            await expect(page).toHaveURL(/\/login/)
        }
    })
})

test.describe('after signing out', () => {
    test('Back does not restore the previous session page', async ({ page }) => {
        await signIn(page, 'user@example.com')

        const balance = (await page.locator('.figure').first().textContent()).trim()
        await page.goto('/history', { waitUntil: 'networkidle' })

        await page.getByRole('button', { name: 'Sign out' }).click()
        await expect(page).toHaveURL(/\/login/)

        // Inertia keeps its own history state, so this is the navigation that
        // used to re-render the signed-in page straight from memory.
        await page.goBack({ waitUntil: 'load' }).catch(() => {})
        await page.waitForTimeout(1200)

        await expect(page).toHaveURL(/\/login/)

        const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ')
        expect(body).not.toContain(balance)
        expect(body).not.toContain('user@example.com')
    })

    test('refreshing after Back still lands on login', async ({ page }) => {
        await signIn(page, 'user@example.com')
        await page.getByRole('button', { name: 'Sign out' }).click()
        await expect(page).toHaveURL(/\/login/)

        await page.goBack({ waitUntil: 'load' }).catch(() => {})
        await page.reload({ waitUntil: 'networkidle' })

        await expect(page).toHaveURL(/\/login/)
    })

    test('authenticated pages are served no-store', async ({ page }) => {
        await signIn(page, 'user@example.com')

        const response = await page.goto('/history', { waitUntil: 'networkidle' })

        expect(response.headers()['cache-control']).toContain('no-store')
    })
})
