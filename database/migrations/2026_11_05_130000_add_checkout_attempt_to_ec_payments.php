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
        Schema::table('ec_payments', function (Blueprint $table) {
            $table->string('checkout_state', 20)->default('not_started')->after('provider_status');
            $table->unsignedSmallInteger('checkout_attempt')->default(1)->after('checkout_state');
            $table->timestamp('checkout_attempt_started_at')->nullable()->after('checkout_attempt');
            $table->timestamp('checkout_claimed_at')->nullable()->after('checkout_attempt_started_at');
            $table->text('checkout_request_payload')->nullable()->after('checkout_claimed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ec_payments', function (Blueprint $table) {
            $table->dropColumn([
                'checkout_state',
                'checkout_attempt',
                'checkout_attempt_started_at',
                'checkout_claimed_at',
                'checkout_request_payload',
            ]);
        });
    }
};
