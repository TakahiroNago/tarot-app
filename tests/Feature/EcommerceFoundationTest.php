<?php

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('ecommerce schema is isolated from existing user and tarot tables', function () {
    $ecommerceTables = [
        'ec_products',
        'ec_product_variants',
        'ec_categories',
        'ec_product_images',
        'ec_inventory_balances',
        'ec_carts',
        'ec_cart_items',
        'ec_orders',
        'ec_order_items',
        'ec_payments',
        'ec_payment_webhook_events',
        'ec_tax_rates',
        'ec_order_emails',
        'ec_shipping_methods',
        'ec_shipping_rates',
        'ec_shipments',
        'ec_inventory_movements',
        'tarot_reading_product_recommendations',
    ];

    expect(collect($ecommerceTables)->every(
        fn (string $table): bool => Schema::hasTable($table),
    ))->toBeTrue();

    expect(Schema::getColumnListing('tarot_readings'))
        ->toEqualCanonicalizing([
            'id',
            'user_id',
            'question',
            'card',
            'interpretation',
            'category',
            'summary',
            'daily_reading_date',
            'created_at',
            'updated_at',
        ]);
});

test('products have variants categories and inventory balances', function () {
    $product = Product::factory()->create([
        'status' => 'published',
        'reading_recommendable' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::create([
        'variant_id' => $variant->id,
        'on_hand' => 4,
        'reserved' => 1,
    ]);
    $category = Category::factory()->create();
    $product->categories()->attach($category);

    expect($product->fresh()->variants)->toHaveCount(1)
        ->and($product->fresh()->categories->sole()->is($category))->toBeTrue()
        ->and($variant->inventoryBalance->available())->toBe(3);
});

test('order contact and delivery fields are encrypted at rest', function () {
    $order = Order::create([
        'order_number' => 'ORD-TEST-1001',
        'idempotency_key' => str_repeat('a', 64),
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'customer_name' => 'テスト購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => 'テスト購入者',
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

    $storedEmail = DB::table('ec_orders')
        ->where('id', $order->id)
        ->value('customer_email');

    expect($storedEmail)->not->toBe('buyer@example.test')
        ->and($order->fresh()->customer_email)->toBe('buyer@example.test')
        ->and($order->fresh()->shipping_postal_code)->toBe('1000001');
});
