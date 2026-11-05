<?php

use App\Jobs\SendOrderConfirmationEmail;
use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $this->rate = ShippingRate::query()->create([
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    TaxRate::query()->create(['country_code' => 'JP', 'tax_basis_points' => 1000, 'active_from' => today(), 'is_active' => true]);
    $this->variant = $variant;
});

function guestOrder(mixed $test, string $key = 'a'): Order
{
    $cart = Cart::query()->create(['guest_token_hash' => hash('sha256', 'guest-'.$key), 'expires_at' => now()->addDays(30)]);
    CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $test->variant->id, 'quantity' => 1]);

    return app(CheckoutService::class)->reserveOrder($cart, [
        'checkout_key' => str_repeat($key, 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $test->rate->id,
    ], null);
}

function onPublicHost(string $link): string
{
    $parts = parse_url($link);

    return 'https://shop.example.test'.$parts['path'].'?'.$parts['query'];
}

test('a link issued on a worker host opens on the public host', function () {
    URL::forceRootUrl('http://worker.internal');
    $order = guestOrder($this);
    $link = app(CheckoutService::class)->guestOrderUrl($order);
    URL::forceRootUrl(null);

    expect($link)->toStartWith('http://worker.internal/orders/guest/');

    $this->get(onPublicHost($link))->assertOk()->assertSee($order->order_number);
});

test('the link in the confirmation mail opens on the public host', function () {
    Mail::fake();
    $order = guestOrder($this);
    $order->update(['status' => 'paid', 'payment_status' => 'paid']);

    URL::forceRootUrl('http://worker.internal');
    (new SendOrderConfirmationEmail($order->id))->handle(app(CheckoutService::class));
    URL::forceRootUrl(null);

    $link = Mail::sent(OrderConfirmationMail::class)->sole()->guestOrderUrl;

    expect($link)->toStartWith('http://worker.internal/orders/guest/');
    $this->get(onPublicHost($link))->assertOk()->assertSee($order->order_number);
});

test('a link that was changed or has run out is refused', function (Closure $change) {
    $order = guestOrder($this);
    parse_str((string) parse_url(app(CheckoutService::class)->guestOrderUrl($order), PHP_URL_QUERY), $query);

    $path = route('shop.orders.guest-show', ['orderNumber' => $order->order_number, ...$change($query, $this)], false);

    $this->get($path)->assertForbidden();
})->with([
    'no signature' => [fn (array $query): array => ['expires' => $query['expires']]],
    'wrong signature' => [fn (array $query): array => [...$query, 'signature' => str_repeat('0', 64)]],
    'later expiry' => [fn (array $query): array => [...$query, 'expires' => $query['expires'] + 60]],
    'extra parameter' => [fn (array $query): array => [...$query, 'extra' => '1']],
    'past expiry' => [function (array $query, mixed $test): array {
        $test->travelTo(now()->addDays(31));

        return $query;
    }],
]);

test('one order link does not open another order', function () {
    $first = guestOrder($this);
    $second = guestOrder($this, 'b');
    parse_str((string) parse_url(app(CheckoutService::class)->guestOrderUrl($first), PHP_URL_QUERY), $query);

    $this->get(route('shop.orders.guest-show', ['orderNumber' => $second->order_number, ...$query], false))->assertForbidden();
});

test('a validly signed link is refused when it is not the one recorded for the order', function (array $changes) {
    $order = guestOrder($this);
    $link = app(CheckoutService::class)->guestOrderUrl($order);
    $order->forceFill($changes)->save();

    $this->get($link)->assertNotFound();
})->with([
    'no recorded token' => [['guest_access_token_hash' => null]],
    'other token' => [['guest_access_token_hash' => str_repeat('0', 64)]],
    'access closed' => [['guest_access_expires_at' => now()->subMinute()]],
]);

test('a member order cannot be opened through a guest link', function () {
    $order = guestOrder($this);
    $link = app(CheckoutService::class)->guestOrderUrl($order);
    $order->update(['user_id' => User::factory()->create()->id]);

    $this->get($link)->assertNotFound();
});

test('the guest order page and its refusals are protected', function () {
    $order = guestOrder($this);
    $link = app(CheckoutService::class)->guestOrderUrl($order);

    $responses = [
        $this->get($link)->assertOk(),
        $this->get(route('shop.orders.guest-show', ['orderNumber' => $order->order_number, 'signature' => 'x', 'expires' => 1], false))->assertForbidden(),
    ];

    foreach ($responses as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
    }
});

test('the completion page holding the guest link is protected', function () {
    $order = guestOrder($this);
    $order->update(['status' => 'paid', 'payment_status' => 'paid']);
    Payment::query()->where('order_id', $order->id)->update(['provider' => 'stripe', 'provider_checkout_id' => 'cs_guest_link']);

    $response = $this->get(route('shop.checkout.success', ['orderNumber' => $order->order_number, 'session_id' => 'cs_guest_link']))
        ->assertOk()
        ->assertSee('signature=', false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

test('ordinary shop pages are not marked as sensitive', function () {
    $response = $this->get(route('shop.products.index'))->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store')
        ->and($response->headers->has('X-Robots-Tag'))->toBeFalse();
});
