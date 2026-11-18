# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: generate.spec.js >> the generate page >> caps reference images at the four Pixelforge accepts
- Location: tests/e2e/generate.spec.js:83:5

# Error details

```
Test timeout of 60000ms exceeded while running "beforeEach" hook.
```

```
Error: locator.fill: Test timeout of 60000ms exceeded.
Call log:
  - waiting for locator('#email')

```

# Page snapshot

```yaml
- generic [ref=e3]:
  - generic [ref=e4]:
    - link "Image Studio" [ref=e5] [cursor=pointer]:
      - /url: /
    - generic [ref=e8]:
      - heading "Sign in to Image Studio" [level=1] [ref=e9]
      - paragraph [ref=e10]: Generate images and track what each one costs.
      - generic [ref=e11]:
        - generic [ref=e12]:
          - generic [ref=e13]:
            - generic [ref=e14]: Email
            - textbox "Email" [ref=e16]:
              - /placeholder: you@example.com
          - generic [ref=e17]:
            - generic [ref=e18]: Password
            - generic [ref=e19]:
              - textbox "Password" [ref=e20]:
                - /placeholder: Enter your password
              - button "Show password" [ref=e21]
        - generic [ref=e26] [cursor=pointer]:
          - checkbox "Remember me" [ref=e27]
          - text: Remember me
        - button "Sign in" [ref=e28]
        - paragraph [ref=e29]:
          - text: New to Image Studio?
          - link "Create an account" [ref=e30] [cursor=pointer]:
            - /url: http://localhost:3000/register
  - figure "Studio sample" [ref=e32]
```

# Test source

```ts
  1  | import { expect } from '@playwright/test'
  2  | 
  3  | /** Sign in through the real form, as a user would. */
  4  | export async function signIn(page, email = 'user@example.com', password = 'password') {
  5  |     await page.goto('/login')
  6  |     // Target the inputs by id: the password field's show/hide toggle also
  7  |     // carries "Password" in its aria-label, so getByLabel is ambiguous.
> 8  |     await page.locator('#email').fill(email)
     |                                  ^ Error: locator.fill: Test timeout of 60000ms exceeded.
  9  |     await page.locator('#password').fill(password)
  10 |     await page.getByRole('button', { name: 'Sign in' }).click()
  11 |     await expect(page).toHaveURL(/\/generate/)
  12 | }
  13 | 
  14 | /**
  15 |  * Fill the generate form and submit it.
  16 |  *
  17 |  * Waits for the debounced quote to land first, so the cost shown is the cost
  18 |  * for this prompt rather than the previous one -- a test that submitted before
  19 |  * the quote settled would be asserting against a stale figure.
  20 |  */
  21 | export async function submitGeneration(page, { prompt, quality = 'Low', size = 'Square' }) {
  22 |     await page.getByLabel('What should Pixelforge draw?').fill(prompt)
  23 |     await chooseOption(page, 'Size', size)
  24 |     await chooseOption(page, 'Quality', quality)
  25 | 
  26 |     const quote = page.waitForResponse(
  27 |         (response) => response.url().includes('/generate/estimate') && response.ok(),
  28 |     )
  29 |     await quote
  30 | 
  31 |     await page.getByRole('button', { name: 'Generate image' }).click()
  32 |     await expect(page).toHaveURL(/\/history/)
  33 | }
  34 | 
  35 | /**
  36 |  * Pick one of the segmented options.
  37 |  *
  38 |  * The radio input is visually hidden inside its label, so the element a user
  39 |  * actually clicks is the label text -- which is what this targets.
  40 |  */
  41 | export async function chooseOption(page, group, option) {
  42 |     await page.getByRole('group', { name: group }).getByText(option, { exact: true }).click()
  43 | }
  44 | 
  45 | /** The first generation row on the history page. */
  46 | export function firstRow(page) {
  47 |     return page.locator('article').first()
  48 | }
  49 | 
  50 | /** Read a money figure out of the page as an exact number of nanodollars. */
  51 | export async function nanodollarsOf(locator) {
  52 |     const text = await locator.textContent()
  53 |     const match = text.replace(/[^0-9.]/g, '')
  54 | 
  55 |     return Math.round(Number(match) * 1_000_000_000)
  56 | }
  57 | 
```