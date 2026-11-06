<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function guestCart(mixed $expiresAt): Cart
{
    return Cart::query()->create([
        'guest_token_hash' => bin2hex(random_bytes(32)),
        'expires_at' => $expiresAt,
    ]);
}

test('expired guest carts and their items are deleted', function () {
    $expired = guestCart(now()->subMinute());
    $variant = ProductVariant::factory()->for(Product::factory()->create())->create();
    CartItem::query()->create(['cart_id' => $expired->id, 'variant_id' => $variant->id, 'quantity' => 1]);

    $this->artisan('ecommerce:prune-carts')->expectsOutput('Deleted 1 expired carts.')->assertSuccessful();

    expect(Cart::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(CartItem::query()->count())->toBe(0);
});

test('live guest carts and member carts are kept', function () {
    $live = guestCart(now()->addMinute());
    $member = Cart::query()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->subYear()]);

    $this->artisan('ecommerce:prune-carts')->expectsOutput('Deleted 0 expired carts.')->assertSuccessful();

    expect(Cart::query()->whereKey([$live->id, $member->id])->count())->toBe(2);
});

test('an expired cart with an order awaiting payment is kept', function () {
    $cart = guestCart(now()->subMinute());
    $order = Order::query()->create([
        'order_number' => 'ECPRUNECART001',
        'idempotency_key' => str_repeat('p', 64),
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'active_cart_id' => $cart->id,
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
    ]);

    $this->artisan('ecommerce:prune-carts')->assertSuccessful();

    expect($order->fresh()->active_cart_id)->toBe($cart->id);
});

test('the cleanup runs daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'ecommerce:prune-carts'));

    expect($event->expression)->toBe('30 3 * * *');
});
