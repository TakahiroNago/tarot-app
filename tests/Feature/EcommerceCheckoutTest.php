<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('checkout snapshots order data, calculates inclusive tax per line, and reserves stock', function () {
    $cart = Cart::query()->create([
        'guest_token_hash' => hash('sha256', 'guest-cart'),
        'expires_at' => now()->addDays(30),
    ]);
    $product = Product::factory()->create([
        'name' => 'Moon Deck',
        'status' => 'published',
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'MOON-100',
        'label' => '標準版',
        'price_minor' => 1100,
    ]);
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 5,
        'reserved' => 1,
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
    ]);
    $shippingMethod = ShippingMethod::query()->create([
        'code' => 'standard',
        'name' => '通常配送',
        'is_active' => true,
    ]);
    $shippingRate = ShippingRate::query()->create([
        'shipping_method_id' => $shippingMethod->id,
        'country_code' => 'JP',
        'fee_minor' => 550,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'shipping_taxable' => true,
        'active_from' => today(),
        'is_active' => true,
    ]);
    $data = [
        'checkout_key' => str_repeat('a', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => 'お届け先',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $shippingRate->id,
    ];

    $order = app(CheckoutService::class)->reserveOrder($cart, $data, null);
    $guestOrderUrl = app(CheckoutService::class)->guestOrderUrl($order);
    parse_str((string) parse_url($guestOrderUrl, PHP_URL_QUERY), $guestQuery);

    expect($order->subtotal_minor)->toBe(2200)
        ->and($order->tax_minor)->toBe(250)
        ->and($order->shipping_minor)->toBe(550)
        ->and($order->total_minor)->toBe(2750)
        ->and($order->items()->sole()->product_name_snapshot)->toBe('Moon Deck')
        ->and($order->customer_email)->toBe('buyer@example.test')
        ->and($order->guest_access_token_hash)->toBe(hash('sha256', $guestQuery['signature']))
        ->and($balance->fresh()->reserved)->toBe(3)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->sole()->reserved_delta)->toBe(2);
});

test('checkout idempotency does not create duplicate orders or reservations', function () {
    $cart = Cart::query()->create([
        'guest_token_hash' => hash('sha256', 'idempotent-cart'),
        'expires_at' => now()->addDays(30),
    ]);
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create(['price_minor' => 1000]);
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 4]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
    ]);
    $shippingMethod = ShippingMethod::query()->create([
        'code' => 'standard',
        'name' => '通常配送',
        'is_active' => true,
    ]);
    $shippingRate = ShippingRate::query()->create([
        'shipping_method_id' => $shippingMethod->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'active_from' => today(),
        'is_active' => true,
    ]);
    $data = [
        'checkout_key' => str_repeat('b', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $shippingRate->id,
    ];
    $service = app(CheckoutService::class);

    $firstOrder = $service->reserveOrder($cart, $data, null);
    $secondOrder = $service->reserveOrder($cart, $data, null);

    expect($secondOrder->is($firstOrder))->toBeTrue()
        ->and(Order::query()->count())->toBe(1)
        ->and(OrderItem::query()->count())->toBe(1)
        ->and($balance->fresh()->reserved)->toBe(2);
});

test('checkout refuses to reserve inventory when destination tax is not configured', function () {
    $cart = Cart::query()->create([
        'guest_token_hash' => hash('sha256', 'taxless-cart'),
        'expires_at' => now()->addDays(30),
    ]);
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create(['price_minor' => 1000]);
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 3]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
    ]);
    $shippingMethod = ShippingMethod::query()->create([
        'code' => 'standard',
        'name' => '通常配送',
        'is_active' => true,
    ]);
    $shippingRate = ShippingRate::query()->create([
        'shipping_method_id' => $shippingMethod->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    expect(fn () => app(CheckoutService::class)->reserveOrder($cart, [
        'checkout_key' => str_repeat('c', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $shippingRate->id,
    ], null))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0)
        ->and($balance->fresh()->reserved)->toBe(0);
});

test('scheduled reservation cleanup releases stock exactly once', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 3,
        'reserved' => 2,
    ]);
    $order = Order::query()->create([
        'order_number' => 'EC260101EXPIRE001',
        'idempotency_key' => str_repeat('e', 64),
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
        'reservation_expires_at' => now()->subSecond(),
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'product_name_snapshot' => $product->name,
        'variant_label_snapshot' => $variant->label,
        'sku_snapshot' => $variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => 2,
        'line_total_minor' => 2000,
        'currency' => 'JPY',
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-expiration-test',
    ]);

    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);
    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($balance->fresh()->reserved)->toBe(0)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->where('movement_type', 'reservation_release')->count())->toBe(1);
});

test('checkout remains unavailable until shipping and tax rules are configured', function () {
    $user = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create();
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
    ]);

    $this->actingAs($user)->get(route('shop.checkout.index'))
        ->assertOk()
        ->assertSee('配送先・送料・税ルールが未設定');

    $this->post(route('shop.checkout.store'), [
        'checkout_key' => str_repeat('9', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => 1,
    ])->assertSessionHasErrors('shipping_country_code');
});

test('checkout does not reserve stock when Stripe is not configured', function () {
    config()->set('services.stripe.secret', null);

    $user = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 2]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
    ]);
    $shippingMethod = ShippingMethod::query()->create([
        'code' => 'standard',
        'name' => '通常配送',
        'is_active' => true,
    ]);
    $shippingRate = ShippingRate::query()->create([
        'shipping_method_id' => $shippingMethod->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'active_from' => today(),
        'is_active' => true,
    ]);

    $this->actingAs($user)->post(route('shop.checkout.store'), [
        'checkout_key' => str_repeat('d', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $shippingRate->id,
    ])->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status')
        ->assertSessionHasInput('checkout_key', str_repeat('d', 64));

    expect(Order::query()->count())->toBe(0)
        ->and($balance->fresh()->reserved)->toBe(0);
});
