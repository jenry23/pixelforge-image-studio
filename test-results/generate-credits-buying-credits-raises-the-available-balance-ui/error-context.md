# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: generate.spec.js >> credits >> buying credits raises the available balance
- Location: tests/e2e/generate.spec.js:107:5

# Error details

```
Test timeout of 60000ms exceeded.
```

```
Error: locator.click: Test timeout of 60000ms exceeded.
Call log:
  - waiting for getByRole('button', { name: 'Register' })

```

# Page snapshot

```yaml
- generic [ref=e3]:
  - generic [ref=e4]:
    - link "Image Studio" [ref=e5] [cursor=pointer]:
      - /url: /
    - generic [ref=e8]:
      - heading "Create your account" [level=1] [ref=e9]
      - paragraph [ref=e10]: Start generating images and tracking costs.
      - generic [ref=e11]:
        - generic [ref=e12]:
          - generic [ref=e13]:
            - generic [ref=e14]: Name
            - textbox "Name" [ref=e16]:
              - /placeholder: Your name
              - text: Credit Buyer
          - generic [ref=e17]:
            - generic [ref=e18]: Email
            - textbox "Email" [ref=e20]:
              - /placeholder: you@example.com
              - text: buyer-1795023058047@example.com
          - generic [ref=e21]:
            - generic [ref=e22]: Password
            - generic [ref=e23]:
              - textbox "Password" [ref=e24]:
                - /placeholder: Create a password
                - text: password
              - button "Show password" [ref=e25]
          - generic [ref=e29]:
            - generic [ref=e30]: Confirm password
            - generic [ref=e31]:
              - textbox "Confirm password" [active] [ref=e32]:
                - /placeholder: Re-enter your password
                - text: password
              - button "Show password" [ref=e33]
        - button "Create account" [ref=e37]
        - paragraph [ref=e38]:
          - text: Already have an account?
          - link "Sign in" [ref=e39] [cursor=pointer]:
            - /url: http://localhost:3000/login
  - figure "Studio sample" [ref=e41]
```

# Test source

```ts
  17  |         await expect(readout.getByText(/The final charge lands somewhere in this range/)).toBeVisible()
  18  |     })
  19  | 
  20  |     test('reprices when the quality changes', async ({ page }) => {
  21  |         const expected = page.locator('aside .figure').first()
  22  | 
  23  |         const low = page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
  24  |         await chooseOption(page, 'Quality', 'Low')
  25  |         await low
  26  |         const lowPrice = await expected.textContent()
  27  | 
  28  |         const high = page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
  29  |         await chooseOption(page, 'Quality', 'High')
  30  |         await high
  31  |         const highPrice = await expected.textContent()
  32  | 
  33  |         expect(highPrice).not.toBe(lowPrice)
  34  |         expect(Number(highPrice.replace(/[^0-9.]/g, ''))).toBeGreaterThan(
  35  |             Number(lowPrice.replace(/[^0-9.]/g, '')),
  36  |         )
  37  |     })
  38  | 
  39  |     test('reprices as the prompt grows, because text tokens are billed', async ({ page }) => {
  40  |         const expected = page.locator('aside .figure').first()
  41  |         const before = await expected.textContent()
  42  | 
  43  |         const repriced = page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
  44  |         await page
  45  |             .getByLabel('What should Pixelforge draw?')
  46  |             .fill('a '.repeat(500).trim())
  47  |         await repriced
  48  | 
  49  |         expect(await expected.textContent()).not.toBe(before)
  50  |     })
  51  | 
  52  |     test('separates the exact parts of the cost from the uncertain ones', async ({ page }) => {
  53  |         const readout = page.getByRole('complementary')
  54  | 
  55  |         // Output and reference-image tokens are fixed by the request itself.
  56  |         await expect(readout.getByRole('row').filter({ hasText: 'Output' })).toContainText('exact')
  57  |         // Reasoning is only counted once the generation has run.
  58  |         await expect(readout.getByRole('row').filter({ hasText: 'Reasoning' })).toContainText('varies')
  59  |     })
  60  | 
  61  |     test('states the markup openly', async ({ page }) => {
  62  |         await expect(page.getByText('Our margin')).toBeVisible()
  63  |         await expect(page.getByText('25%')).toBeVisible()
  64  |     })
  65  | 
  66  |     test('promises that failures are free', async ({ page }) => {
  67  |         await expect(page.getByText('If a generation fails, it costs you nothing.')).toBeVisible()
  68  |     })
  69  | 
  70  |     test('will not submit an empty prompt', async ({ page }) => {
  71  |         await expect(page.getByRole('button', { name: 'Generate image' })).toBeDisabled()
  72  |     })
  73  | 
  74  |     test('counts reference images and their token cost', async ({ page }) => {
  75  |         await expect(page.getByText('0 of 4')).toBeVisible()
  76  | 
  77  |         await page.getByRole('button', { name: 'Add a reference image' }).click()
  78  |         await page.getByPlaceholder('https://example.com/style.png').fill('https://example.com/a.png')
  79  | 
  80  |         await expect(page.getByText('1 of 4')).toBeVisible()
  81  |     })
  82  | 
  83  |     test('caps reference images at the four Pixelforge accepts', async ({ page }) => {
  84  |         for (let i = 0; i < 4; i++) {
  85  |             await page.getByRole('button', { name: 'Add a reference image' }).click()
  86  |         }
  87  | 
  88  |         await expect(page.getByRole('button', { name: 'Add a reference image' })).toBeHidden()
  89  |     })
  90  | })
  91  | 
  92  | test.describe('credits', () => {
  93  |     test('blocks a generation the user cannot afford, and says why', async ({ page }) => {
  94  |         // The spent-out account has no credit left.
  95  |         await signIn(page, 'broke@example.com')
  96  | 
  97  |         await page.getByLabel('What should Pixelforge draw?').fill('an expensive picture')
  98  |         await chooseOption(page, 'Quality', 'High')
  99  |         await page.waitForResponse((r) => r.url().includes('/generate/estimate') && r.ok())
  100 | 
  101 |         await expect(
  102 |             page.getByText(/You need a little more credit to cover this one/),
  103 |         ).toBeVisible()
  104 |         await expect(page.getByRole('button', { name: 'Generate image' })).toBeDisabled()
  105 |     })
  106 | 
  107 |     test('buying credits raises the available balance', async ({ page }) => {
  108 |         // Registers its own account rather than spending the shared fixture's
  109 |         // balance, so this test cannot change what another test sees.
  110 |         const email = `buyer-${Date.now()}@example.com`
  111 | 
  112 |         await page.goto('/register')
  113 |         await page.getByLabel('Name').fill('Credit Buyer')
  114 |         await page.getByLabel('Email').fill(email)
  115 |         await page.getByLabel('Password', { exact: true }).fill('password')
  116 |         await page.getByLabel('Confirm Password').fill('password')
> 117 |         await page.getByRole('button', { name: 'Register' }).click()
      |                                                              ^ Error: locator.click: Test timeout of 60000ms exceeded.
  118 |         await expect(page).toHaveURL(/\/generate/)
  119 | 
  120 |         // A new account starts with the welcome credit.
  121 |         await expect(page.getByText('$5.0000')).toBeVisible()
  122 | 
  123 |         await page.getByRole('button', { name: '$20.00' }).click()
  124 |         await expect(page.getByText('Added $20.00 of credits.')).toBeVisible()
  125 |         await expect(page.getByText('$25.0000')).toBeVisible()
  126 |     })
  127 | })
  128 | 
  129 | test.describe('the admin report', () => {
  130 |     test('is hidden from an ordinary user', async ({ page }) => {
  131 |         await signIn(page)
  132 | 
  133 |         await expect(page.getByRole('link', { name: 'Billing' })).toBeHidden()
  134 | 
  135 |         const response = await page.goto('/admin/report')
  136 |         expect(response.status()).toBe(404)
  137 |     })
  138 | 
  139 |     test('shows spend, provider cost and profit to an operator', async ({ page }) => {
  140 |         await signIn(page, 'admin@example.com')
  141 |         await page.getByRole('link', { name: 'Billing' }).click()
  142 | 
  143 |         await expect(page.getByText('Charged to users')).toBeVisible()
  144 |         await expect(page.getByText('Paid to Pixelforge')).toBeVisible()
  145 |         await expect(page.getByText('Profit', { exact: true }).first()).toBeVisible()
  146 |         await expect(page.getByRole('columnheader', { name: 'Pixelforge says' })).toBeVisible()
  147 |         await expect(page.getByRole('columnheader', { name: 'Delta' })).toBeVisible()
  148 |     })
  149 | })
  150 | 
```