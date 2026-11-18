# Deploying

The app needs three processes, not one. A web server alone will accept
generations and never submit them.

| Process | Why it is required |
|---|---|
| web | serves the app and receives webhooks |
| `php artisan queue:work` | **submissions run on the queue.** Without a worker, generations sit in `pending` forever |
| `php artisan schedule:run` (every minute) | runs `pixelforge:reconcile`, which settles anything a lost webhook left stranded |

## 1. Create an API key for this environment

Each Pixelforge key has its **own webhook URL, its own signing secret, and its
own `GET /v1/usage` report**. Use one key per environment, or your deployed app's
numbers will be mixed in with local development's and the billing report will
never reconcile.

1. [Dashboard → API keys](https://pixelforge.promptedit.dev/api-keys) → create a
   key named for the environment (e.g. `production`). It is shown once.
2. [Dashboard → Webhooks](https://pixelforge.promptedit.dev/webhooks) → set that
   key's **Endpoint URL** to `https://<your-domain>/webhooks/pixelforge` and save.
3. Reveal and copy that key's **signing secret** (`whsec_…`).

> The webhook URL must be publicly reachable. Pixelforge rejects loopback and
> private addresses.

## 2. Environment

```dotenv
APP_NAME="Image Studio"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain

# Postgres or MySQL in production; SQLite is fine locally.
DB_CONNECTION=pgsql
# …host, port, database, username, password

# The queue must be durable. `sync` would run submissions inside the web
# request, which defeats the entire retry design.
QUEUE_CONNECTION=database     # or redis
SESSION_DRIVER=database
CACHE_STORE=database          # or redis

PIXELFORGE_API_KEY=pf_…                 # this environment's key
PIXELFORGE_WEBHOOK_SECRET=whsec_…       # that same key's signing secret
PIXELFORGE_MARKUP_PERCENT=25
```

Optional tuning (sensible defaults are built in):

```dotenv
PIXELFORGE_MAX_IN_FLIGHT=3              # provider cap on queued+processing
PIXELFORGE_SUBMIT_MAX_ATTEMPTS=6        # provider attempts before parking as `unknown`
PIXELFORGE_SUBMIT_LEASE_SECONDS=120     # must exceed the worker --timeout; see below
PIXELFORGE_RESOLVE_UNKNOWN_MINUTES=60   # how long to keep replaying an unknown outcome
PIXELFORGE_WEBHOOK_TOLERANCE=300        # reject signatures older than this
PIXELFORGE_RECONCILE_AFTER=45           # poll a generation gone quiet this long
PIXELFORGE_SIGNUP_CREDIT_USD=5.00       # welcome credit for new accounts
```

`PIXELFORGE_MAX_IN_FLIGHT` is **per Pixelforge account, not per deployment**. If
local development and production share one account, together they can exceed 3
in flight and will see `429 concurrency_limit_exceeded`. That is handled (the job
honours `Retry-After`), but lower the value if you run both heavily at once.

## 3. Deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### Laravel Cloud

Add two processes beyond the web one:

- **Worker** — command `php artisan queue:work --tries=1 --timeout=60`
- **Scheduler** — enable it; it runs `schedule:run` every minute

`--tries=1` is deliberate: the jobs manage their own retry budget and persist it
on the generation row, so letting the queue retry them as well would double-count
attempts.

**Keep `PIXELFORGE_SUBMIT_LEASE_SECONDS` above the worker's `--timeout`.** A
submit attempt is claimed for the length of the lease and released on a clean
exit. A worker killed by the queue timeout cannot release it, which is precisely
how the next attempt learns the outcome is unknown. If the lease were shorter
than the timeout, a *still-running* job would look crashed and a second worker
could submit alongside it. At the defaults (60s timeout, 120s lease) there is 2x
headroom. Recovery takes up to one lease, so do not set it far higher than
needed.

### Anywhere else (systemd, supervisor, containers)

```ini
[program:image-studio-worker]
command=php /srv/app/artisan queue:work --tries=1 --timeout=60
autorestart=true
numprocs=1

[program:image-studio-scheduler]
command=php /srv/app/artisan schedule:work
autorestart=true
```

Or a cron entry for the scheduler:

```cron
* * * * * cd /srv/app && php artisan schedule:run >> /dev/null 2>&1
```

## 4. Verify the deployment

```bash
# 1. The app is up
curl -sS https://your-domain/up

# 2. Webhooks arrive and verify.
#    Dashboard → Webhooks → "Send test event" on this environment's key.
#    The delivery log must show HTTP 204. A 400 means the signing secret is
#    wrong; a 500 means it is missing.

# 3. Generate one image through the UI, then:
php artisan pixelforge:verify-ledger    # must exit 0

# 4. Open /admin/report as an admin. The banner must read
#    "Provider costs match Pixelforge to the nanodollar."

# 5. Nothing should be waiting on a person yet
php artisan pixelforge:resolve-generation   # expect "Nothing is awaiting review." 
```

If the banner instead says the usage endpoint could not be reached, the report is
showing **unverified** figures — that is deliberate, so our own numbers are never
presented as confirmed when nothing confirmed them.

## Operating notes

- **`pixelforge:verify-ledger`** exits non-zero on any discrepancy. Worth
  alerting on; it runs daily at 03:00 by default.
- **Generations parked as `unresolved` need a person.** When replays cannot
  confirm an outcome, the generation stops retrying and holds the user's credit
  rather than guessing. Nothing clears these automatically, by design. Put
  `php artisan pixelforge:resolve-generation` (no arguments lists them) in the
  on-call routine, and alert on the log line `Stopped replaying an unknown
  generation automatically`, which is written at `error` level with the user id
  and the amount held. The full procedure, including the evidence required
  before releasing a hold, is in [docs/ENGINEERING.md](docs/ENGINEERING.md).
- **A persistent delta in the billing report** means provider cost we have not
  recorded. The usual cause is a generation created outside the app on the same
  key (a manual `curl`, say), or one still sitting `unresolved`. Both are logged.
- **Images expire 24 hours** after completion. The app stores the URL and its
  expiry and shows an expired state; it does not re-fetch, since a fresh URL
  expires at the same moment.
- **Rotating the signing secret** invalidates in-flight deliveries. Update
  `PIXELFORGE_WEBHOOK_SECRET` immediately after rotating; the reconciler will
  settle anything missed in the gap.
