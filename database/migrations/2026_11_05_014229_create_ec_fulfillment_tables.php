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
        Schema::create('ec_shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ec_shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('ec_shipping_methods')->restrictOnDelete();
            $table->char('country_code', 2);
            $table->unsignedBigInteger('fee_minor');
            $table->char('currency', 3)->default('JPY');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['shipping_method_id', 'country_code']);
        });

        Schema::create('ec_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ec_orders')->restrictOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('ec_shipping_methods')->nullOnDelete();
            $table->string('carrier_name', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('ec_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('ec_product_variants')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('ec_orders')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('movement_type', 30);
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->char('idempotency_key', 64)->unique();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['variant_id', 'created_at']);
            $table->index(['order_id', 'movement_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ec_inventory_movements');
        Schema::dropIfExists('ec_shipments');
        Schema::dropIfExists('ec_shipping_rates');
        Schema::dropIfExists('ec_shipping_methods');
    }
};
