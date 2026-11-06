<?php

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\DisplayTime;
use App\Support\OrderStatusLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function displayedOrder(array $attributes = []): Order
{
    $order = Order::query()->create([
        'order_number' => 'EC260101'.strtoupper(bin2hex(random_bytes(4))),
        'idempotency_key' => bin2hex(random_bytes(32)),
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
        ...$attributes,
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_'.$order->order_number,
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.$order->order_number,
    ]);

    return $order;
}

test('customer pages show every order and payment status in Japanese', function (string $status, string $paymentStatus) {
    $user = User::factory()->create();
    $order = displayedOrder(['user_id' => $user->id, 'status' => $status, 'payment_status' => $paymentStatus]);
    Shipment::query()->create(['order_id' => $order->id, 'status' => 'shipped']);
    $guestOrder = displayedOrder(['status' => $status, 'payment_status' => $paymentStatus, 'guest_access_expires_at' => now()->addDay()]);
    $orderLabel = OrderStatusLabel::ORDER[$status];
    $paymentLabel = OrderStatusLabel::PAYMENT[$paymentStatus];

    $pages = [
        $this->actingAs($user)->get(route('account.orders.index'))->assertSee($orderLabel),
        $this->get(route('account.orders.show', $order))->assertSee($orderLabel)->assertSee($paymentLabel)->assertSee('発送済み'),
        $this->get(route('shop.checkout.success', ['orderNumber' => $order->order_number]).'?session_id=cs_'.$order->order_number)
            ->assertSee($orderLabel)->assertSee($paymentLabel),
    ];
    auth()->logout();
    $guestUrl = app(CheckoutService::class)->guestOrderUrl($guestOrder);
    parse_str((string) parse_url($guestUrl, PHP_URL_QUERY), $guestQuery);
    $guestOrder->forceFill(['guest_access_token_hash' => hash('sha256', $guestQuery['signature'])])->save();
    $pages[] = $this->get($guestUrl)->assertSee($orderLabel)->assertSee($paymentLabel);

    foreach ($pages as $page) {
        $page->assertOk();
        expect(strip_tags($page->getContent()))->not->toMatch('/\b'.$status.'\b/')
            ->and(strip_tags($page->getContent()))->not->toMatch('/\b'.$paymentStatus.'\b/');
    }
})->with([
    'awaiting payment' => ['pending_payment', 'pending'],
    'settled' => ['paid', 'paid'],
    'preparing' => ['processing', 'paid'],
    'shipped' => ['shipped', 'paid'],
    'completed' => ['completed', 'paid'],
    'cancelled' => ['cancelled', 'failed'],
    'cancelled by status' => ['cancelled', 'cancelled'],
    'under review' => ['payment_review', 'payment_review'],
    'refund pending' => ['paid', 'refund_pending'],
    'refund in review' => ['paid', 'refund_review'],
    'refund pending as an order status' => ['refund_pending', 'refund_pending'],
    'refunded' => ['refunded', 'refunded'],
]);

test('a status without a label never reaches customers as the internal value', function () {
    expect(OrderStatusLabel::order('new_internal_status'))->toBe('確認中')
        ->and(OrderStatusLabel::payment('new_internal_status'))->toBe('確認中')
        ->and(OrderStatusLabel::shipment('new_internal_status'))->toBe('確認中');
});

test('administrators see the internal value of an unlisted event or reason', function () {
    expect(OrderStatusLabel::event('new_event'))->toBe('new_event')
        ->and(OrderStatusLabel::reason('new_reason'))->toBe('new_reason');
});

test('order pages show times in the display timezone', function () {
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $order = displayedOrder(['user_id' => $user->id]);
    $order->forceFill(['created_at' => Carbon::parse('2026-01-01 16:30:00', 'UTC')])->save();
    OrderEvent::query()->create(['order_id' => $order->id, 'event_type' => 'refund_requested'])
        ->forceFill(['created_at' => Carbon::parse('2026-01-01 17:45:00', 'UTC')])->save();

    $this->actingAs($user)->get(route('account.orders.index'))->assertSee('2026年01月02日');
    $this->get(route('account.orders.show', $order))->assertSee('2026年01月02日 01:30');

    $this->actingAs($admin)->get(route('admin.orders.index'))->assertSee('2026-01-02 01:30');
    $this->get(route('admin.orders.show', $order))
        ->assertSee('2026-01-02 01:30')
        ->assertSee('2026-01-02 02:45');
});

test('the reservation deadline on checkout is shown in the display timezone', function () {
    $user = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    displayedOrder([
        'user_id' => $user->id,
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'active_cart_id' => $cart->id,
        'reservation_expires_at' => Carbon::parse('2030-03-01 15:10:00', 'UTC'),
    ]);

    $this->actingAs($user)->get(route('shop.checkout.index'))->assertSee('2030年03月02日 00:10');
});

test('formatting for display leaves the stored time untouched', function () {
    $time = Carbon::parse('2026-01-01 16:30:00', 'UTC');

    expect(DisplayTime::format($time, 'H:i'))->toBe('01:30')
        ->and($time->format('H:i'))->toBe('16:30')
        ->and($time->timezoneName)->toBe('UTC')
        ->and(DisplayTime::format(null, 'H:i'))->toBe('');
});

test('the display timezone does not change how the application stores time', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Asia/Tokyo');
});

test('the admin order page uses labels, and leaves empty history states blank', function () {
    $order = displayedOrder(['status' => 'payment_review', 'payment_status' => 'payment_review', 'review_reason' => Order::REVIEW_LATE_PAYMENT]);
    OrderEvent::query()->create(['order_id' => $order->id, 'event_type' => 'terms_accepted']);

    $page = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('ご注文内容の確認中')
        ->assertSee('予約解放後の支払い')
        ->assertSee('規約への同意')
        ->assertSee('注文 — → —');

    expect($page->getContent())->not->toContain('<dd>payment_review</dd>');
});

test('the admin filter shows labels and still filters by the stored value', function () {
    $paid = displayedOrder();
    $review = displayedOrder(['status' => 'payment_review', 'payment_status' => 'payment_review']);
    $this->actingAs(User::factory()->admin()->create());

    $filters = $this->get(route('admin.orders.index'))->getContent();
    expect($filters)->toMatch('/<option value="payment_review"\s*>ご注文内容の確認中</u')
        ->and($filters)->toMatch('/<option value="payment_review"\s*>お支払いの確認中</u');

    $this->get(route('admin.orders.index', ['status' => 'payment_review']))
        ->assertSee($review->order_number)
        ->assertDontSee($paid->order_number);

    foreach (array_keys(OrderStatusLabel::ORDER) as $status) {
        $this->get(route('admin.orders.index', ['status' => $status]))->assertOk();
    }
    foreach (array_keys(OrderStatusLabel::PAYMENT) as $status) {
        $this->get(route('admin.orders.index', ['payment_status' => $status]))->assertOk();
    }
});
