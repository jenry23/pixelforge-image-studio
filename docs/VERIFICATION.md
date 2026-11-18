# What was verified, and how

Two different kinds of confidence, kept separate on purpose: things a real
Pixelforge delivery proved, and things automated tests assert.

Verified on **2026-11-18** against the live API, with the app behind a Cloudflare
tunnel and a queue worker running.

---

## A. Verified live, against the real Pixelforge API

These happened with a real API key, real generations, and real signed webhooks
delivered over the public internet.

| # | What | Evidence |
|---|---|---|
| L1 | **Webhook signatures verify** against the real signing secret | A dashboard `ping` and 20+ generation events all answered **HTTP 204**; the delivery log shows 310–812ms, well inside the 10s budget |
| L2 | **Full lifecycle** `queued → processing → succeeded` | Driven through the UI; status updated by polling with no page reload |
| L3 | **Billed usage ties out exactly** | Our recorded provider cost `$0.064062500` vs `GET /v1/usage` `"0.064062500"` — identical to the nanodollar |
| L4 | **The 25% markup is exact** | `charged × 4 == cost × 5` held precisely across all settled generations |
| L5 | **Duplicate deliveries are charged once** | `[duplicate]` prompts; the dashboard log shows each event delivered twice ("duplicate succeeded"), both answered 204, and `verify-ledger` confirms one charge |
| L6 | **Failures are free** | `[fail]` prompts settled at `charged = 0`, hold released, balance returned to its exact prior value |
| L7 | **Idempotency replay** returns the same generation | Probed directly: same key + params → `202` + `Idempotent-Replayed: true`, same id; different params → `409 idempotency_key_reused` |
| L8 | **Concurrency cap is on provider-side in-flight work** | 5 simultaneous creates → exactly 3 accepted, 2 × `429 concurrency_limit_exceeded` with `Retry-After: 5` |
| L9 | **Error contract** | `[overloaded]` → `503` + `Retry-After: 2`; `[blocked]` → `400 content_policy_violation`; unknown parameter → `422 validation_failed` |
| L10 | **Rate-limit headers** present on every authenticated response (`X-RateLimit-Limit`, `X-RateLimit-Remaining`) |
| L11 | **The reconciliation report detects real discrepancies** | See "A finding" below — it caught genuine unrecorded provider spend |

Run it yourself:

```bash
cloudflared tunnel --url http://127.0.0.1:8000     # register the URL on the key
php artisan queue:work &
PIXELFORGE_LIVE=1 npx playwright test --project=live
```

### A finding worth recording

Partway through, the billing report refused to reconcile. It was right to.

While verifying the API contract I had created several generations with `curl`
directly — never through the app. Those were billed to the same key, so
Pixelforge reported 6 billed generations while the app had records for 2. The
report flagged the gap instead of hiding it.

The fix was the one the docs prescribe: a dedicated API key per environment, so
the app's `/v1/usage` contains only the app's own generations. The old key still
carries the probe traffic. This is exactly the failure mode the reconciliation
view exists to surface, and it surfaced it.

---

## B. Covered by automated tests

**157 Pest tests, 497 assertions** (`./vendor/bin/pest`) and **13 Playwright UI
tests** (`npx playwright test --project=ui`).

### Money

- Nine-decimal parsing with no float cast; refuses sub-nanodollar precision
- 1,000 sub-cent amounts summed with zero accumulated error
- Every published rate is whole nanodollars per token; the 25% markup is integral
- A rate that *couldn't* be billed exactly is rejected at the boundary
- Settlement reproduces both the docs' worked example and the live-verified figure
- Costing uses the generation's own rate snapshot, not current pricing
- The quoted range brackets the settled cost at every corner of the uncertainty

### Webhooks

- Valid signature accepted; wrong secret, tampered body, stale timestamp (>300s),
  missing and malformed headers all rejected `400` with **nothing persisted**
- Missing signing secret → `500`, so the misconfiguration is visible in the dashboard
- Reachable without a CSRF token
- Same event twice → one record, one charge, `204` both times
- Two *different* event ids for the same completion → second ignored (`already_final`)
- `processing` after `succeeded` → ignored; `queued` after `processing` → ignored
- A failure can never become a success
- **Webhook before the create response is saved** → matched via `metadata.generation_id`,
  and the late create response cannot regress it
- An unmatchable but validly-signed event is recorded, acknowledged, and left for
  the reconciler rather than discarded
- A `succeeded` payload with no usage defers rather than applying a final state it
  could never settle
- A second charge row is rejected by the database even if application logic were bypassed

### Submission and retries

- The idempotency key exists on the committed row **before** any request
- The same key is reused across an injected timeout
- Byte-identical parameters on every attempt, so a `409` is impossible
- `Retry-After` honoured exactly (37s, 5s, 2s cases); exponential backoff otherwise
- The in-flight gate releases **without making an HTTP call** and **without
  consuming retry budget**
- In-flight counted account-wide, since the provider's limits are per account
- Budget exhausted + outcome unknown → `unknown`, **hold kept**, resolver dispatched
- Budget exhausted + outcome known → `rejected`, hold released
- `ResolveUnknownGeneration` replays the key and adopts whatever it finds; fails
  closed and releases the hold when its window closes
- A generation already finished by a webhook is never re-submitted

### Credits

- Reserves the **maximum**, not the expected cost
- A second submission sees the first's hold and is refused (exact figure asserted:
  `$0.340075` reserved, `$0.40` balance)
- Many sequential submissions never let holds exceed the balance
- Settlement charges the actual cost and returns the difference
- Failure returns the whole reservation
- The cached balance always equals the folded ledger; `verify-ledger` catches drift
- The client cannot mint an arbitrary top-up amount

### Reporting

- Spend, provider cost and profit per UTC day and per user
- **Bucketed by the day the generation *completed***, asserted with one created
  23:59:45 and completed 00:00:10 the next day
- 300 sub-cent generations summed without drift
- A **one-nanodollar** discrepancy is detected (exact integer comparison, no tolerance)
- Usage endpoint unavailable (503, and timeout) → shown explicitly as unverified,
  never as agreement
- Admin-only; ordinary users get a 404

### Browser

Cost shown before submitting · repricing on quality, size and prompt length ·
exact vs varying token types distinguished · markup stated openly · empty prompt
blocked · reference images capped at 4 · unaffordable generation blocked with a
reason · buying credits raises the balance · admin report visible only to admins.

---

## C. Not verified

Stated plainly rather than implied.

- **True parallel-process double-charging.** Concurrency is enforced by a
  `SELECT … FOR UPDATE` on the user row plus the `UNIQUE (generation_id, type)`
  constraint. The constraint is asserted directly (a second charge insert throws),
  and the lock serialises the logic — but the tests run on SQLite, which
  serialises writes anyway, so this was not exercised with genuinely concurrent
  workers against Postgres.
- **`daily_limit_exceeded` against the live API.** Handled and unit-tested, but
  triggering it needs 500 generations in a day.
- **Webhook retry schedule** (10s/30s/2m/10m/15m, 6 attempts). Our endpoint
  answered 204 every time, so no real retry occurred. The redelivery path is
  covered by tests.
- **Long-run reconciler behaviour.** Exercised in tests and wired into the
  scheduler, but not observed over hours of production traffic.
- **Images past their 24-hour expiry.** The expired state is rendered from
  `image_url_expires_at`, but no URL has actually expired yet.
