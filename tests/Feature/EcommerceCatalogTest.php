<?php

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public catalog lists published products and filters by category and search', function () {
    $category = Category::factory()->create(['slug' => 'decks']);
    $matchingProduct = Product::factory()->create([
        'name' => 'Moon Tarot Deck',
        'slug' => 'moon-tarot-deck',
        'status' => 'published',
    ]);
    $matchingProduct->categories()->attach($category);
    $matchingVariant = ProductVariant::factory()->for($matchingProduct)->create(['sku' => 'MOON-001']);
    InventoryBalance::factory()->for($matchingVariant, 'variant')->create([
        'on_hand' => 2,
        'reserved' => 0,
    ]);
    Product::factory()->create([
        'name' => 'Draft Tarot Deck',
        'status' => 'draft',
    ]);
    $outOfStockProduct = Product::factory()->create([
        'name' => 'Other Product',
        'status' => 'published',
    ]);
    $outOfStockVariant = ProductVariant::factory()->for($outOfStockProduct)->create();
    InventoryBalance::factory()->for($outOfStockVariant, 'variant')->create([
        'on_hand' => 1,
        'reserved' => 1,
    ]);

    $response = $this->get(route('shop.products.index', [
        'search' => 'Moon',
        'category' => 'decks',
    ]));

    $response->assertOk()
        ->assertSee('Moon Tarot Deck')
        ->assertSee(route('shop.products.show', 'moon-tarot-deck'))
        ->assertDontSee('Draft Tarot Deck')
        ->assertDontSee('Other Product');
});

test('public product detail uses its slug and only adds available variants to the cart', function () {
    $product = Product::factory()->create([
        'slug' => 'available-deck',
        'status' => 'published',
    ]);
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 2,
        'reserved' => 0,
    ]);

    $this->get(route('shop.products.show', 'available-deck'))
        ->assertOk()
        ->assertSee(route('shop.cart.store'))
        ->assertSee((string) $variant->id);
});

test('public product detail does not expose unpublished products', function () {
    $product = Product::factory()->create(['status' => 'draft']);

    $this->get(route('shop.products.show', $product->slug))->assertNotFound();
});

test('admin can create a product and initial inventory movement', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.products.store'), [
        'name' => 'Moon Tarot Deck',
        'slug' => 'moon-tarot-deck',
        'description' => 'A sample deck.',
        'status' => 'published',
        'sku' => 'MOON-001',
        'variant_label' => '標準',
        'price_minor' => 3200,
        'stock' => 5,
        'stock_reason' => '初回入庫',
        'category_ids' => [],
    ]);

    $product = Product::query()->where('slug', 'moon-tarot-deck')->firstOrFail();
    $variant = $product->variants()->firstOrFail();

    $response->assertRedirect(route('admin.products.edit', $product));
    expect($variant->inventoryBalance->on_hand)->toBe(5)
        ->and(InventoryMovement::query()->where('variant_id', $variant->id)->count())->toBe(1);
});

test('admin category checkbox can deactivate a category', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create(['is_active' => true]);

    $response = $this->actingAs($admin)->put(route('admin.categories.update', $category), [
        'name' => $category->name,
        'slug' => $category->slug,
        'sort_order' => $category->sort_order,
        'is_active' => '0',
    ]);

    $response->assertRedirect(route('admin.categories.index'));
    expect($category->fresh()->is_active)->toBeFalse();
});

test('cart enforces available inventory for signed-in shoppers', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 3,
        'reserved' => 1,
    ]);

    $this->actingAs($user)
        ->post(route('shop.cart.store'), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])
        ->assertRedirect(route('shop.cart.index'));

    $this->assertDatabaseHas('ec_cart_items', [
        'variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    $this->actingAs($user)
        ->post(route('shop.cart.store'), [
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])
        ->assertSessionHasErrors('quantity');
});
