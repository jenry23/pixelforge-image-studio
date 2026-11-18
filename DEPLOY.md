# Deploying

The app needs three processes, not one. A web server alone will accept
generations and never submit them.

| Process | Why it is required |
|---|---|
| web | serves the app and receives webhooks |
| `php artisan queue:work` | **submissions run on the queue.** Without a worker, generations sit in `pending` forever |
| `php artisan schedule:run` (every minute) | runs `pixelforge:reconcile`, which settles anything a lost webhook left stranded |

## The order matters

Pixelforge will not accept a webhook endpoint that is not publicly reachable, so
the app has to be live before the provider can be configured. That forces this
sequence, and steps taken out of order are the usual cause of a first deploy that
looks broken:

```
1. APP_KEY + database        ->  the app can boot at all
2. build, migrate, boot web  ->  /up answers 200, and you have a domain
3. create the admin          ->  someone can sign in
4. Pixelforge key + secret   ->  now that a public URL exists to register
5. reload the config cache   ->  or the new values are not read
6. worker + scheduler        ->  only now can a generation complete
7. first real generation     ->  end to end
```

## 1. Before the first boot

`APP_KEY` and the database must be set before anything starts; the app cannot
boot without them.

```dotenv
APP_NAME="Image Studio"
APP_ENV=production
APP_KEY=                      # php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://<your-domain>

DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

QUEUE_CONNECTION=database     # never sync: that runs submissions in the request
SESSION_DRIVER=database
CACHE_STORE=database
```

Leave `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` empty for now. The app
boots fine without them; only generation needs them.

## 2. Build, migrate, and bring the web app up

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`public/build` is not in the repository, so `npm run build` must run on the host
or the app serves without assets.

Verify before going further:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://<your-domain>/up    # expect 200
```

That 200 is also what tells you the domain is reachable from outside, which is
the precondition for the next step.

## 3. Create the first admin

For a demo deployment that reviewers need to sign in to, seed the demo accounts:

```bash
php artisan db:seed --force
```

`--force` is required because Laravel will not seed a production environment
without it. That creates `admin@example.com`, `user@example.com` and
`broke@example.com`, all with the password `password`.

For anything where the data matters, skip the seeder and create an admin whose
password is not published:

```bash
php artisan pixelforge:create-admin
```

It prompts for a name, an email and a password typed twice and never echoed, so
no credential ends up in a seeder, a deploy script, or shell history. Minimum
twelve characters. Sign in at `https://<your-domain>/login` to confirm.

**On a host with no TTY** -- Laravel Cloud's command runner included -- there is
nothing to prompt, so the password comes from the environment instead of from an
argument, which would otherwise land in the deploy log:

1. add `ADMIN_INITIAL_PASSWORD` to the environment and redeploy
2. `php artisan pixelforge:create-admin --name="Your Name" --email="you@example.com"`
3. delete `ADMIN_INITIAL_PASSWORD` and redeploy

Step 3 is not optional. Leaving it set keeps a working password in the
environment of a running app.

Further admins come from the same command. There is no way to grant the admin
flag through the web app, by design.

## 4. Configure Pixelforge, now that there is a URL to register

Each key has its **own webhook URL, its own signing secret, and its own
`GET /v1/usage` report**. Use one key per environment, or the deployed numbers
mix with local development's and the billing report will never reconcile.

1. [Dashboard -> API keys](https://pixelforge.promptedit.dev/api-keys) -> create a
   key named for the environment (e.g. `production`). It is shown once.
2. [Dashboard -> Webhooks](https://pixelforge.promptedit.dev/webhooks) -> set that
   key's **Endpoint URL** to `https://<your-domain>/webhooks/pixelforge`.
3. Reveal and copy that key's **signing secret** (`whsec_...`).

```dotenv
PIXELFORGE_API_KEY=pf_...
PIXELFORGE_WEBHOOK_SECRET=whsec_...
PIXELFORGE_MARKUP_PERCENT=25
```

## 5. Reload the config cache

`config:cache` froze the earlier values, so the new ones are invisible until it
is rebuilt. This is the step people miss, and it presents as a webhook returning
500 with "signing secret is not configured".

```bash
php artisan config:clear
php artisan config:cache
```

## 6. Start the worker and the scheduler

Only now can a generation actually complete. A web process on its own accepts
generations and never submits them.

| Process | Command |
|---|---|
| Worker | `php artisan queue:work --tries=1 --timeout=60` |
| Scheduler | `schedule:run` every minute, or `php artisan schedule:work` |

### Laravel Cloud

Add a **Worker** process with that command, and enable the **Scheduler** toggle.

### supervisor

```ini
[program:image-studio-worker]
command=php /srv/app/artisan queue:work --tries=1 --timeout=60
autostart=true
autorestart=true
numprocs=1
stopwaitsecs=70
stdout_logfile=/var/log/image-studio-worker.log

[program:image-studio-scheduler]
command=php /srv/app/artisan schedule:work
autostart=true
autorestart=true
stdout_logfile=/var/log/image-studio-scheduler.log
```

Keep `stopwaitsecs` above `--timeout` so a restart does not kill a job mid-flight.

### systemd

```ini
[Unit]
Description=Image Studio queue worker
After=network.target

[Service]
User=www-data
Restart=always
RestartSec=5
TimeoutStopSec=70
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php /srv/app/artisan queue:work --tries=1 --timeout=60

[Install]
WantedBy=multi-user.target
```

Or cron for the scheduler alone:

```cron
* * * * * cd /srv/app && php artisan schedule:run >> /dev/null 2>&1
```

`--tries=1` is deliberate: the jobs carry their own retry budget on the
generation row, so queue-level retries would double-count attempts.

**Keep `PIXELFORGE_SUBMIT_LEASE_SECONDS` above the worker's `--timeout`.** A
submit attempt is claimed for the length of the lease and released on a clean
exit. A worker killed by the queue timeout cannot release it, which is precisely
how the next attempt learns the outcome is unknown. If the lease were shorter
than the timeout, a *still-running* job would look crashed and a second worker
could submit alongside it. At the defaults (60s timeout, 120s lease) there is 2x
headroom. Recovery takes up to one lease, so do not set it far higher.

Optional tuning, all with working defaults:

```dotenv
PIXELFORGE_MAX_IN_FLIGHT=3              # advisory; the provider enforces the real limit
PIXELFORGE_SUBMIT_MAX_ATTEMPTS=6        # attempts before parking as `unknown`
PIXELFORGE_SUBMIT_LEASE_SECONDS=120     # must exceed the worker --timeout
PIXELFORGE_RESOLVE_UNKNOWN_MINUTES=60   # how long to keep replaying an unknown outcome
PIXELFORGE_WEBHOOK_TOLERANCE=300        # reject signatures older than this
PIXELFORGE_RECONCILE_AFTER=45           # poll a generation gone quiet this long
PIXELFORGE_SIGNUP_CREDIT_USD=5.00       # welcome credit for new accounts
```

`PIXELFORGE_MAX_IN_FLIGHT` is per Pixelforge account, not per deployment.

## 7. Verify the deployment

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
