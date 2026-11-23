<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres doesn't index foreign keys by itself, and every page sums a user's
     * purchases for their balance.
     */
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
