<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Held for the duration of one submit attempt. A worker that exits
            // cleanly clears it; one that is killed mid-request leaves it set,
            // which is how we learn the provider outcome is unknown.
            $table->timestamp('submit_claimed_at')->nullable()->after('submit_attempts');

            $table->index(['status', 'provider_id', 'submit_claimed_at'], 'generations_submit_claim_index');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex('generations_submit_claim_index');
            $table->dropColumn('submit_claimed_at');
        });
    }
};
