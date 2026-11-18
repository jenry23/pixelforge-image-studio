<?php

use App\Console\Commands\ReconcileGenerations;
use App\Console\Commands\VerifyLedger;
use Illuminate\Support\Facades\Schedule;

/*
 * Reconciliation runs every minute.
 *
 * Webhook delivery "isn't guaranteed", so this is what stops a lost event
 * stranding a generation -- and, because credits are reserved up front, a user's
 * balance along with it. `withoutOverlapping` keeps a slow pass from stacking.
 */
Schedule::command(ReconcileGenerations::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * A daily proof that the cached balances still match the append-only ledger and
 * that nothing has been charged twice. Exits non-zero on a discrepancy, so it
 * can be alerted on.
 */
Schedule::command(VerifyLedger::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();
