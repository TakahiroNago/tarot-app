<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('customers can view only their own order history and details', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $order = Order::query()->create([
        'order_number' => 'ORD-TEST-2001',
        'user_id' => $customer->id,
        'idempotency_key' => str_repeat('b', 64),
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => 'テスト配送',
        'subtotal_minor' => 1200,
        'tax_minor' => 0,
        'shipping_minor' => 500,
        'total_minor' => 1700,
        'currency' => 'JPY',
    ]);

    $this->actingAs($customer)
        ->get(route('account.orders.index'))
        ->assertOk()
        ->assertSee($order->order_number);

    $this->actingAs($otherCustomer)
        ->get(route('account.orders.show', $order))
        ->assertNotFound();
});

test('guest order details require a signed unexpired order link', function () {
    $expiresAt = now()->addDays(30);
    $order = Order::query()->create([
        'order_number' => 'EC260101GUEST001',
        'idempotency_key' => str_repeat('f', 64),
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
        'guest_access_expires_at' => $expiresAt,
    ]);
    $url = URL::temporarySignedRoute(
        'shop.orders.guest-show',
        $expiresAt,
        ['orderNumber' => $order->order_number],
    );
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $order->guest_access_token_hash = hash('sha256', $query['signature']);
    $order->save();

    $this->get($url)->assertOk()->assertSee($order->order_number);
    $this->get(route('shop.orders.guest-show', [
        'orderNumber' => $order->order_number,
        'signature' => str_repeat('0', 64),
        'expires' => $expiresAt->timestamp,
    ]))->assertForbidden();
});
