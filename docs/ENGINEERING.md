# Image Studio — working notes

A Laravel 13 + Vue 3 + Inertia 2 app that generates images through Pixelforge and
records what each one costs.

Read `docs/superpowers/specs/2026-11-18-pixelforge-image-studio-design.md` first;
it is the design this was built from and explains every decision below.

## Invariants — do not break these

1. **All money is integer nanodollars.** Never introduce a float or a decimal
   string into the money path. `Money` is the only money type.
2. **`ApplyProviderState` is the only thing that changes a generation's status or
   settles money.** The webhook handler, the submit job and the reconciler all go
   through it. Adding a fourth path that mutates status directly would break the
   guarantee that polling and webhooks cannot disagree.
3. **`LedgerWriter` is the only writer of `credit_transactions`,** and every
   mutation holds a `FOR UPDATE` lock on the user row via `forUser()`.
4. **The unique indexes are load-bearing:** `webhook_events.event_id` and
   `credit_transactions (generation_id, type)`. They are what make duplicate
   recording and double charging unrepresentable. Do not drop them.
5. **One idempotency key per generation, persisted before the first request,** and
   the create payload always comes from `Generation::providerPayload()` so every
   attempt sends identical parameters.
6. **`billed_on_utc` is a plain `Y-m-d` string, deliberately uncast.** It is the
   key `GET /v1/usage` uses, and a date cast would write `… 00:00:00` and silently
   stop matching.

## Running it

Three processes are required, not one:

```bash
php artisan serve
php artisan queue:work      # submissions run here
php artisan schedule:work   # reconciliation, every minute
```

Webhooks need a public URL — `cloudflared tunnel --url http://127.0.0.1:8000`,
then register `<tunnel>/webhooks/pixelforge` on the key in the Pixelforge
dashboard and copy that key's signing secret into `PIXELFORGE_WEBHOOK_SECRET`.

Use **one API key per environment**. Keys have separate webhook secrets and
separate `/v1/usage` reports, so sharing one makes the billing report
irreconcilable.

## Tests

```bash
./vendor/bin/pest                                     # 157 tests
npx playwright test --project=ui                      # 13 browser tests
PIXELFORGE_LIVE=1 npx playwright test --project=live  # real API, needs a tunnel
php artisan pixelforge:verify-ledger                  # proves the money adds up
```

The Playwright suites run against a real server and a real database; fixture
accounts are reset by `tests/e2e/global-setup.js`.

## Frontend conventions

- Amber (`text-estimate`) means a figure we do not know yet. Cyan
  (`text-measured`) means one the provider has settled. Those two hues are used
  for nothing else.
- Money renders through `MoneyFigure`, in IBM Plex Mono with tabular digits, so
  nine-decimal amounts align vertically and can be compared.
- The cost quote is fetched from the server (debounced). Do not reimplement the
  pricing maths in JavaScript — one tested implementation, in PHP.
