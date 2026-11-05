<?php

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admins can configure shipping and an effective tax rate for checkout', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.shipping.methods.store'), [
            'code' => 'standard',
            'name' => '通常配送',
        ])
        ->assertRedirect(route('admin.shipping.index'));
    $method = ShippingMethod::query()->where('code', 'standard')->firstOrFail();

    $this->post(route('admin.shipping.rates.store'), [
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
    ])->assertRedirect(route('admin.shipping.index'));

    $this->post(route('admin.shipping.tax-rates.store'), [
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'shipping_taxable' => '1',
        'active_from' => today()->toDateString(),
        'active_until' => '',
        'is_active' => '1',
    ])->assertRedirect(route('admin.shipping.index'));
    $this->get(route('admin.shipping.index'))->assertOk();

    expect(ShippingRate::query()->count())->toBe(1)
        ->and(TaxRate::query()->count())->toBe(1)
        ->and(app(CheckoutService::class)->availableShippingRates())->toHaveCount(1);
});

test('admins cannot create overlapping active tax rules for a country', function () {
    $admin = User::factory()->admin()->create();
    TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'active_from' => today(),
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.shipping.tax-rates.store'), [
            'country_code' => 'JP',
            'tax_basis_points' => 800,
            'shipping_taxable' => '0',
            'active_from' => today()->addDay()->toDateString(),
            'active_until' => '',
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('active_from');

    expect(TaxRate::query()->count())->toBe(1);
});

test('shipment status cannot move backwards after dispatch', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::query()->create([
        'order_number' => 'EC260101SHIP0001',
        'idempotency_key' => str_repeat('1', 64),
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

    $this->actingAs($admin)
        ->patch(route('admin.orders.shipment.update', $order), [
            'carrier_name' => '配送会社',
            'tracking_number' => 'TRACK-001',
            'status' => 'shipped',
        ])
        ->assertRedirect(route('admin.orders.show', $order));
    $this->get(route('admin.orders.show', $order))->assertOk();

    $this->patch(route('admin.orders.shipment.update', $order), [
        'carrier_name' => '配送会社',
        'tracking_number' => 'TRACK-001',
        'status' => 'pending',
    ])->assertUnprocessable();

    expect($order->fresh()->status)->toBe('shipped')
        ->and($order->shipments()->sole()->status)->toBe('shipped');
});

test('restocking a returned item requires a refund and is idempotent', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 0,
        'reserved' => 0,
    ]);
    $order = Order::query()->create([
        'order_number' => 'EC260101RETURN01',
        'idempotency_key' => str_repeat('2', 64),
        'status' => 'refunded',
        'payment_status' => 'refunded',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '購入者',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 2000,
        'tax_minor' => 182,
        'shipping_minor' => 500,
        'total_minor' => 2500,
        'currency' => 'JPY',
    ]);
    $item = OrderItem::query()->create([
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
    InventoryMovement::query()->create([
        'variant_id' => $variant->id,
        'order_id' => $order->id,
        'movement_type' => 'sale',
        'on_hand_delta' => -2,
        'reserved_delta' => -2,
        'idempotency_key' => str_repeat('e', 64),
    ]);
    $form = [
        'quantity' => 1,
        'reason' => '返品受領・検品済み',
        'restock_key' => str_repeat('a', 64),
    ];

    $this->actingAs($admin)
        ->post(route('admin.orders.items.restock', [$order, $item]), $form)
        ->assertRedirect(route('admin.orders.show', $order));
    $this->post(route('admin.orders.items.restock', [$order, $item]), $form)
        ->assertRedirect(route('admin.orders.show', $order));

    $this->post(route('admin.orders.items.restock', [$order, $item]), [
        ...$form,
        'quantity' => 2,
        'restock_key' => str_repeat('b', 64),
    ])->assertSessionHasErrors('quantity');

    expect($balance->fresh()->on_hand)->toBe(1)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->where('movement_type', 'return_restock')->count())->toBe(1);
});
