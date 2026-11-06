<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, item: CartItem}
 */
function suspendedUserWithCart(): array
{
    $user = User::factory()->inactive()->create();
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $item = CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);

    return compact('user', 'item');
}

test('a suspended user is logged out of every cart and checkout route', function (string $method, Closure $url) {
    ['user' => $user, 'item' => $item] = suspendedUserWithCart();

    $this->actingAs($user)
        ->call($method, $url($item), ['quantity' => 3, 'variant_id' => $item->variant_id])
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($item->fresh()->quantity)->toBe(1)
        ->and(CartItem::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(0);
})->with([
    'view cart' => ['GET', fn () => route('shop.cart.index')],
    'add to cart' => ['POST', fn () => route('shop.cart.store')],
    'change quantity' => ['PATCH', fn (CartItem $item) => route('shop.cart.update', $item)],
    'remove from cart' => ['DELETE', fn (CartItem $item) => route('shop.cart.destroy', $item)],
    'view checkout' => ['GET', fn () => route('shop.checkout.index')],
    'reload confirmation' => ['GET', fn () => url('/checkout/confirm')],
    'submit confirmation' => ['POST', fn () => route('shop.checkout.confirm')],
    'edit details' => ['POST', fn () => route('shop.checkout.edit')],
    'place order' => ['POST', fn () => route('shop.checkout.store')],
    'resume payment' => ['POST', fn () => route('shop.checkout.pending.resume')],
    'cancel pending order' => ['POST', fn () => route('shop.checkout.pending.cancel')],
]);

test('guests and active users can still reach the cart and checkout', function (?Closure $user) {
    if ($user !== null) {
        $this->actingAs($user());
    }

    $this->get(route('shop.cart.index'))->assertOk();
    $this->get(route('shop.checkout.index'))->assertOk();
})->with([
    'guest' => [null],
    'active user' => [fn () => User::factory()->create()],
]);

test('a user suspended after paying can still see the result of that payment', function () {
    $user = User::factory()->inactive()->create();
    $order = Order::query()->create([
        'order_number' => 'ECSUSPENDED0001',
        'idempotency_key' => bin2hex(random_bytes(32)),
        'user_id' => $user->id,
        'status' => 'paid',
        'payment_status' => 'paid',
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
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_suspended',
        'status' => 'succeeded',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-suspended',
    ]);

    $this->actingAs($user)
        ->get(route('shop.checkout.success', ['orderNumber' => $order->order_number]).'?session_id=cs_suspended')
        ->assertOk()
        ->assertSee($order->order_number);
});
