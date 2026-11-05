<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_subscription_status')->nullable();
            $table->timestamp('stripe_current_period_end')->nullable();
            $table->timestamp('stripe_subscription_event_created_at')->nullable();
            $table->boolean('stripe_cancel_at_period_end')->default(false);
            $table->string('stripe_pending_subscription_checkout_id')->nullable()->unique();
            $table->string('stripe_pending_subscription_product')->nullable();
        });

        Schema::create('stripe_credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('checkout_session_id')->unique();
            $table->string('product_key');
            $table->string('price_id');
            $table->unsignedInteger('credits');
            $table->unsignedInteger('amount_total');
            $table->string('currency', 3);
            $table->timestamps();
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('stripe_credit_purchases');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropUnique(['stripe_pending_subscription_checkout_id']);
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_subscription_status',
                'stripe_current_period_end',
                'stripe_subscription_event_created_at',
                'stripe_cancel_at_period_end',
                'stripe_pending_subscription_checkout_id',
                'stripe_pending_subscription_product',
            ]);
        });
    }
};
