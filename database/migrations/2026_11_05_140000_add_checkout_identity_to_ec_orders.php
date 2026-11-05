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
        Schema::table('ec_orders', function (Blueprint $table) {
            $table->char('checkout_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->foreignId('active_cart_id')->nullable()->unique()->after('checkout_fingerprint')->constrained('ec_carts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ec_orders', function (Blueprint $table) {
            $table->dropForeign(['active_cart_id']);
            $table->dropUnique(['active_cart_id']);
            $table->dropColumn(['checkout_fingerprint', 'active_cart_id']);
        });
    }
};
