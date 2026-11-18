<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // THE deduplication invariant. Pixelforge delivers at least once and
            // may deliver the same event twice (the [duplicate] keyword forces
            // it). Making this unique means a replay fails at the database rather
            // than relying on an application-level check that could race.
            $table->string('event_id')->unique();

            $table->string('event_type')->index();

            // Resolved target. Nullable because a validly-signed event may arrive
            // for a generation we cannot match yet; we still record and
            // acknowledge it, and the reconciler applies it later.
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_generation_id')->nullable()->index();

            $table->json('payload');

            // The `t` from the signature header, kept for audit.
            $table->unsignedInteger('signature_timestamp');
            $table->unsignedSmallInteger('delivery_attempt')->default(1);

            // Set once the event actually drove a state transition. Null means
            // recorded but not yet applied -- the reconciler's work queue.
            $table->timestamp('applied_at')->nullable();

            // Why an event changed nothing: 'out_of_order', 'unmatched',
            // 'already_final'. Makes duplicate/stale deliveries auditable instead
            // of silently discarded.
            $table->string('ignored_reason')->nullable();

            $table->timestamp('received_at');
            $table->timestamps();

            // The reconciler's sweep for recorded-but-unapplied events.
            $table->index(['applied_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
