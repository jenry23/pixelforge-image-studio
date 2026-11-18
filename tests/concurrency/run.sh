#!/usr/bin/env bash
# Opt-in parallel worker harness. See README.md. Not run by CI.
set -euo pipefail

MODE="${1:-both}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export DB_DATABASE="$WORK/concurrency.sqlite"
export DB_BUSY_TIMEOUT=10000
touch "$DB_DATABASE"

cat > "$WORK/boot.php" <<PHP
<?php
require '$ROOT/vendor/autoload.php';
\$app = require '$ROOT/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
PHP

php "$ROOT/artisan" migrate --force --quiet
# WAL lets readers and writers overlap the way a real database server would.
php -r '$p = new PDO("sqlite:".getenv("DB_DATABASE")); $p->exec("PRAGMA journal_mode=WAL");'

barrier() { php -r 'echo microtime(true) + 3.0;'; }

run_claim() {
    echo "== claim: 12 workers, 1 generation =="
    php "$WORK/seed_claim.php"
    local at; at="$(barrier)"
    for _ in $(seq 1 12); do php "$WORK/worker_claim.php" "$at" & done
    wait
    php "$WORK/report_claim.php"
}

run_capacity() {
    echo "== capacity: 8 workers, 8 generations, PIXELFORGE_MAX_IN_FLIGHT=3 =="
    php "$WORK/seed_capacity.php"
    local at; at="$(barrier)"
    for i in 0 1 2 3 4 5 6 7; do
        PIXELFORGE_MAX_IN_FLIGHT=3 php "$WORK/worker_capacity.php" "$at" "$i" &
    done
    wait
    php "$WORK/report_capacity.php"
}

cat > "$WORK/seed_claim.php" <<'PHP'
<?php
require __DIR__.'/boot.php';
use App\Models\User; use App\Models\Generation; use App\Enums\GenerationStatus;
$u = User::factory()->create(['balance_nanodollars' => 50000000000]);
$g = Generation::factory()->create([
    'user_id' => $u->id, 'status' => GenerationStatus::Pending,
    'provider_id' => null, 'submit_attempts' => 0, 'submit_claimed_at' => null,
]);
file_put_contents(__DIR__.'/gid.txt', $g->getKey());
PHP

cat > "$WORK/worker_claim.php" <<'PHP'
<?php
require __DIR__.'/boot.php';
use App\Jobs\SubmitGeneration;
$gid = trim(file_get_contents(__DIR__.'/gid.txt'));
while (microtime(true) < (float) $argv[1]) { usleep(200); }
$claim = new ReflectionMethod(SubmitGeneration::class, 'claim');
echo $claim->invoke(new SubmitGeneration($gid), 6) ? "CLAIMED\n" : "refused\n";
PHP

cat > "$WORK/report_claim.php" <<'PHP'
<?php
require __DIR__.'/boot.php';
$g = App\Models\Generation::find(trim(file_get_contents(__DIR__.'/gid.txt')));
echo "-> submit_attempts={$g->submit_attempts} (expected 1)\n\n";
PHP

cat > "$WORK/seed_capacity.php" <<'PHP'
<?php
require __DIR__.'/boot.php';
use App\Models\User; use App\Models\Generation; use App\Enums\GenerationStatus;
$u = User::factory()->create(['balance_nanodollars' => 50000000000]);
$ids = [];
for ($i = 0; $i < 8; $i++) {
    $ids[] = Generation::factory()->create([
        'user_id' => $u->id, 'status' => GenerationStatus::Pending,
        'provider_id' => null, 'submit_attempts' => 0, 'submit_claimed_at' => null,
    ])->getKey();
}
file_put_contents(__DIR__.'/cap_ids.txt', implode("\n", $ids));
PHP

cat > "$WORK/worker_capacity.php" <<'PHP'
<?php
require __DIR__.'/boot.php';
use App\Jobs\SubmitGeneration; use App\Models\Generation; use App\Enums\GenerationStatus;
$ids = array_values(array_filter(explode("\n", trim(file_get_contents(__DIR__.'/cap_ids.txt')))));
$gid = $ids[(int) $argv[2]];
while (microtime(true) < (float) $argv[1]) { usleep(200); }
$job = new SubmitGeneration($gid);
$atCapacity = new ReflectionMethod(SubmitGeneration::class, 'providerIsAtCapacity');
$claim = new ReflectionMethod(SubmitGeneration::class, 'claim');
if ($atCapacity->invoke($job)) { echo "held-back\n"; exit; }
if (! $claim->invoke($job, 6)) { echo "lost-claim\n"; exit; }
// Stand in for a successful submit putting this generation in flight.
Generation::query()->whereKey($gid)->update([
    'status' => GenerationStatus::Queued->value, 'provider_id' => 'gen_'.$gid,
]);
echo "SUBMITTED\n";
PHP

cat > "$WORK/report_capacity.php" <<'PHP'
<?php
require __DIR__.'/boot.php';
$n = App\Models\Generation::query()->inFlightAtProvider()->count();
echo "-> in flight={$n} against a cap of 3 (advisory: 8 is the expected result)\n\n";
PHP

case "$MODE" in
    claim) run_claim ;;
    capacity) run_capacity ;;
    both) run_claim; run_capacity ;;
    *) echo "usage: run.sh [claim|capacity|both]" >&2; exit 64 ;;
esac
