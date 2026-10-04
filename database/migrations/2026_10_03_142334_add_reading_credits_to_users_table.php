<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('free')->after('is_active');
            $table->unsignedSmallInteger('daily_credits')->default(0)->after('plan');
            $table->date('daily_credits_refreshed_at')->nullable()->after('daily_credits');
            $table->unsignedInteger('purchased_credits')->default(0)->after('daily_credits_refreshed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'plan',
                'daily_credits',
                'daily_credits_refreshed_at',
                'purchased_credits',
            ]);
        });
    }
};
