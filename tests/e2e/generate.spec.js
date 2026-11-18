import { expect, test } from '@playwright/test'
import { chooseOption, signIn } from './support.js'

test.describe('the generate page', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page)
    })

    test('shows what a generation will cost before it is submitted', async ({ page }) => {
        const readout = page.getByRole('complementary')

        await expect(readout.getByText('Estimated cost')).toBeVisible()
        await expect(readout.getByText('Expected charge')).toBeVisible()

        // A range, not a single number: reasoning tokens are only counted at
        // completion, so a single figure would be a guess presented as a fact.
        await expect(readout.getByText(/We reserve the upper estimate and settle the actual cost/)).toBeVisible()
    })

    test('reprices when the quality changes', async ({ page }) => {
        const expected = page.locator('aside .figure').first()

        const low = page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
        await chooseOption(page, 'Quality', 'Low')
        await low
        const lowPrice = await expected.textContent()

        const high = page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
        await chooseOption(page, 'Quality', 'High')
        await high
        const highPrice = await expected.textContent()

        expect(highPrice).not.toBe(lowPrice)
        expect(Number(highPrice.replace(/[^0-9.]/g, ''))).toBeGreaterThan(
            Number(lowPrice.replace(/[^0-9.]/g, '')),
        )
    })

    test('reprices as the prompt grows, because text tokens are billed', async ({ page }) => {
        const expected = page.locator('aside .figure').first()
        const before = await expected.textContent()

        const repriced = page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
        await page
            .getByLabel('Prompt')
            .fill('a '.repeat(500).trim())
        await repriced

        expect(await expected.textContent()).not.toBe(before)
    })

    test('separates the exact parts of the cost from the uncertain ones', async ({ page }) => {
        const readout = page.getByRole('complementary')

        // Output tokens are fixed by the request, so they show a single figure.
        await expect(readout.getByRole('row').filter({ hasText: 'Output' })).toContainText('1,100')
        // Reasoning is only counted at completion, so it shows a range.
        await expect(readout.getByRole('row').filter({ hasText: 'Reasoning' })).toContainText('–')
    })

    test('states the markup openly', async ({ page }) => {
        await expect(page.getByText('Markup')).toBeVisible()
        await expect(page.getByText('25%')).toBeVisible()
    })

    test('promises that failures are free', async ({ page }) => {
        await expect(page.getByText('Failed generations are free.')).toBeVisible()
    })

    test('will not submit an empty prompt', async ({ page }) => {
        await expect(page.getByRole('button', { name: 'Generate image' })).toBeDisabled()
    })

    test('counts reference images and their token cost', async ({ page }) => {
        await expect(page.getByText('0 of 4')).toBeVisible()

        await page.getByRole('button', { name: 'Add a reference image' }).click()
        await page.getByPlaceholder('https://example.com/style.png').fill('https://example.com/a.png')

        await expect(page.getByText('1 of 4')).toBeVisible()
    })

    test('caps reference images at the four Pixelforge accepts', async ({ page }) => {
        for (let i = 0; i < 4; i++) {
            await page.getByRole('button', { name: 'Add a reference image' }).click()
        }

        await expect(page.getByRole('button', { name: 'Add a reference image' })).toBeHidden()
    })
})

test.describe('credits', () => {
    test('blocks a generation the user cannot afford, and says why', async ({ page }) => {
        // The spent-out account has no credit left.
        await signIn(page, 'broke@example.com')

        await page.getByLabel('Prompt').fill('an expensive picture')
        await chooseOption(page, 'Quality', 'High')
        await page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())

        await expect(
            page.getByText(/You need a little more credit to cover this one/),
        ).toBeVisible()
        await expect(page.getByRole('button', { name: 'Generate image' })).toBeDisabled()
    })

    test('buying credits raises the available balance', async ({ page }) => {
        // Registers its own account rather than spending the shared fixture's
        // balance, so this test cannot change what another test sees.
        const email = `buyer-${Date.now()}@example.com`

        await page.goto('/register')
        await page.locator('#name').fill('Credit Buyer')
        await page.locator('#email').fill(email)
        await page.locator('#password').fill('password')
        await page.locator('#password_confirmation').fill('password')
        await page.getByRole('button', { name: 'Create account' }).click()
        await expect(page).toHaveURL(/\/generate/)

        // A new account starts with the welcome credit.
        await expect(page.getByText('$5.0000')).toBeVisible()

        await page.getByRole('button', { name: '$20.00' }).click()
        await expect(page.getByText('Added $20.00 of credits.')).toBeVisible()
        await expect(page.getByText('$25.0000')).toBeVisible()
    })
})

test.describe('the admin report', () => {
    test('is hidden from an ordinary user', async ({ page }) => {
        await signIn(page)

        await expect(page.getByRole('link', { name: 'Billing' })).toBeHidden()

        const response = await page.goto('/admin/report')
        expect(response.status()).toBe(404)
    })

    test('shows spend, provider cost and profit to an operator', async ({ page }) => {
        await signIn(page, 'admin@example.com')
        await page.getByRole('link', { name: 'Billing' }).click()

        await expect(page.getByText('Charged to users')).toBeVisible()
        await expect(page.getByText('Paid to Pixelforge')).toBeVisible()
        await expect(page.getByText('Profit', { exact: true }).first()).toBeVisible()
        await expect(page.getByRole('columnheader', { name: 'Pixelforge says' })).toBeVisible()
        await expect(page.getByRole('columnheader', { name: 'Delta' })).toBeVisible()
    })
})
