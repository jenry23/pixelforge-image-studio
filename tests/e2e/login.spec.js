import { expect, test } from '@playwright/test'

/**
 * The sign-in page.
 *
 * The artwork rules are easy to break silently, so they are asserted rather
 * than assumed: a later refactor that swaps `v-if` for a CSS `hidden` class
 * would still look correct on screen while quietly costing every phone user a
 * 40KB download.
 */
test.describe('the artwork panel', () => {
    test('is not downloaded on a phone', async ({ page }) => {
        const requested = []
        page.on('request', (r) => r.url().includes('login-hero') && requested.push(r.url()))

        await page.setViewportSize({ width: 390, height: 844 })
        await page.goto('/login', { waitUntil: 'networkidle' })

        // Not merely hidden -- absent, so the browser never fetches it.
        expect(requested).toHaveLength(0)
        await expect(page.locator('img[src*="login-hero"]')).toHaveCount(0)
    })

    test('is downloaded and covers its panel on desktop', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 })
        await page.goto('/login', { waitUntil: 'networkidle' })

        const image = page.locator('img[src*="login-hero"]')
        await expect(image).toBeVisible()
        await expect(image).toHaveCSS('object-fit', 'cover')
        await expect(image).toHaveAttribute('src', '/images/login-hero.webp')
    })

    test('serves the artwork as WebP', async ({ page }) => {
        const response = await page.request.get('/images/login-hero.webp')

        expect(response.status()).toBe(200)
        expect(response.headers()['content-type']).toContain('image/webp')
    })


    /**
     * The failure this guards against: the layout used to grow with its content,
     * so on any window shorter than ~990px the artwork ran off the bottom of the
     * screen and the caption was never visible. Only a tall viewport looked
     * right, which is precisely the viewport a test is most likely to use.
     */
    test('fits inside the window at real laptop heights', async ({ page }) => {
        for (const viewport of [
            { width: 1280, height: 560 },
            { width: 1440, height: 620 },
            { width: 1512, height: 700 },
            { width: 1440, height: 800 },
            { width: 1920, height: 1080 },
        ]) {
            await page.setViewportSize(viewport)
            await page.goto('/login', { waitUntil: 'networkidle' })

            const state = await page.evaluate(() => {
                const figure = document.querySelector('figure')
                const caption = document.querySelector('figcaption')
                const footer = [...document.querySelectorAll('p')]
                    .find((p) => p.textContent.includes('New to Image Studio'))

                const fig = figure.getBoundingClientRect()
                const cap = caption.getBoundingClientRect()
                const foot = footer.getBoundingClientRect()

                return {
                    pageScrolls: document.documentElement.scrollHeight > window.innerHeight + 1,
                    figureBottom: Math.round(fig.bottom),
                    viewportHeight: window.innerHeight,
                    captionVisible: cap.bottom <= window.innerHeight && cap.top >= 0,
                    footerVisible: foot.bottom <= window.innerHeight && foot.top >= 0,
                }
            })

            const where = `${viewport.width}x${viewport.height}`
            expect(state.pageScrolls, `page scrolls at ${where}`).toBe(false)
            // The panel keeps its 21px breathing room at the bottom.
            expect(state.figureBottom, `artwork overruns the window at ${where}`)
                .toBeLessThanOrEqual(state.viewportHeight - 10)
            expect(state.captionVisible, `caption below the fold at ${where}`).toBe(true)
            expect(state.footerVisible, `footer below the fold at ${where}`).toBe(true)
        }
    })

    test('renders the caption as text, not baked into the image', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 })
        await page.goto('/login')

        // Selectable, translatable and available to screen readers.
        await expect(page.locator('figcaption')).toHaveText('Studio sample')
    })
})

test.describe('the sign-in form', () => {
    test('reveals and re-hides the password', async ({ page }) => {
        await page.goto('/login')
        const password = page.locator('#password')
        await password.fill('a-secret')

        await expect(password).toHaveAttribute('type', 'password')
        await page.getByRole('button', { name: 'Show password' }).click()
        await expect(password).toHaveAttribute('type', 'text')
        await page.getByRole('button', { name: 'Hide password' }).click()
        await expect(password).toHaveAttribute('type', 'password')
    })

    test('signs a user in', async ({ page }) => {
        await page.goto('/login')
        await page.locator('#email').fill('user@example.com')
        await page.locator('#password').fill('password')
        await page.getByRole('button', { name: 'Sign in' }).click()

        await expect(page).toHaveURL(/\/generate/)
    })

    test('shows an error for bad credentials', async ({ page }) => {
        await page.goto('/login')
        await page.locator('#email').fill('user@example.com')
        await page.locator('#password').fill('not-the-password')
        await page.getByRole('button', { name: 'Sign in' }).click()

        await expect(page.getByText(/credentials do not match/i)).toBeVisible()
    })

    test('has no horizontal overflow at any width', async ({ page }) => {
        for (const width of [360, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 })
            await page.goto('/login')

            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            )
            expect(overflow, `horizontal overflow at ${width}px`).toBe(0)
        }
    })
})
