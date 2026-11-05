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
        Schema::create('ec_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('guest_token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('ec_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('ec_carts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('ec_product_variants')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id']);
        });

        Schema::create('ec_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 30)->default('pending_payment')->index();
            $table->string('payment_status', 30)->default('pending')->index();
            $table->text('customer_name');
            $table->text('customer_email');
            $table->text('customer_phone')->nullable();
            $table->text('shipping_recipient');
            $table->char('shipping_country_code', 2);
            $table->text('shipping_postal_code');
            $table->text('shipping_region')->nullable();
            $table->text('shipping_city');
            $table->text('shipping_address_line1');
            $table->text('shipping_address_line2')->nullable();
            $table->string('shipping_method_name', 120);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3)->default('JPY');
            $table->unsignedSmallInteger('tax_rate_basis_points')->nullable();
            $table->timestamp('reservation_expires_at')->nullable()->index();
            $table->char('guest_access_token_hash', 64)->nullable()->unique();
            $table->timestamp('guest_access_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ec_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ec_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('ec_products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('ec_product_variants')->nullOnDelete();
            $table->string('product_name_snapshot', 255);
            $table->string('variant_label_snapshot', 255);
            $table->string('sku_snapshot', 100);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedSmallInteger('tax_rate_basis_points')->nullable();
            $table->char('currency', 3)->default('JPY');
            $table->timestamps();
        });

        Schema::create('ec_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ec_orders')->cascadeOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('provider_checkout_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_status', 40)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('JPY');
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('ec_payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 255);
            $table->string('event_type', 120);
            $table->string('status', 20)->default('processing');
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('ec_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->unsignedSmallInteger('tax_basis_points');
            $table->boolean('shipping_taxable')->default(false);
            $table->date('active_from');
            $table->date('active_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country_code', 'active_from']);
        });

        Schema::create('ec_order_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ec_orders')->cascadeOnDelete();
            $table->string('email_type', 40);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'email_type']);
        });

        Schema::create('tarot_reading_product_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarot_reading_id')->unique()->constrained('tarot_readings')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('ec_products')->restrictOnDelete();
            $table->timestamp('selected_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarot_reading_product_recommendations');
        Schema::dropIfExists('ec_order_emails');
        Schema::dropIfExists('ec_tax_rates');
        Schema::dropIfExists('ec_payment_webhook_events');
        Schema::dropIfExists('ec_payments');
        Schema::dropIfExists('ec_order_items');
        Schema::dropIfExists('ec_orders');
        Schema::dropIfExists('ec_cart_items');
        Schema::dropIfExists('ec_carts');
    }
};
