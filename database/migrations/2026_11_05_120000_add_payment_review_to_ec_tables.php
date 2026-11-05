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
            $table->string('review_reason', 40)->nullable()->after('payment_status');
            $table->timestamp('review_opened_at')->nullable()->after('review_reason');
            $table->timestamp('review_resolved_at')->nullable()->after('review_opened_at');
            $table->foreignId('review_resolved_by')->nullable()->after('review_resolved_at')->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable()->after('review_resolved_by');
        });

        Schema::table('ec_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('received_amount_minor')->nullable()->after('currency');
            $table->char('received_currency', 3)->nullable()->after('received_amount_minor');
        });

        Schema::create('ec_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ec_orders')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('from_payment_status', 30)->nullable();
            $table->string('to_payment_status', 30)->nullable();
            $table->string('reason', 40)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ec_order_events');

        Schema::table('ec_payments', function (Blueprint $table) {
            $table->dropColumn(['received_amount_minor', 'received_currency']);
        });

        Schema::table('ec_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_resolved_by');
            $table->dropColumn(['review_reason', 'review_opened_at', 'review_resolved_at', 'review_note']);
        });
    }
};
