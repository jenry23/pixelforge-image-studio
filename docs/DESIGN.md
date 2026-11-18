# Pixelforge Image Studio — Design

**Date:** 2026-11-18
**Status:** Approved (Shape B, both stretch goals)

## 1. Intent and why

Users write a prompt, pick a size and quality, and get an image generated through
Pixelforge. The app charges them Pixelforge's cost plus a 25% markup.

The point of the exercise is not the image. It is that **every generation is
recorded at exactly the right cost, exactly once, and failures are free** — while
the provider actively misbehaves (rate limits, concurrency caps, overloads,
mid-flight failures, duplicate and out-of-order webhooks).

So the design optimises for one thing: *the money is provably right*, and it is
right because the database makes wrongness unrepresentable, not because the
application code remembers to check.

## 2. Goals

- A generation is charged **exactly once** or not at all. Never twice, never zero
  when it succeeded, never anything when it failed.
- Our recorded provider cost **ties out to `GET /v1/usage`** to the last of nine
  decimal places.
- Correct terminal state under duplicate, out-of-order, early, and *entirely
  lost* webhooks.
- A user cannot spend credits they do not have, even with simultaneous submits.
- Clear UX: what it will cost (honestly, as a range), what is happening now, and
  what went wrong.
- Lean enough to walk through and extend live in an interview.

## 3. Non-goals

- Real payments. "Buy credits" is a fake top-up.
- Permanent image hosting. Provider URLs expire after 24h; that is acceptable per
  the brief. (We store the URL and its expiry, and show an expired state.)
- Multi-tenant org/team structures, roles beyond `user` / `admin`.
- Websocket infrastructure. Polling is sufficient and deployable anywhere.
- Prompt moderation of our own. We surface the provider's `content_policy_violation`.

## 4. Anti-goals (what makes this a failure even if it "works")

- A cost that is *approximately* right. Float arithmetic anywhere in the money path.
- A double charge under webhook replay, even in a rare race.
- A charge for a failed generation.
- Credits reserved and never released, silently leaking a user's balance.
- An admin report that shows our own numbers as if verified when the provider's
  usage endpoint was actually unreachable.

## 5. Constraints (verified against the live API on 2026-11-18)

Every item below was confirmed by probing the real API, not only read in the docs.

| Constraint | Verified behaviour |
|---|---|
| Money format | Exact decimal string, **9 dp** (`"0.011707500"`). Docs explicitly recommend "integers (for example nanodollars)". |
| Rates | `$5.00` input text, `$10.00` input image, `$40.00` output, `$12.50` reasoning, per 1M tokens. Snapshotted onto each generation at creation; later price changes do not apply. |
| Idempotency | Same key + same params → `202` + `Idempotent-Replayed: true`, **same generation id**, nothing extra billed, does not count against concurrency/daily limits. Same key + different params → `409 idempotency_key_reused`. Keys never expire, scoped to account. |
| Limits | **Per account, across all keys.** 60 req/min; **3 generations queued-or-processing**; 500/UTC-day. |
| `Retry-After` | Present and respected: `overloaded` → `2`, `concurrency_limit_exceeded` → `5`, `rate_limit_exceeded` → seconds. |
| Concurrency cap | Probed with 5 simultaneous creates: exactly 3 accepted, 2 × `429 concurrency_limit_exceeded`. Cap is on **provider-side in-flight generations**, not on our worker. |
| Rejections | `400 content_policy_violation`, `422 validation_failed` (unknown params rejected, not ignored), `503 overloaded` — all create nothing and bill nothing. |
| Webhook signature | `Pixelforge-Signature: t=<unix>,v1=<hex>`; HMAC-SHA256 of `"{t}.{raw_body}"` keyed with that key's `whsec_…`. Constant-time compare; reject `|now − t| > 300`. |
| Webhook delivery | At least once, **any order**, **can arrive before the create response returns**, may never arrive at all. Retries 10s/30s/2m/10m/15m, 6 attempts. Any 2xx within 10s = delivered. |
| Usage attribution | **Billed on the UTC day it *completed*, not created.** Per API key. |
| Token knowledge | Output tokens and image tokens are **exactly known up front**. Input text is `ceil(chars/4)` ±15%. Reasoning is only known at completion, within a wide per-quality range. |

### Material ambiguities, and the call made

1. **Up-front price is a range, not a number.** For `high`, reasoning spans
   1,000–8,000 tokens — a $0.0875 spread, larger than the rest of the cost. A
   single "estimated cost" would be misleading. **Decision:** show expected cost
   prominently with the min–max range beside it, and reserve credits against the
   **maximum**. Tradeoff: a user needs max-cost credit to submit, which is
   slightly conservative, but it is the only way to guarantee no overdraft.
2. **Rounding for display vs storage.** Costs are sub-cent. **Decision:** store
   exact nanodollars forever; round only in the view layer, to 6 dp for
   per-generation figures and 2 dp for aggregate dollar totals. Never round before
   summing.
3. **What `unknown` submit outcomes mean for the user.** See §8.4. **Decision:**
   bounded idempotent replay to resolve, then fail closed and release the hold.
4. **Reference images are not downloaded by Pixelforge** but are billed at 765
   tokens each. We validate URL shape only; we do not fetch them.

## 6. Approaches considered

**Shape A — thin synchronous.** Controller calls the provider inline; cost derived
on read. Rejected: a submit timeout leaves the billable outcome permanently
unknowable, `429`/`503` become user-facing errors, and the 3-in-flight cap cannot
be respected without blocking a web request.

**Shape B — queued submission over an append-only ledger.** *Chosen.* Submit is a
local write plus a job; the job owns every provider conversation, so each provider
failure mode becomes a retry policy. An `Idempotency-Key` persisted before the
first request makes retries provably non-duplicating.

**Shape C — event-sourced generations** (rebuild state purely by folding the event
log). Rejected as over-built for the scope: it buys replayability we do not need
and costs read complexity on every page. We keep the *useful half* — a durable,
deduplicated event log — and fold it into a mutable projection.

Within Shape B: **Reverb/websockets** rejected for live status in favour of
adaptive polling (no extra infra to deploy, deterministic under Playwright);
correctness lives in the reconciler, not in the transport.

## 7. Money representation

**Integer nanodollars (1 USD = 1_000_000_000 nd), stored in `BIGINT`.**

Exactness proof, which a test asserts rather than trusting:

- A rate is dollars-per-1M-tokens with 2 dp. Nanodollars per token
  = `rate × 10^9 / 10^6` = `rate × 1000`. With 2 dp that is always an integer:
  `12.50 → 12_500`, `5.00 → 5_000`, `10.00 → 10_000`, `40.00 → 40_000`.
- Provider cost = `Σ tokens × nd_per_token` — integer, exact.
- Markup is ×5/4. Every `nd_per_token` above is divisible by 4
  (`12_500/4 = 3_125`), so the charge is integral too.

Verified end to end against the provider: usage `{9, 0, 280, 37}` →
`11_707_500 nd` → `$0.011707500`, and `GET /v1/usage` reported exactly
`"0.011707500"`. Charge `×1.25 = 14_634_375 nd`.

A `Money` value object wraps the integer. No float or string arithmetic touches
the money path; a test asserts representability for every documented rate.

## 8. Design

### 8.1 Local status model

`pending → queued → processing → succeeded | failed`
plus terminal `rejected` (provider refused at submit; nothing created) and
`unknown` (retry budget exhausted, provider outcome genuinely unknown).

Each status has a **rank**: `pending 0, queued 1, processing 2, succeeded/failed/rejected 3`.
`unknown` is rank 0 — it is a *pre-provider* state, so any real provider news wins.

### 8.2 One transition path for every source

A single action, `ApplyProviderState`, is the **only** code that mutates a
generation's status or settles money. It is used identically by:

- the webhook handler,
- the create-response handler in the submit job,
- the reconciler's `GET /v1/generations/{id}` poll.

It runs in one `DB::transaction` and applies monotonically:

```
accept the incoming state iff
    incoming_rank > current_rank
 OR (incoming_rank == current_rank AND incoming_event_at > current_status_event_at)
```

So a retried `generation.processing` arriving after `generation.succeeded` is
ignored (rank 2 < 3), and a stale poll can never regress a settled generation.
Settlement happens in the same transaction as the transition that makes the
generation final — they cannot diverge.

### 8.3 Webhook pipeline

1. Read the **raw body** before any decoding.
2. Parse `t` and `v1`; reject if `|now − t| > 300`; `hash_equals` against
   HMAC-SHA256 of `"{t}.{raw}"`. Invalid → `400`, nothing stored.
3. `DB::transaction`: insert into `webhook_events` with a **`UNIQUE` index on
   `event_id`**. A duplicate raises a unique violation, which we catch and answer
   `200` — dedupe is a *database invariant*, not an application check, so no race
   can defeat it. Then resolve the target generation and call `ApplyProviderState`.
4. Commit, **then** return `204`. The event is durably recorded before we
   acknowledge, so an ack never outlives the data.
5. Slow follow-up work (persisting the image) is dispatched after commit, keeping
   us far inside the 10s budget.

**Early-webhook race.** We send `metadata: {"generation_id": "<our id>"}` on every
create — the docs state metadata is returned "in every webhook, so you can match
them to your own records". The handler resolves the target by `metadata.generation_id`
first, then by `provider_id`. Because the local row is created *before* submitting,
a webhook that overtakes the create response still matches. If no match is found,
the event is still stored and acknowledged, and the reconciler applies it later —
we never discard a validly-signed event.

### 8.4 Submission and retries

- **One idempotency key per generation, persisted before the first request.**
  Generated and committed with the `pending` row. Every attempt — including after
  an ambiguous timeout — reuses it. Parameters are frozen at creation, so a `409`
  is impossible by construction (and treated as a defect if seen).
- **Provider in-flight gate, tracked separately from worker concurrency.** Before
  posting, count generations in `queued|processing` *account-wide*. At ≥3, release
  the job for 5s. This is deliberately **not** the queue's concurrency setting:
  a submit job finishes in ~200ms while its generation stays in flight at the
  provider for 5–30s, so worker concurrency is not a proxy for provider capacity.
- **Error handling by class:**
  | Response | Action |
  |---|---|
  | `202` | Apply state via `ApplyProviderState`. Done. |
  | `429` rate / concurrency / daily | Release for `Retry-After` seconds (+jitter). Counts against budget. |
  | `503 overloaded` | Release for `Retry-After` (2s) with exponential backoff. |
  | Timeout / connection error | **Ambiguous** — retry with the same key. Bounded. |
  | `400` / `422` | Terminal `rejected`. Release hold. No retry. |
  | `409` | Terminal `rejected`; log as a defect. Release hold. |
- **Retry budget: 6 attempts across ~5 minutes.** On exhaustion with the outcome
  still unknown, status becomes `unknown` and **the hold is kept** — the provider
  may have created a billable generation we cannot see.
- **Resolving `unknown`.** A scheduled resolver replays the idempotent create. The
  contract guarantees this either returns the existing generation (adopt it, bill
  correctly) or creates it once (the user gets their image) — in neither case can
  it bill twice. Bounded to 1 hour of attempts; after that the generation is marked
  `rejected`, the hold is released, the user is told plainly that we could not
  confirm it and was not charged, and it is logged for an operator. The admin
  reconciliation page (§8.6) surfaces any provider cost we failed to record, so this
  failure mode is visible rather than silent.
  *Tradeoff:* replay may produce an image slightly later than the user expected. The
  alternative — never replaying — risks paying Pixelforge for a generation we never
  recorded, which violates an anti-goal.

### 8.5 Credits: append-only ledger with holds

`credit_transactions` is append-only. Each row carries a signed `balance_delta`
and `hold_delta`:

| type | balance_delta | hold_delta | when |
|---|---|---|---|
| `topup` | `+A` | `0` | Buy credits |
| `hold` | `0` | `+H` | At submit, `H` = **max** estimated charge |
| `release` | `0` | `−H` | Failure, rejection, or abandoned submit |
| `charge` | `−C` | `−H` | Settlement on success |

`available = Σ balance_delta − Σ hold_delta`.

- **One charge per generation, enforced by the database:**
  `UNIQUE (generation_id, type)`. A second `charge` row is impossible, so even a
  perfectly-timed webhook/poll race cannot double-bill.
- **Atomic reservation:** `SELECT … FOR UPDATE` on the user row, check
  `available ≥ H`, insert the hold, update the materialised columns — one
  transaction. Simultaneous submits serialise; the second sees the first's hold.
- `users.balance_nanodollars` / `held_nanodollars` are a materialised cache
  written in the same transaction. `pixelforge:verify-ledger` asserts they equal
  the ledger fold, so drift is detectable rather than theoretical.

### 8.6 Admin report and reconciliation

Per UTC day and per user: **credit spend** (what users were charged), **provider
cost** (what Pixelforge charged us), and **profit** (the difference).

Bucketed by `completed_at` in UTC, matching the provider's stated attribution
("billed on the UTC day it completed"). Getting this wrong is the most likely
cause of a report that almost ties out, so it is asserted by a test with a
generation created on one UTC day and completed on the next.

The page fetches `GET /v1/usage` for the same window and shows, per day, our
computed provider cost against theirs with an explicit **delta** column. If the
usage endpoint errors or times out, the page says so in a banner and renders the
reconciliation column as unavailable — it never presents unverified numbers as
verified.

### 8.7 Reconciler (scheduled, every minute)

- `queued|processing` with a `provider_id` and no news for >45s → poll and apply.
- `unknown` → idempotent replay (§8.4), bounded.
- `pending` with no `provider_id` older than 2 min → re-dispatch submit.
- Stored but unapplied webhook events → re-apply.

This is what makes a permanently lost webhook a non-event, and satisfies the
brief's "a webhook **or by polling**".

### 8.8 Frontend

Vue 3 + Inertia 2, Tailwind. Components kept small and single-purpose:
`GenerateForm`, `CostEstimate`, `ReferenceImageInput`, `GenerationCard`,
`StatusPill`, `GenerationList`, `BalanceWidget`, `BuyCreditsDialog`, and admin
`DailyReportTable` / `ReconciliationBanner`.

Live status by **adaptive polling**: a composable reloads only the `generations`
and `balance` props every 2.5s *while any generation is non-final*, and stops
entirely when everything is settled — no idle traffic, and deterministic in tests.

Cost is shown before submit as expected + range, with the breakdown by token type,
and the submit button is disabled with a clear reason when `available < max`.

## 9. Requirement → acceptance check

| # | Requirement (source) | Acceptance check |
|---|---|---|
| R1 | Laravel + Vue + Inertia, users sign in | Auth feature tests; Playwright signs in and reaches `/generate` |
| R2 | Prompt, size, quality, optional reference URLs | Validation tests incl. >4 refs, bad size/quality, 4001-char prompt |
| R3 | Show cost before submit | Component test on `CostEstimate`; Playwright asserts the figure changes with quality |
| R4 | Cost = provider + 25% | Unit test: `charge == provider × 5/4`, exact |
| R5 | Up-front price is an estimate | Test: estimate range brackets the settled actual for 50 simulated usages |
| R6 | Record actual cost on completion | Settlement test asserts `provider_cost_nd` and `charged_nd` from the webhook payload |
| R7 | Failed generations cost nothing | Test: `generation.failed` → `charged = 0`, hold released, balance unchanged |
| R8 | Fractions of a cent handled | Representability test over all rates; exact match vs provider's `"0.011707500"` |
| R9 | History with live status | Playwright watches `queued → processing → succeeded` without reload |
| R10 | Estimated and actual cost both shown | `GenerationCard` renders both; Playwright asserts both present |
| R11 | Verify webhook signatures | Tests: valid, bad sig, stale `t`, missing header, tampered body |
| R12 | Right state when events arrive twice | Duplicate event → `200`, one `webhook_events` row, exactly one `charge` |
| R13 | Right state when out of order | `processing` after `succeeded` → ignored, still one charge |
| R14 | Event twice never recorded twice | `UNIQUE(event_id)` + assertion on ledger row count |
| R15 | Idempotency key before first request | Test asserts key persisted pre-request; reused across an injected timeout |
| R16 | Honor `Retry-After`, bound retries | Faked `429`/`503` assert release delay == header, and budget exhaustion |
| R17 | Budget exhausted, outcome unknown | Test: → `unknown`, hold **kept**, resolver replays idempotently |
| R18 | Provider cap ≠ worker concurrency | Test: 3 in flight → 4th job released 5s without an HTTP call |
| R19 | Webhook before submit response saved | Test: webhook applied via `metadata.generation_id`, create response does not regress it |
| R20 | Polling racing a success webhook | Concurrent apply → exactly one charge (unique index) |
| R21 | Only one charge settles | DB-level: second insert raises; test asserts single row |
| R22 | Reserve credits atomically | Concurrent-submit test: two submits, insufficient for both → one succeeds |
| R23 | Hold released on confirmed failure | Covered by R7 plus explicit release assertion |
| R24 | Block generating when unaffordable | Test returns 422; Playwright sees disabled button + reason |
| R25 | Admin report: spend, cost, profit per day & user | Report test with fixtures across two users and two days |
| R26 | Match provider UTC usage window | Test: created day N, completed day N+1 → bucketed to N+1 |
| R27 | Report discrepancy | Test: seeded mismatch surfaces a non-zero delta |
| R28 | Usage endpoint unavailable shown explicitly | Test: faked 503 → banner, no fabricated reconciliation |
| R29 | Deploy instructions | `DEPLOY.md` covering worker, scheduler, env, webhook registration |
| R30 | Live verification | Real tunnelled delivery: signature, lifecycle, billed usage tie-out |

## 10. Left to the builder

- Exact Tailwind palette and component styling, within the stated UX goals.
- Pest vs PHPUnit syntax details (Pest is the Laravel 13 default; use it).
- Whether the materialised balance columns live on `users` or a `wallets` table —
  chosen as columns on `users` for leanness; revisit only if teams are added.
- Queue driver locally (`database` is fine; Redis if available).
- Image persistence beyond 24h is out of scope, but the download job is stubbed
  behind a config flag so it can be switched on.
