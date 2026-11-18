# Engineering notes

A Laravel 13 + Vue 3 + Inertia 2 app that generates images through Pixelforge and
records what each one costs.

Read [DESIGN.md](DESIGN.md) first; it is the design this was built from and
explains every decision below.

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
| `max_in_flight` (ours) | **advisory only** | 8 parallel workers on 8 generations, cap 3: 6-8 went through |

`max_in_flight` is an unsynchronised `COUNT(*)`, so simultaneous workers on
*different* generations read the same figure and proceed together; how far over
the cap a run lands depends on scheduling. It is a
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

### Replaying a key is a submission, not a lookup

An idempotency key only replays if Pixelforge *received* the original request.
The docs are explicit about the other case: *"If a POST request with an
Idempotency-Key was never received by the server, sending that key later will
create a new generation."* So the exact failure that produces an `unresolved`
record -- we could not tell whether the request arrived -- is the one where
re-sending might create and bill a second generation.

There is no lookup-by-key endpoint. The read-only way to ask whether something
exists is `GET /v1/generations`, matching on the `metadata.generation_id` we
send on the way out. That is what inspection does, and why inspection is the
default:

```bash
php artisan pixelforge:resolve-generation <id>            # read-only. Writes nothing, sends no POST
php artisan pixelforge:resolve-generation <id> --submit   # RE-SENDS. May create and bill
php artisan pixelforge:resolve-generation <id> --release  # frees the hold; re-checks first
```

`--release` will not take an operator's word for it: it runs the same read-only
search itself and refuses if the generation turns up, or if the search hit its
page limit before reaching the end of history. Absence is only evidence when the
search was exhaustive. `unresolved`
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

## Runbook: generations awaiting review

**Who.** Whoever is on call for billing. These are holds on real user credit, so
they do not wait for the next working day.

**How they are found.** Three ways, and they should agree:

1. `php artisan pixelforge:resolve-generation` lists everything parked, oldest
   first, with the held amount and the idempotency key.
2. The log line `Stopped replaying an unknown generation automatically` is
   written at `error` level with the generation id, user id and held amount.
   Alert on it.
3. The admin billing report: a parked generation is money reserved and not
   charged, so provider cost will exceed recorded spend for that day and the
   reconciliation banner will say so.

**What to do.**

```bash
php artisan pixelforge:resolve-generation                 # 1. what is waiting
php artisan pixelforge:resolve-generation <id>            # 2. inspect. Read-only
```

Then follow what step 2 found:

| Inspection says | Action | Why it is safe |
|---|---|---|
| Pixelforge HAS it | `--submit` | the key is known to them, so this replays; nothing extra is billed |
| No record, search exhaustive | `--release` | nothing exists, so nothing is owed |
| No record, search hit its page limit | **stop** | absence is unproven; narrow it down by hand |
| Cannot reach Pixelforge | **stop** | the hold is safe where it is; try later |

**Evidence required before `--release`.** All three:

1. Inspection reports no record **and** says the search was exhaustive. The
   command re-checks this itself and refuses otherwise.
2. `GET /v1/usage` for the day the generation was created shows no cost that our
   records cannot account for. The list endpoint pages back only so far; usage is
   the backstop for anything old.
3. Someone has read the original `error_message` and agrees the request plausibly
   never left us.

If 1 and 2 disagree, believe usage: it is the billing record. Releasing a hold on
a generation Pixelforge did build means we absorb the cost silently, which is the
one outcome none of this is allowed to produce.

**Never** reach for `--submit` to "just check". It is a submission.

**Races are safe either way.** `--release` re-reads the row under the user lock
before writing, so a webhook that lands mid-decision wins and the hold becomes a
charge. If the release gets there first, `rejected` is still overridable by real
provider news, so a later success still bills -- once, by
`UNIQUE(generation_id, type)`. Both orderings are tested.

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
