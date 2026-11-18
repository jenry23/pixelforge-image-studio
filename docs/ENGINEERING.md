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

## Recovery paths for an in-doubt submission

A submit attempt is claimed with one conditional `UPDATE`, and the row that the
database reports as changed is the one worker that owns the attempt. The claim
is ours; Pixelforge's idempotency key and its in-flight cap are separate
safeguards, and none of the three is allowed to rely on the others.

The three are not equally strict, and it matters which is which:

| Safeguard | Strength | Verified |
|---|---|---|
| Attempt claim (ours) | **exact** -- one generation, one worker | 12 parallel workers: 1 claim, `submit_attempts` = 1 |
| Idempotency key (provider) | exact -- same key returns the same generation, nothing extra billed | docs; replay tests |
| `max_in_flight` (ours) | **advisory only** | 8 parallel workers on 8 generations, cap 3: all 8 went through |

`max_in_flight` is an unsynchronised `COUNT(*)`, so simultaneous workers on
*different* generations all read the same figure and all proceed. It is a
pressure valve, not a limit. The real limit is Pixelforge's, enforced with 429
and honoured here through `Retry-After` backoff. Both figures come from
`tests/concurrency/run.sh`, which is opt-in and not part of the Pest suite.

A worker that exits cleanly clears its claim. A worker that is killed mid-request
cannot, so a claim still held past `submit_lease_seconds` means a previous
attempt reached an unknown end. The next worker marks the generation
`submit_outcome_unknown` before deciding anything else. That is what stops a
killed *final* attempt from being written off as a clean failure when Pixelforge
may already have accepted and billed it.

| Situation | Status | Hold | What resolves it |
|---|---|---|---|
| Provider refused it | `rejected` | released | nothing to do |
| Retries spent, nothing was created | `rejected` | released | nothing to do |
| Retries spent, outcome unknown | `unknown` | **kept** | `ResolveUnknownGeneration` replays the idempotency key |
| Worker killed mid-request | unchanged | kept | next attempt adopts the stale claim as unknown |
| Resolution window closed, still unknown | `unresolved` | **kept** | an operator, via `pixelforge:resolve-generation` |

### What the 60-minute cutoff is, and is not

`PIXELFORGE_RESOLVE_UNKNOWN_MINUTES` is **not** an idempotency deadline. The API
docs are explicit: *"Keys are scoped to your account and never expire."* A replay
is therefore just as valid a week later as a minute later, and nothing is lost by
waiting.

The cutoff is only the point where we stop replaying *automatically*, so a
provider that is persistently unreachable cannot keep a job retrying forever.
What it must never do is invent an answer. Earlier this path marked the
generation `rejected` and released the hold, telling the user "You were not
charged" about something Pixelforge may well have built and billed us for. It now
parks the generation as `unresolved` with the hold intact, and escalates:

```bash
php artisan pixelforge:resolve-generation            # what is waiting
php artisan pixelforge:resolve-generation <id>       # replay the key, settle on the answer
php artisan pixelforge:resolve-generation <id> --release   # only after confirming nothing was created
```

Because the key never expires, the replay is always able to reach a definitive
answer, so the hold is released on evidence rather than on a timer. `unresolved`
ranks alongside `pending`, so a late webhook still settles it normally -- the
hold becomes the charge, and the ledger's `UNIQUE(generation_id, type)` keeps
that to exactly once however many deliveries arrive.

Nothing in that table depends on a queue job surviving. `pixelforge:reconcile`
runs every minute and re-dispatches `ResolveUnknownGeneration` for anything
sitting in `unknown`, re-dispatches `SubmitGeneration` for anything `pending`
with no `provider_id` after two minutes, and polls generations in flight at the
provider. A success webhook arriving at any point in this is applied normally:
`unknown` ranks alongside `pending`, so it never blocks a terminal state, and
the event matches on `metadata.generation_id` while `provider_id` is still null.

The hold is only released on a *confirmed* negative. While the outcome is in
doubt the user's credit stays reserved, because Pixelforge may have produced
something we will be billed for.

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
