<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // ULID primary key: non-guessable, sortable, and a single identifier
            // we can hand to the provider in `metadata` to match webhooks back.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Persisted before the first provider request and reused by every
            // retry, so an ambiguous timeout can never create a second
            // billable generation. Unique because one generation == one key.
            $table->string('idempotency_key')->unique();

            // Null until the create response (or an early webhook) tells us the
            // provider's id. Unique so the same provider generation can never be
            // adopted by two local rows.
            $table->string('provider_id')->nullable()->unique();

            $table->string('status')->index();

            // The `created_at` of the event that last set `status`. Used with the
            // status rank to apply out-of-order events monotonically.
            $table->timestamp('status_event_at')->nullable();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // The rates this generation is billed at, fixed at creation. We always
            // cost from this snapshot, never from current pricing.
            $table->json('rates_snapshot');

            // The quoted band. Stored so the UI can show estimate vs actual, and
            // so we can audit how good our estimates were.
            $table->bigInteger('estimated_provider_min_nanodollars');
            $table->bigInteger('estimated_provider_expected_nanodollars');
            $table->bigInteger('estimated_provider_max_nanodollars');
            $table->bigInteger('estimated_charge_min_nanodollars');
            $table->bigInteger('estimated_charge_expected_nanodollars');
            $table->bigInteger('estimated_charge_max_nanodollars');

            // Credits reserved at submit, against the maximum possible charge.
            $table->bigInteger('hold_nanodollars')->default(0);

            $table->json('usage')->nullable();

            // The settled truth. Null until final; zero for a failed generation.
            $table->bigInteger('provider_cost_nanodollars')->nullable();
            $table->bigInteger('charged_nanodollars')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Retry accounting for the submit job, so the budget is durable
            // across worker restarts rather than living only in the job payload.
            // Counts real provider attempts only -- waiting on the in-flight gate
            // must not consume the retry budget.
            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->timestamp('first_submit_attempted_at')->nullable();

            // Set when a submit attempt failed ambiguously (timeout, connection
            // error, unreadable 5xx), meaning the provider may have created a
            // billable generation we never saw.
            //
            // This is the difference between "we know nothing was created, so
            // release the hold" and "we do not know, so keep it until we find
            // out" -- the decision that stops us silently losing money.
            $table->boolean('submit_outcome_unknown')->default(false);

            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();

            $table->timestamp('provider_completed_at')->nullable()->index();

            // The provider bills on the UTC day a generation *completed*, not the
            // day it was created. Derived once at settlement and stored as a plain
            // date so the admin report is a portable GROUP BY rather than a
            // dialect-specific date() expression over a timestamp -- and so there
            // is no timezone ambiguity at query time.
            $table->date('billed_on_utc')->nullable()->index();

            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);

            // Drives the provider in-flight gate and the reconciler sweep.
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
