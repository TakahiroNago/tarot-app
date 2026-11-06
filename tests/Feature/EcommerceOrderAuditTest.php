<?php

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Stripe\FakeStripeBillingService;
use Tests\Support\Stripe\FakeStripeRefunds;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function auditedOrder(array $attributes = []): Order
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
        'provider_payment_id' => 'pi_'.$order->order_number,
        'status' => 'succeeded',
        'amount_minor' => 1500,
        'received_amount_minor' => 1500,
        'received_currency' => 'JPY',
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.$order->order_number,
    ]);

    return $order;
}

function auditFakeRefunds(): FakeStripeRefunds
{
    FakeStripeBillingService::install(app());

    return app(StripeBillingService::class)->ecommerceClient()->fakeRefunds;
}

/**
 * @return list<string>
 */
function recordedEvents(Order $order): array
{
    return $order->events()->orderBy('id')->pluck('event_type')->all();
}

test('updating a shipment records who did it, without the delivery details', function () {
    $order = auditedOrder();

    $this->actingAs($this->admin)
        ->patch(route('admin.orders.shipment.update', $order), [
            'status' => 'shipped',
            'carrier_name' => 'ヤマト運輸',
            'tracking_number' => 'TRACK-SECRET-123',
        ])
        ->assertRedirect(route('admin.orders.show', $order));

    $event = OrderEvent::query()->where('order_id', $order->id)->sole();
    expect($event->event_type)->toBe('shipment_updated')
        ->and($event->actor_user_id)->toBe($this->admin->id)
        ->and($event->from_status)->toBe('paid')
        ->and($event->to_status)->toBe('shipped')
        ->and($event->note)->toBe('shipment pending -> shipped; changed: carrier_name, tracking_number')
        ->and($event->note)->not->toContain('TRACK-SECRET-123');
});

test('correcting a tracking number on a shipped order is recorded', function () {
    $order = auditedOrder();
    $this->actingAs($this->admin);
    $form = ['status' => 'shipped', 'carrier_name' => 'ヤマト運輸', 'tracking_number' => 'A-1'];
    $this->patch(route('admin.orders.shipment.update', $order), $form);

    $this->patch(route('admin.orders.shipment.update', $order), [...$form, 'tracking_number' => 'A-2']);

    expect($order->events()->orderByDesc('id')->first()->note)->toBe('shipment shipped -> shipped; changed: tracking_number');
});

test('saving a shipment without changes adds nothing to the history', function () {
    $order = auditedOrder();
    $this->actingAs($this->admin);
    $form = ['status' => 'shipped', 'carrier_name' => 'ヤマト運輸', 'tracking_number' => 'A-1'];
    $this->patch(route('admin.orders.shipment.update', $order), $form);

    $this->patch(route('admin.orders.shipment.update', $order), $form)
        ->assertRedirect(route('admin.orders.show', $order));

    expect(recordedEvents($order))->toBe(['shipment_updated']);
});

test('a rejected shipment update records nothing', function () {
    $order = auditedOrder(['status' => 'payment_review', 'payment_status' => 'payment_review']);

    $this->actingAs($this->admin)
        ->patch(route('admin.orders.shipment.update', $order), ['status' => 'shipped'])
        ->assertUnprocessable();

    expect(recordedEvents($order))->toBe([]);
});

test('a completed refund names the admin who requested it', function () {
    auditFakeRefunds()->willReturn('succeeded', 1500);
    $order = auditedOrder();

    $this->actingAs($this->admin)
        ->post(route('admin.orders.refund', $order), ['refund_note' => 'お客様都合のキャンセル'])
        ->assertRedirect(route('admin.orders.show', $order));

    $request = $order->events()->where('event_type', 'refund_requested')->sole();
    expect(recordedEvents($order))->toBe(['refund_requested', 'refund_completed'])
        ->and($request->actor_user_id)->toBe($this->admin->id)
        ->and($request->from_payment_status)->toBe('paid')
        ->and($request->note)->toBe('お客様都合のキャンセル');
});

test('a refund without a valid reason never reaches Stripe', function (array $form) {
    $refunds = auditFakeRefunds()->willReturn('succeeded', 1500);
    $order = auditedOrder();

    $this->actingAs($this->admin)
        ->post(route('admin.orders.refund', $order), $form)
        ->assertSessionHasErrors('refund_note');

    expect($refunds->createCalls)->toBe([])
        ->and(recordedEvents($order))->toBe([])
        ->and($order->fresh()->payment_status)->toBe('paid');
})->with([
    'missing' => [[]],
    'only spaces' => [['refund_note' => '   ']],
    'too long' => [['refund_note' => str_repeat('あ', 501)]],
]);

test('the refund form asks for a reason', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.orders.show', auditedOrder()))
        ->assertOk()
        ->assertSee('name="refund_note"', false);
});

test('a refund Stripe has not finished records the move to refund pending', function () {
    auditFakeRefunds()->willReturn('pending', 1500);
    $order = auditedOrder();

    $this->actingAs($this->admin)->post(route('admin.orders.refund', $order), ['refund_note' => 'お客様都合のキャンセル']);

    $pending = $order->events()->where('event_type', 'refund_pending')->sole();
    expect(recordedEvents($order))->toBe(['refund_requested', 'refund_pending'])
        ->and($pending->actor_user_id)->toBe($this->admin->id)
        ->and($pending->from_payment_status)->toBe('paid')
        ->and($pending->to_payment_status)->toBe('refund_pending');
});

test('a refund that does not go through records why', function (Closure $arrange, string $reason) {
    $arrange(auditFakeRefunds());
    $order = auditedOrder();

    $this->actingAs($this->admin)->post(route('admin.orders.refund', $order), ['refund_note' => 'お客様都合のキャンセル']);

    $failure = $order->events()->where('event_type', 'refund_request_failed')->sole();
    expect(recordedEvents($order))->toBe(['refund_requested', 'refund_request_failed'])
        ->and($failure->actor_user_id)->toBe($this->admin->id)
        ->and($failure->reason)->toBe($reason)
        ->and($order->fresh()->payment_status)->toBe('paid');
})->with([
    'Stripe could not be reached' => [fn (FakeStripeRefunds $refunds) => $refunds->willFail(new RuntimeException('down')), 'stripe_error'],
    'Stripe refunded another amount' => [fn (FakeStripeRefunds $refunds) => $refunds->willReturn('succeeded', 1), 'amount_mismatch'],
    'Stripe declined the refund' => [fn (FakeStripeRefunds $refunds) => $refunds->willReturn('failed', 1500), 'stripe_refund_failed'],
]);

test('an order that cannot be refunded records no request', function () {
    auditFakeRefunds();
    $order = auditedOrder(['status' => 'refunded', 'payment_status' => 'refunded']);

    $this->actingAs($this->admin)
        ->post(route('admin.orders.refund', $order), ['refund_note' => 'お客様都合のキャンセル'])
        ->assertSessionHasErrors('refund');

    expect(recordedEvents($order))->toBe([]);
});

test('the order page shows the history in order, including automatic events', function () {
    $order = auditedOrder();
    OrderEvent::query()->create(['order_id' => $order->id, 'event_type' => 'first_automatic_event', 'to_status' => 'paid']);
    OrderEvent::query()->create(['order_id' => $order->id, 'actor_user_id' => $this->admin->id, 'event_type' => 'second_admin_event', 'to_status' => 'paid']);

    $this->actingAs($this->admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSeeInOrder(['first_automatic_event', '自動処理または削除済みユーザー', 'second_admin_event', $this->admin->email]);
});

test('an order with no events says so', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.orders.show', auditedOrder()))
        ->assertOk()
        ->assertSee('操作履歴はありません。');
});
