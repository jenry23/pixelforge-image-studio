<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null for top-ups, which belong to the user rather than a generation.
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();

            // topup | hold | release | charge
            $table->string('type');

            // Signed deltas. `available = sum(balance_delta) - sum(hold_delta)`.
            //   topup   balance +A, hold  0
            //   hold    balance  0, hold +H
            //   release balance  0, hold -H
            //   charge  balance -C, hold -H
            $table->bigInteger('balance_delta_nanodollars');
            $table->bigInteger('hold_delta_nanodollars');

            $table->string('description')->nullable();
            $table->timestamps();

            // THE single-charge invariant. One hold, one release and one charge
            // per generation, enforced by the database -- so a webhook racing a
            // poll cannot double-bill even if both pass every application check.
            // Top-ups carry a null generation_id, and every supported engine
            // treats nulls as distinct, so they are unconstrained.
            $table->unique(['generation_id', 'type']);

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
