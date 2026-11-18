<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('idempotency_key')->unique();

            $table->string('provider_id')->nullable()->unique();

            $table->string('status')->index();

            $table->timestamp('status_event_at')->nullable();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->json('rates_snapshot');

            $table->bigInteger('estimated_provider_min_nanodollars');
            $table->bigInteger('estimated_provider_expected_nanodollars');
            $table->bigInteger('estimated_provider_max_nanodollars');
            $table->bigInteger('estimated_charge_min_nanodollars');
            $table->bigInteger('estimated_charge_expected_nanodollars');
            $table->bigInteger('estimated_charge_max_nanodollars');

            $table->bigInteger('hold_nanodollars')->default(0);

            $table->json('usage')->nullable();

            $table->bigInteger('provider_cost_nanodollars')->nullable();
            $table->bigInteger('charged_nanodollars')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->timestamp('first_submit_attempted_at')->nullable();

            $table->boolean('submit_outcome_unknown')->default(false);

            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();

            $table->timestamp('provider_completed_at')->nullable()->index();

            $table->date('billed_on_utc')->nullable()->index();

            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
