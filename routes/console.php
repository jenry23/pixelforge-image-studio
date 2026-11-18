<?php

use App\Console\Commands\ReconcileGenerations;
use App\Console\Commands\VerifyLedger;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ReconcileGenerations::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(VerifyLedger::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();
