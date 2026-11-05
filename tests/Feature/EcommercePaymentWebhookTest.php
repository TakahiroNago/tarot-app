<?php

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Jobs\SendOrderConfirmationEmail;
use App\Mail\OrderConfirmationMail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Stripe\WebhookSignature;

uses(RefreshDatabase::class);

test('signed ecommerce payment webhooks are isolated and idempotently settle inventory', function () {
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_ec_test']);
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 3,
        'reserved' => 1,
    ]);
    $order = Order::query()->create([
        'order_number' => 'EC260101TEST0001',
        'idempotency_key' => str_repeat('d', 64),
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'tax_minor' => 91,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
        'reservation_expires_at' => now()->addMinutes(30),
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'product_name_snapshot' => $product->name,
        'variant_label_snapshot' => $variant->label,
        'sku_snapshot' => $variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => 1,
        'line_total_minor' => 1000,
        'currency' => 'JPY',
    ]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_ec_test',
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-webhook-test',
    ]);
    $payload = json_encode([
        'id' => 'evt_ec_paid_once',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_ec_test',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => 1500,
                'currency' => 'jpy',
                'client_reference_id' => $order->order_number,
                'payment_intent' => 'pi_ec_test',
                'metadata' => [
                    'ec_order_id' => (string) $order->id,
                    'ec_payment_id' => (string) $payment->id,
                ],
            ],
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);
    $send = fn () => $this->call(
        'POST',
        route('stripe.ecommerce.webhook'),
        server: [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_ec_test'),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    );

    $send()->assertOk();
    $send()->assertOk();

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and($payment->fresh()->provider_payment_id)->toBe('pi_ec_test')
        ->and($balance->fresh()->on_hand)->toBe(2)
        ->and($balance->fresh()->reserved)->toBe(0)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->where('movement_type', 'sale')->count())->toBe(1)
        ->and(DB::table('ec_payment_webhook_events')->where('event_id', 'evt_ec_paid_once')->count())->toBe(1)
        ->and(DB::table('stripe_webhook_events')->where('stripe_event_id', 'evt_ec_paid_once')->count())->toBe(0)
        ->and($item->fresh()->quantity)->toBe(1);
});

test('ecommerce webhook rejects invalid signatures', function () {
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_ec_test']);

    $this->call(
        'POST',
        route('stripe.ecommerce.webhook'),
        server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid', 'CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )->assertBadRequest();
});

test('late successful payment enters review without consuming another order reservation', function () {
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_ec_test']);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 5,
        'reserved' => 1,
    ]);
    $order = Order::query()->create([
        'order_number' => 'EC260101LATE0001',
        'idempotency_key' => str_repeat('4', 64),
        'status' => 'cancelled',
        'payment_status' => 'failed',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'tax_minor' => 91,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'product_name_snapshot' => $product->name,
        'variant_label_snapshot' => $variant->label,
        'sku_snapshot' => $variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => 1,
        'line_total_minor' => 1000,
        'currency' => 'JPY',
    ]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_ec_late',
        'status' => 'failed',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-late-payment',
    ]);
    $payload = json_encode([
        'id' => 'evt_ec_late_paid',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'cs_ec_late',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1500,
            'currency' => 'jpy',
            'client_reference_id' => $order->order_number,
            'payment_intent' => 'pi_ec_late',
            'metadata' => [
                'ec_order_id' => (string) $order->id,
                'ec_payment_id' => (string) $payment->id,
            ],
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('stripe.ecommerce.webhook'),
        server: [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_ec_test'),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    )->assertOk();

    expect($order->fresh()->status)->toBe('payment_review')
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and($balance->fresh()->on_hand)->toBe(5)
        ->and($balance->fresh()->reserved)->toBe(1)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->where('movement_type', 'sale')->count())->toBe(0);
});

test('full refund webhook confirms refund without restoring stock automatically', function () {
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_ec_test']);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 2,
        'reserved' => 0,
    ]);
    $order = Order::query()->create([
        'order_number' => 'EC260101REFUND01',
        'idempotency_key' => str_repeat('5', 64),
        'status' => 'shipped',
        'payment_status' => 'refund_pending',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'tax_minor' => 91,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
    ]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_payment_id' => 'pi_ec_refund',
        'status' => 'refund_pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-refund-webhook',
    ]);
    $payload = json_encode([
        'id' => 'evt_ec_refund_succeeded',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 're_ec_test',
            'object' => 'refund',
            'payment_intent' => 'pi_ec_refund',
            'amount' => 1500,
            'currency' => 'jpy',
            'status' => 'succeeded',
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'refund.updated',
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('stripe.ecommerce.webhook'),
        server: [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_ec_test'),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    )->assertOk();

    expect($order->fresh()->status)->toBe('refunded')
        ->and($order->fresh()->payment_status)->toBe('refunded')
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($balance->fresh()->on_hand)->toBe(2)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->where('movement_type', 'return_restock')->count())->toBe(0);
});

test('order confirmation email delivery is idempotent', function () {
    $order = Order::query()->create([
        'order_number' => 'EC260101EMAIL0001',
        'idempotency_key' => str_repeat('3', 64),
        'status' => 'paid',
        'payment_status' => 'paid',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'tax_minor' => 91,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
    ]);
    Mail::fake();
    $job = new SendOrderConfirmationEmail($order->id);

    $job->handle(app(App\Services\CheckoutService::class));
    $job->handle(app(App\Services\CheckoutService::class));

    Mail::assertSent(OrderConfirmationMail::class, 1);
    expect(DB::table('ec_order_emails')->where('order_id', $order->id)->value('status'))->toBe('sent')
        ->and(DB::table('ec_order_emails')->where('order_id', $order->id)->value('attempts'))->toBe(1);
});
