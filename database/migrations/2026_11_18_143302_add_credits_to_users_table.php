<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');

            // Materialised cache of the append-only ledger, written in the same
            // transaction as the ledger row. The ledger is the source of truth;
            // `pixelforge:verify-ledger` asserts these agree with it, so drift is
            // detectable rather than theoretical.
            $table->bigInteger('balance_nanodollars')->default(0)->after('is_admin');
            $table->bigInteger('held_nanodollars')->default(0)->after('balance_nanodollars');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'balance_nanodollars', 'held_nanodollars']);
        });
    }
};
