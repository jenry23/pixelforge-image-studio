# Image Studio

Image Studio generates images with [Pixelforge](https://pixelforge.promptedit.dev/). Choose a prompt, size, quality, and optional reference images to see a price estimate before submitting. History shows live status, image previews, and the final charge. Admins can reconcile provider costs against Pixelforge usage and export the billing report.

Built with Laravel 13, Vue 3, Inertia 2, and Tailwind CSS 4. Tests use Pest and Playwright.

![Generate page](docs/screenshots/generate.png)

## Local setup

From the project root, install dependencies and prepare the local environment.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

Set these values in `.env`. See [DEPLOY.md](DEPLOY.md) for where to get them.

```dotenv
PIXELFORGE_API_KEY=pf_…
PIXELFORGE_WEBHOOK_SECRET=whsec_…
```

Keep local and production on separate API keys. Pixelforge reports usage per key, so sharing one mixes the charges that each environment is trying to reconcile.

```bash
php artisan migrate --seed
npm run build
```

Start each process in its own terminal.

| Command | Purpose |
|---|---|
| `php artisan serve --port=3000` | Serves the app at [localhost:3000](http://localhost:3000) |
| `php artisan queue:work` | Submits generations to Pixelforge |
| `php artisan schedule:work` | Runs reconciliation every minute |

### Demo accounts

All three seeded accounts use the password `password`. They are seeded on the
deployed demo too, so reviewers can sign in and look around. For anything real,
create an admin with `php artisan pixelforge:create-admin`, which prompts for a
password instead, and do not seed.

| Email | Access | Starting credit |
|---|---|---:|
| `admin@example.com` | Admin billing report | $20 |
| `user@example.com` | Generate and History | $5 |
| `broke@example.com` | Test insufficient credit | $0 |

### Local webhooks

Expose the running app with a tunnel.

```bash
cloudflared tunnel --url http://127.0.0.1:3000
```

In the Pixelforge dashboard, register `https://<tunnel-host>/webhooks/pixelforge` on your local key. If a webhook is missed, the scheduled reconciler can still poll for the result and settle the generation.

## Pricing and credit

The app shows an expected charge and a range because input text and reasoning usage can vary. It reserves credit at the top of the range before submission. On success, it settles the actual Pixelforge cost plus 25% and frees the unused credit. A confirmed failure releases the hold without a charge.

Money is stored as integer nanodollars. One dollar is one billion nanodollars. In the live verification, the app calculated `$0.011707500` for a generation and Pixelforge returned the same value from `GET /v1/usage`. See [docs/VERIFICATION.md](docs/VERIFICATION.md).

![Billing report](docs/screenshots/billing.png)

## Retries and recovery

The app stores an idempotency key before submission and reuses it on retries. An atomic attempt claim prevents two workers from claiming the same generation at once. The local in-flight count helps reduce pressure. Pixelforge enforces the provider limit, and the worker honors its `Retry-After` response.

Provider updates follow the same settlement path through `ApplyProviderState`. A unique event ID deduplicates webhook deliveries, and a unique ledger entry per generation and transaction type prevents duplicate charges. An older processing event cannot reopen a settled generation. A webhook that arrives before the submit response can match the local generation ID in its metadata.

If the provider outcome is unknown, the app keeps the hold while it retries. After an hour, automatic replays stop and the generation becomes `unresolved`. A later success webhook can still settle it.

```bash
php artisan pixelforge:resolve-generation       # List unresolved generations
php artisan pixelforge:resolve-generation <id>  # Look up a generation without submitting
```

Use `--submit` only when you intend to resend the request. If Pixelforge never received the original request, resending can create a new billable generation. Use `--release` only after confirming that no provider generation exists. The command checks for a matching generation before releasing. See the operator runbook in [docs/ENGINEERING.md](docs/ENGINEERING.md).

## Checks

```bash
./vendor/bin/pest
npm run typecheck
npx playwright test --project=ui
php artisan pixelforge:verify-ledger
```

The ledger check compares cached balances against the ledger and checks for duplicate charges. The opt-in harness at `tests/concurrency/run.sh` runs separate worker processes against a shared SQLite database.

To run the live browser suite, configure a real local Pixelforge key and start the queue worker and webhook tunnel.

```bash
PIXELFORGE_LIVE=1 npx playwright test --project=live
```

## Where to look

| Path | Responsibility |
|---|---|
| `app/Pixelforge/` | Provider client, signatures, token usage, and pricing |
| `app/Actions/` | Generation creation, provider state updates, and ledger writes |
| `app/Jobs/` | Submission and recovery jobs |
| `app/Reporting/` | Billing report and provider usage reconciliation |
| `resources/js/` | Vue pages and shared components |
| [DEPLOY.md](DEPLOY.md) | Deployment and production configuration |
| [docs/DESIGN.md](docs/DESIGN.md) | Design decisions |
| [docs/ENGINEERING.md](docs/ENGINEERING.md) | Recovery procedures and accounting rules |
