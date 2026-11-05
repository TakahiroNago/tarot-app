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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\CheckoutForm;
use Tests\Support\Concurrency\InjectedDeadlock;
use Tests\Support\Stripe\FakeStripeBillingService;

/*
 * DatabaseMigrations, not RefreshDatabase: Laravel only retries a deadlock in an outermost
 * transaction, and RefreshDatabase would wrap every test in one.
 */
uses(DatabaseMigrations::class);

const BUSY_NOTICE = 'ご注文が混み合っています。入力内容は保持されていますので、もう一度お試しください。';

/**
 * @return array{user: User, cart: Cart, variant: ProductVariant, balance: InventoryBalance, rate: ShippingRate, taxRate: TaxRate}
 */
function reservationScenario(int $onHand = 5): array
{
    $user = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => $onHand, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $rate = ShippingRate::query()->create([
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $taxRate = TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'active_from' => today(),
        'is_active' => true,
    ]);

    return compact('user', 'cart', 'variant', 'balance', 'rate', 'taxRate');
}

/**
 * @return array<string, mixed>
 */
function reservationForm(ShippingRate $rate, string $key = 'a'): array
{
    return [
        'checkout_key' => str_repeat($key, 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $rate->id,
    ];
}

/**
 * @param  array{balance: InventoryBalance}  $scenario
 */
function expectExactlyOneReservation(array $scenario): void
{
    expect(Order::query()->count())->toBe(1)
        ->and(OrderItem::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation')->count())->toBe(1)
        ->and($scenario['balance']->fresh()->reserved)->toBe(1);
}

test('one deadlock is retried and the order succeeds without duplicate rows', function (int $deadlocks) {
    $sessions = FakeStripeBillingService::install(app());
    $scenario = reservationScenario();
    InjectedDeadlock::onPaymentCreation($deadlocks);

    $this->actingAs($scenario['user'])
        ->post(route('shop.checkout.store'), CheckoutForm::confirmed(reservationForm($scenario['rate'])))
        ->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expectExactlyOneReservation($scenario);
    expect($sessions->createCallCount())->toBe(1);
})->with(['one deadlock' => 1, 'two deadlocks' => 2]);

test('exhausted retries show the notice and keep the input and key', function () {
    $sessions = FakeStripeBillingService::install(app());
    $scenario = reservationScenario();
    $form = reservationForm($scenario['rate']);
    InjectedDeadlock::onPaymentCreation(3);

    $this->actingAs($scenario['user'])
        ->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', BUSY_NOTICE)
        ->assertSessionHasInput('checkout_key', $form['checkout_key'])
        ->assertSessionHasInput('customer_name', '購入者')
        ->assertSessionHasInput('shipping_address_line1', '千代田1-1');

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation')->count())->toBe(0)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0)
        ->and(CartItem::query()->count())->toBe(1)
        ->and($sessions->createCallCount())->toBe(0);

    $this->get(route('shop.checkout.index'))
        ->assertOk()
        ->assertSee(BUSY_NOTICE)
        ->assertSee('value="'.$form['checkout_key'].'"', false)
        ->assertSee('value="購入者"', false);
});

test('resubmitting after exhausted retries creates a single order with the original key', function () {
    FakeStripeBillingService::install(app());
    $scenario = reservationScenario();
    $form = reservationForm($scenario['rate']);
    InjectedDeadlock::onPaymentCreation(3);

    $this->actingAs($scenario['user'])->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertSessionHas('status', BUSY_NOTICE);
    $this->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expectExactlyOneReservation($scenario);
    expect(Order::query()->sole()->idempotency_key)->toBe(hash('sha256', $form['checkout_key']));
});

test('other database errors are not reported as a busy notice', function () {
    FakeStripeBillingService::install(app());
    $scenario = reservationScenario();
    $this->freezeTime();
    Str::createRandomStringsUsing(fn (): string => 'AAAAAAAA');
    Order::query()->create([
        ...reservationOrderAttributes(),
        'order_number' => 'EC'.now()->format('ymd').'AAAAAAAA',
        'idempotency_key' => str_repeat('c', 64),
    ]);

    try {
        $this->actingAs($scenario['user'])
            ->post(route('shop.checkout.store'), CheckoutForm::confirmed(reservationForm($scenario['rate'])))
            ->assertServerError();
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($scenario['balance']->fresh()->reserved)->toBe(0)
        ->and(session('status'))->toBeNull();
});

test('a unique violation other than the idempotency key is re-thrown', function (Closure $seedConflict) {
    $scenario = reservationScenario();
    $this->freezeTime();
    Str::createRandomStringsUsing(fn (): string => 'AAAAAAAA');
    $seedConflict(reservationForm($scenario['rate']));
    $ordersBefore = Order::query()->count();

    try {
        expect(fn () => app(CheckoutService::class)->reserveOrder($scenario['cart'], reservationForm($scenario['rate']), $scenario['user']))
            ->toThrow(UniqueConstraintViolationException::class);
    } finally {
        Str::createRandomStringsNormally();
    }

    expect(Order::query()->count())->toBe($ordersBefore)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0);
})->with([
    'order number' => [fn (array $form) => Order::query()->create([
        ...reservationOrderAttributes(),
        'order_number' => 'EC'.now()->format('ymd').'AAAAAAAA',
        'idempotency_key' => str_repeat('c', 64),
    ])],
    'payment idempotency key' => [fn (array $form) => Payment::query()->create([
        'order_id' => Order::query()->create([...reservationOrderAttributes(), 'order_number' => 'OTHER001', 'idempotency_key' => str_repeat('d', 64)])->id,
        'provider' => 'stripe',
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.hash('sha256', $form['checkout_key']),
    ])],
]);

test('an idempotency key violation with no order to find is not treated as success', function () {
    $scenario = reservationScenario();
    $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ec_orders.idempotency_key');
    $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: ec_orders.idempotency_key'];
    Order::creating(function () use ($previous): void {
        throw new UniqueConstraintViolationException('sqlite', 'insert into ec_orders', [], $previous);
    });

    expect(fn () => app(CheckoutService::class)->reserveOrder($scenario['cart'], reservationForm($scenario['rate']), $scenario['user']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Order::query()->count())->toBe(0);
});

test('an existing order is re-checked for its owner and status', function () {
    $scenario = reservationScenario();
    $form = reservationForm($scenario['rate']);
    $service = app(CheckoutService::class);
    $order = $service->reserveOrder($scenario['cart'], $form, $scenario['user']);

    expect($service->reserveOrder($scenario['cart'], $form, $scenario['user'])->is($order))->toBeTrue();

    expect(fn () => $service->reserveOrder($scenario['cart'], $form, User::factory()->create()))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => $service->reserveOrder($scenario['cart'], $form, null))
        ->toThrow(NotFoundHttpException::class);

    $order->update(['status' => 'cancelled']);

    expect(fn () => $service->reserveOrder($scenario['cart'], $form, $scenario['user']))
        ->toThrow(ValidationException::class);
    expectExactlyOneReservation($scenario);
});

test('admin setting changes do not alter a placed order or its Stripe amounts', function () {
    $sessions = FakeStripeBillingService::install(app())->failBeforeStripeReceivesRequest();
    $scenario = reservationScenario();
    $admin = User::factory()->admin()->create();
    $form = reservationForm($scenario['rate']);

    $this->actingAs($scenario['user'])->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect(route('shop.checkout.index'));
    $order = Order::query()->sole();
    $placed = $order->only(['shipping_minor', 'tax_minor', 'subtotal_minor', 'total_minor', 'tax_rate_basis_points']);

    $this->actingAs($admin)->put(route('admin.shipping.rates.update', $scenario['rate']), [
        'country_code' => 'JP',
        'fee_minor' => 9900,
        'is_active' => '1',
    ])->assertRedirect();
    $this->put(route('admin.shipping.tax-rates.update', $scenario['taxRate']), [
        'tax_basis_points' => 2500,
        'shipping_taxable' => '1',
        'active_from' => today()->toDateString(),
        'active_until' => '',
        'is_active' => '1',
    ])->assertRedirect();

    expect($order->fresh()->only(array_keys($placed)))->toBe($placed);

    $this->actingAs($scenario['user'])->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    $amounts = array_map(
        fn (array $item): int => $item['price_data']['unit_amount'] * $item['quantity'],
        end($sessions->createCalls)['params']['line_items'],
    );
    expect(array_sum($amounts))->toBe($placed['total_minor'])
        ->and($order->fresh()->only(array_keys($placed)))->toBe($placed)
        ->and($placed['shipping_minor'])->toBe(500)
        ->and($placed['tax_rate_basis_points'])->toBe(1000);
});

test('a saved session is reused instead of creating another', function () {
    $sessions = FakeStripeBillingService::install(app());
    $scenario = reservationScenario();
    $form = reservationForm($scenario['rate']);

    $this->actingAs($scenario['user'])->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');
    $this->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expect($sessions->createCallCount())->toBe(1)
        ->and($sessions->sessionCount())->toBe(1);
    expectExactlyOneReservation($scenario);
});

test('resending after a lost response recovers the same session instead of creating another', function () {
    $sessions = FakeStripeBillingService::install(app())->loseResponseAfterStripeCreatesSession();
    $scenario = reservationScenario();
    $form = reservationForm($scenario['rate']);

    $this->actingAs($scenario['user'])->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect(route('shop.checkout.index'));
    $this->travel(5)->seconds();
    $this->post(route('shop.checkout.store'), CheckoutForm::confirmed($form))->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expect($sessions->createCallCount())->toBe(2)
        ->and($sessions->sessionCount())->toBe(1)
        ->and(Payment::query()->sole()->provider_checkout_id)->toBe('cs_test_fake_1');
    expectExactlyOneReservation($scenario);
});

/**
 * @return array<string, mixed>
 */
function reservationOrderAttributes(): array
{
    return [
        'status' => 'cancelled',
        'payment_status' => 'failed',
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
    ];
}
