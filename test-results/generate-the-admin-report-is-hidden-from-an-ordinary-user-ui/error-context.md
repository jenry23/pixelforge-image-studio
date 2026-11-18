# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: generate.spec.js >> the admin report >> is hidden from an ordinary user
- Location: tests/e2e/generate.spec.js:130:5

# Error details

```
Error: locator.fill: Target page, context or browser has been closed
Call log:
  - waiting for locator('#email')

```

```
Error: write EPIPE
```

```
Error: browserContext.close: Target page, context or browser has been closed
```