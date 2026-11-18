import { expect } from '@playwright/test'

/** Sign in through the real form, as a user would. */
export async function signIn(page, email = 'user@example.com', password = 'password') {
    await page.goto('/login')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill(password)
    await page.getByRole('button', { name: 'Log in' }).click()
    await expect(page).toHaveURL(/\/generate/)
}

/**
 * Fill the generate form and submit it.
 *
 * Waits for the debounced quote to land first, so the cost shown is the cost
 * for this prompt rather than the previous one -- a test that submitted before
 * the quote settled would be asserting against a stale figure.
 */
export async function submitGeneration(page, { prompt, quality = 'Low', size = 'Square' }) {
    await page.getByLabel('What should Pixelforge draw?').fill(prompt)
    await chooseOption(page, 'Size', size)
    await chooseOption(page, 'Quality', quality)

    const quote = page.waitForResponse(
        (response) => response.url().includes('/generate/estimate') && response.ok(),
    )
    await quote

    await page.getByRole('button', { name: 'Generate image' }).click()
    await expect(page).toHaveURL(/\/history/)
}

/**
 * Pick one of the segmented options.
 *
 * The radio input is visually hidden inside its label, so the element a user
 * actually clicks is the label text -- which is what this targets.
 */
export async function chooseOption(page, group, option) {
    await page.getByRole('group', { name: group }).getByText(option, { exact: true }).click()
}

/** The first generation row on the history page. */
export function firstRow(page) {
    return page.locator('article').first()
}

/** Read a money figure out of the page as an exact number of nanodollars. */
export async function nanodollarsOf(locator) {
    const text = await locator.textContent()
    const match = text.replace(/[^0-9.]/g, '')

    return Math.round(Number(match) * 1_000_000_000)
}
