<?php

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('inventory adjustments update the balance and append a movement', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 3,
        'reserved' => 1,
    ]);

    $updated = app(InventoryService::class)->setOnHand($variant, 6, $admin, '入庫');

    expect($updated->on_hand)->toBe(6)
        ->and($updated->reserved)->toBe(1)
        ->and(InventoryMovement::query()->where('variant_id', $variant->id)->sole()->on_hand_delta)->toBe(3);
});

test('inventory cannot be adjusted below reserved quantity', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 3,
        'reserved' => 2,
    ]);

    expect(fn () => app(InventoryService::class)->setOnHand($variant, 1, $admin, '棚卸し'))
        ->toThrow(ValidationException::class);

    expect($variant->inventoryBalance->fresh()->on_hand)->toBe(3)
        ->and(InventoryMovement::query()->where('variant_id', $variant->id)->count())->toBe(0);
});
