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

            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');

            $table->bigInteger('balance_delta_nanodollars');
            $table->bigInteger('hold_delta_nanodollars');

            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'type']);

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
