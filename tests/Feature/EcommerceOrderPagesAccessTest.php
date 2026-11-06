<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function accessOrder(array $attributes = []): Order
{
    $order = Order::query()->create([
        'order_number' => 'EC260101'.strtoupper(bin2hex(random_bytes(4))),
        'idempotency_key' => bin2hex(random_bytes(32)),
        'status' => 'paid',
        'payment_status' => 'paid',
        'customer_name' => '購入者名前',
        'customer_email' => 'buyer-private@example.test',
        'customer_phone' => '09012345678',
        'shipping_recipient' => '受取人名前',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_region' => '東京都',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '番地一丁目1-1',
        'shipping_address_line2' => '建物名101号室',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
        ...$attributes,
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_'.$order->order_number,
        'provider_payment_id' => 'pi_secret_'.$order->order_number,
        'status' => 'succeeded',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.$order->order_number,
    ]);

    return $order;
}

function guestLinkFor(Order $order): string
{
    $url = app(CheckoutService::class)->guestOrderUrl($order);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $order->forceFill(['guest_access_token_hash' => hash('sha256', $query['signature'])])->save();

    return $url;
}

function resultPageFor(Order $order, ?string $sessionId = null): string
{
    return route('shop.checkout.success', ['orderNumber' => $order->order_number])
        .'?session_id='.($sessionId ?? 'cs_'.$order->order_number);
}

/**
 * @return array<string, array{0: bool}>
 */
function openAndClosed(): array
{
    return ['shop open' => [true], 'shop closed' => [false]];
}

test('the result page shows a payment only as recorded on the server', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $order = accessOrder(['status' => 'pending_payment', 'payment_status' => 'pending']);

    $this->get(resultPageFor($order).'&redirect_status=succeeded&payment_status=paid&status=paid')
        ->assertOk()
        ->assertSee('決済結果を確認しています')
        ->assertDontSee('お支払いを確認しました');

    expect($order->fresh()->payment_status)->toBe('pending');
})->with(openAndClosed());

test('the result page says what actually happened to the order', function (string $status, string $paymentStatus, string $message) {
    $order = accessOrder(['status' => $status, 'payment_status' => $paymentStatus]);

    $this->get(resultPageFor($order))
        ->assertOk()
        ->assertSee($message)
        ->assertDontSee('決済結果を確認しています');
})->with([
    'paid' => ['paid', 'paid', 'お支払いを確認しました'],
    'in review' => ['payment_review', 'payment_review', 'ご注文内容の確認が必要'],
    'refund under way' => ['paid', 'refund_pending', '返金の手続き中'],
    'refund being checked' => ['paid', 'refund_review', '返金内容を確認しています'],
    'refunded' => ['refunded', 'refunded', '返金済み'],
    'payment failed' => ['cancelled', 'failed', 'キャンセルされました'],
    'cancelled' => ['cancelled', 'cancelled', 'キャンセルされました'],
]);

test('the result page needs the session of that very order', function (bool $open, Closure $url) {
    config(['ecommerce.enabled' => $open]);
    $order = accessOrder();
    $other = accessOrder();

    $this->get($url($order, $other))->assertNotFound();
})->with(openAndClosed())->with([
    'no session' => [fn (Order $order) => route('shop.checkout.success', ['orderNumber' => $order->order_number])],
    'an empty session' => [fn (Order $order) => resultPageFor($order, '')],
    'a made-up session' => [fn (Order $order) => resultPageFor($order, 'cs_made_up')],
    'another order\'s session' => [fn (Order $order, Order $other) => resultPageFor($order, 'cs_'.$other->order_number)],
]);

test('the result page shows no personal details or payment references', function () {
    $order = accessOrder();

    $response = $this->get(resultPageFor($order))->assertOk();

    foreach (['購入者名前', 'buyer-private@example.test', '09012345678', '受取人名前', '1000001', '番地一丁目', '建物名', 'pi_secret_'] as $private) {
        $response->assertDontSee($private);
    }
});

test('a guest result page stops offering a link that has run out, without an error', function () {
    $order = accessOrder(['guest_access_expires_at' => now()->subDay()]);

    $this->get(resultPageFor($order))->assertOk()->assertDontSee('/orders/guest/');
});

test('a guest result page offers the link only once paid', function () {
    $paid = accessOrder(['guest_access_expires_at' => now()->addDay()]);
    $pending = accessOrder(['status' => 'pending_payment', 'payment_status' => 'pending', 'guest_access_expires_at' => now()->addDay()]);

    $this->get(resultPageFor($paid))->assertSee('/orders/guest/');
    $this->get(resultPageFor($pending))->assertDontSee('/orders/guest/');
});

test('the result page and guest link are limited and kept out of caches', function (Closure $url) {
    $order = accessOrder(['guest_access_expires_at' => now()->addDay()]);
    $target = $url($order);

    $first = $this->get($target)->assertOk();
    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($first->headers->get('X-Robots-Tag'))->toContain('noindex');

    foreach (range(2, 30) as $attempt) {
        $this->get($target)->assertOk();
    }
    $this->get($target)->assertTooManyRequests();
})->with([
    'result page' => [fn (Order $order) => resultPageFor($order)],
    'guest link' => [fn (Order $order) => guestLinkFor($order)],
]);

test('order history lists only the member\'s own orders', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $member = User::factory()->create();
    $own = accessOrder(['user_id' => $member->id]);
    $others = accessOrder(['user_id' => User::factory()->create()->id]);
    $guest = accessOrder();

    $this->actingAs($member)->get(route('account.orders.index'))
        ->assertOk()
        ->assertSee($own->order_number)
        ->assertDontSee($others->order_number)
        ->assertDontSee($guest->order_number);
})->with(openAndClosed());

test('a member cannot open an order that is not theirs', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $member = User::factory()->create();
    $others = accessOrder(['user_id' => User::factory()->create()->id]);
    $guest = accessOrder();

    $this->actingAs($member)->get(route('account.orders.show', $others))->assertNotFound();
    $this->get(route('account.orders.show', $guest))->assertNotFound();
})->with(openAndClosed());

test('order history requires an active, verified, signed-in member', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $order = accessOrder();

    $this->get(route('account.orders.index'))->assertRedirect(route('login'));
    $this->get(route('account.orders.show', $order))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('account.orders.index'))
        ->assertRedirect(route('verification.notice'));

    auth()->logout();
    $this->actingAs(User::factory()->inactive()->create())
        ->get(route('account.orders.index'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
})->with(openAndClosed());

test('a member sees their full delivery address', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $member = User::factory()->create();
    $order = accessOrder(['user_id' => $member->id]);

    $this->actingAs($member)->get(route('account.orders.show', $order))
        ->assertOk()
        ->assertSee('受取人名前')
        ->assertSee('1000001')
        ->assertSee('番地一丁目1-1')
        ->assertSee('建物名101号室');
})->with(openAndClosed());

test('a member\'s order detail, which shows their address, is kept out of caches', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $member = User::factory()->create();
    $order = accessOrder(['user_id' => $member->id]);

    $response = $this->actingAs($member)->get(route('account.orders.show', $order))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
})->with(openAndClosed());

test('a guest link shows the region only, with no name or exact address', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $order = accessOrder(['guest_access_expires_at' => now()->addDay()]);

    $response = $this->get(guestLinkFor($order))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('JP')
        ->assertSee('東京都')
        ->assertSee('千代田区')
        ->assertSee('通常配送');

    foreach (['受取人名前', '購入者名前', '1000001', '番地一丁目', '建物名', 'buyer-private@example.test', '09012345678', 'pi_secret_', 'cs_'.$order->order_number] as $private) {
        $response->assertDontSee($private);
    }
})->with(openAndClosed());

test('a guest link still shows shipment progress', function () {
    $order = accessOrder(['guest_access_expires_at' => now()->addDay()]);
    Shipment::query()->create(['order_id' => $order->id, 'status' => 'shipped', 'carrier_name' => 'ヤマト運輸', 'tracking_number' => 'A-1']);

    $this->get(guestLinkFor($order))->assertOk()->assertSee('発送済み')->assertSee('A-1');
});

test('a guest link cannot be guessed, altered or reused for another order', function (bool $open, Closure $url, int $status) {
    config(['ecommerce.enabled' => $open]);
    $order = accessOrder(['guest_access_expires_at' => now()->addDays(2)]);
    $other = accessOrder(['guest_access_expires_at' => now()->addDays(2)]);
    $valid = guestLinkFor($order);
    guestLinkFor($other);

    $this->get($url($valid, $order, $other))->assertStatus($status);
})->with(openAndClosed())->with([
    'no signature' => [fn (string $valid, Order $order) => '/orders/guest/'.$order->order_number, 403],
    'a guessed signature' => [fn (string $valid, Order $order) => '/orders/guest/'.$order->order_number.'?expires='.now()->addDay()->timestamp.'&signature='.str_repeat('a', 64), 403],
    'an altered signature' => [fn (string $valid) => substr($valid, 0, -1).(str_ends_with($valid, 'a') ? 'b' : 'a'), 403],
    'a later expiry' => [fn (string $valid) => preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $valid), 403],
    'another order\'s number' => [fn (string $valid, Order $order, Order $other) => str_replace($order->order_number, $other->order_number, $valid), 403],
]);

test('a guest link stops working when it runs out', function (bool $open) {
    config(['ecommerce.enabled' => $open]);
    $order = accessOrder(['guest_access_expires_at' => now()->addDay()]);
    $url = guestLinkFor($order);
    $this->get($url)->assertOk();

    $this->travel(2)->days();

    $this->get($url)->assertForbidden();
})->with(openAndClosed());

test('a validly signed link refused once its order\'s access date has passed', function () {
    $order = accessOrder(['guest_access_expires_at' => now()->addDay()]);
    $url = guestLinkFor($order);
    $order->forceFill(['guest_access_expires_at' => now()->subMinute()])->save();

    $this->get($url)->assertNotFound();
});

test('a signature not recorded for the order is refused', function () {
    $order = accessOrder(['guest_access_expires_at' => now()->addDay()]);
    guestLinkFor($order);
    $unrecorded = URL::temporarySignedRoute('shop.orders.guest-show', now()->addHours(2), ['orderNumber' => $order->order_number], false);

    $this->get($unrecorded)->assertNotFound();
});

test('a member order cannot be opened through a guest link', function () {
    $order = accessOrder(['user_id' => User::factory()->create()->id, 'guest_access_expires_at' => now()->addDay()]);
    $url = URL::temporarySignedRoute('shop.orders.guest-show', now()->addDay(), ['orderNumber' => $order->order_number], false);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $order->forceFill(['guest_access_token_hash' => hash('sha256', $query['signature'])])->save();

    $this->get($url)->assertNotFound();
});

test('open order pages offer no way into the closed shop', function () {
    config(['ecommerce.enabled' => false]);
    $shop = route('shop.products.index');
    $member = User::factory()->create();
    $memberOrder = accessOrder(['user_id' => $member->id]);
    $guestOrder = accessOrder(['guest_access_expires_at' => now()->addDay()]);

    $this->get(resultPageFor($guestOrder))->assertOk()->assertDontSee($shop);
    $this->get(guestLinkFor($guestOrder))->assertOk()->assertDontSee($shop);
    $this->actingAs($member)->get(route('account.orders.show', $memberOrder))->assertOk()->assertDontSee($shop);
    $this->actingAs(User::factory()->create())->get(route('account.orders.index'))->assertOk()->assertDontSee($shop);
});

test('open order pages link back to the shop while it is open', function () {
    $guestOrder = accessOrder(['guest_access_expires_at' => now()->addDay()]);

    $this->get(resultPageFor($guestOrder))->assertOk()->assertSee(route('shop.products.index'));
    $this->get(guestLinkFor($guestOrder))->assertOk()->assertSee(route('shop.products.index'));
});
