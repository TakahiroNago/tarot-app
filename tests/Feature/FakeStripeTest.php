<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\StripeClient;
use Tests\Support\CheckoutForm;
use Tests\Support\Stripe\FakeStripeBillingService;
use Tests\Support\Stripe\FakeStripeCheckoutSessions;
use Tests\Support\Stripe\FakeStripeClient;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function fakeSessionParams(int $expiresAt = 1900000000): array
{
    return [
        'mode' => 'payment',
        'expires_at' => $expiresAt,
        'line_items' => [[
            'price_data' => ['currency' => 'jpy', 'product_data' => ['name' => 'Deck'], 'unit_amount' => 1000],
            'quantity' => 2,
        ]],
    ];
}

test('the same key and parameters replay the same session', function () {
    $sessions = new FakeStripeCheckoutSessions;

    $first = $sessions->create(fakeSessionParams(), ['idempotency_key' => 'key']);
    $second = $sessions->create(fakeSessionParams(), ['idempotency_key' => 'key']);

    expect($second->id)->toBe($first->id)
        ->and($first->amount_total)->toBe(2000)
        ->and($sessions->sessionCount())->toBe(1)
        ->and($sessions->createCallCount())->toBe(2);
});

test('the same key with different parameters raises an idempotency error', function () {
    $sessions = new FakeStripeCheckoutSessions;
    $sessions->create(fakeSessionParams(1900000000), ['idempotency_key' => 'key']);

    expect(fn () => $sessions->create(fakeSessionParams(1900000001), ['idempotency_key' => 'key']))
        ->toThrow(IdempotencyException::class);
});

test('a lost response leaves a session that a replay recovers', function () {
    $sessions = (new FakeStripeCheckoutSessions)->loseResponseAfterStripeCreatesSession();

    expect(fn () => $sessions->create(fakeSessionParams(), ['idempotency_key' => 'key']))
        ->toThrow(ApiConnectionException::class);
    expect($sessions->sessionCount())->toBe(1);

    expect($sessions->create(fakeSessionParams(), ['idempotency_key' => 'key'])->id)->toBe('cs_test_fake_1')
        ->and($sessions->sessionCount())->toBe(1);
});

test('a request that never reached Stripe leaves nothing and may be sent with new parameters', function () {
    $sessions = (new FakeStripeCheckoutSessions)->failBeforeStripeReceivesRequest();

    expect(fn () => $sessions->create(fakeSessionParams(1900000000), ['idempotency_key' => 'key']))
        ->toThrow(ApiConnectionException::class);
    expect($sessions->sessionCount())->toBe(0);

    expect($sessions->create(fakeSessionParams(1900000001), ['idempotency_key' => 'key'])->status)->toBe('open');
});

test('a stored server error replays and creates no session', function () {
    $sessions = (new FakeStripeCheckoutSessions)->failWithStoredServerError();

    expect(fn () => $sessions->create(fakeSessionParams(), ['idempotency_key' => 'key']))
        ->toThrow(UnknownApiErrorException::class);
    expect(fn () => $sessions->create(fakeSessionParams(), ['idempotency_key' => 'key']))
        ->toThrow(UnknownApiErrorException::class);

    expect($sessions->sessionCount())->toBe(0);
});

test('a validation rejection reports its parameter and does not store the key', function () {
    $sessions = (new FakeStripeCheckoutSessions)->rejectInvalidRequest('Fake: invalid expiry.', 'expires_at');

    try {
        $sessions->create(fakeSessionParams(), ['idempotency_key' => 'key']);
        $this->fail('The rejection should have been thrown.');
    } catch (InvalidRequestException $exception) {
        expect($exception->getStripeParam())->toBe('expires_at');
    }

    expect($sessions->create(fakeSessionParams(), ['idempotency_key' => 'key'])->status)->toBe('open');
});

test('sessions can be retrieved, listed, paid, and expired', function () {
    $sessions = new FakeStripeCheckoutSessions;
    $open = $sessions->create(fakeSessionParams(), ['idempotency_key' => 'open']);
    $paid = $sessions->create(fakeSessionParams(), ['idempotency_key' => 'paid']);

    $sessions->markPaid($paid->id, 'pi_fake');

    expect($sessions->retrieve($paid->id)->payment_status)->toBe('paid')
        ->and($sessions->retrieve($paid->id)->payment_intent)->toBe('pi_fake')
        ->and($sessions->all(['status' => 'open'])->data)->toHaveCount(1)
        ->and($sessions->all()->data)->toHaveCount(2)
        ->and($sessions->expire($open->id)->status)->toBe('expired')
        ->and($sessions->retrieve($open->id)->url)->toBeNull();

    expect(fn () => $sessions->expire($paid->id))->toThrow(InvalidRequestException::class);
    expect(fn () => $sessions->retrieve('cs_missing'))->toThrow(InvalidRequestException::class);
});

test('the fake client refuses services it does not implement', function () {
    FakeStripeBillingService::install(app());

    expect(fn () => app(StripeBillingService::class)->ecommerceClient()->customers)->toThrow(LogicException::class);
});

test('the fake client refuses every SDK route to the HTTP layer', function (Closure $call) {
    FakeStripeBillingService::install(app());

    expect(fn () => $call(app(StripeBillingService::class)->ecommerceClient()))->toThrow(LogicException::class);
})->with([
    'getService' => [fn (StripeClient $client) => $client->getService('customers')],
    'request' => [fn (StripeClient $client) => $client->request('get', '/v1/customers', [], [])],
    'rawRequest' => [fn (StripeClient $client) => $client->rawRequest('get', '/v1/customers')],
    'requestStream' => [fn (StripeClient $client) => $client->requestStream('get', '/v1/files', fn () => null, [], [])],
    'requestCollection' => [fn (StripeClient $client) => $client->requestCollection('get', '/v1/customers', [], [])],
    'requestSearchResult' => [fn (StripeClient $client) => $client->requestSearchResult('get', '/v1/customers/search', [], [])],
]);

test('the fake billing service refuses methods that would build a real client', function (Closure $call) {
    FakeStripeBillingService::install(app());
    $user = User::factory()->make(['stripe_customer_id' => 'cus_fake']);

    expect(fn () => $call(app(StripeBillingService::class), $user))->toThrow(LogicException::class);
})->with([
    'customerId' => [fn (StripeBillingService $service, User $user) => $service->customerId($user)],
    'customer deletion' => [fn (StripeBillingService $service, User $user) => $service->deleteCustomerForAccountDeletion($user)],
]);

test('every public billing method except the local webhook recorder is overridden by the fake', function () {
    $publicMethods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(StripeBillingService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    $unreviewed = array_filter(
        array_diff($publicMethods, ['handleWebhook']),
        fn (string $method): bool => (new ReflectionMethod(FakeStripeBillingService::class, $method))->class !== FakeStripeBillingService::class,
    );

    expect($unreviewed)->toBe([]);
});

test('every public SDK client method is either overridden or reviewed as local only', function () {
    $reviewedAsLocalOnly = [
        'getApiKey', 'getClientId', 'getStripeAccount', 'getStripeContext', 'getStripeContextHeader', 'getStripeVersion',
        'getApiBase', 'getConnectBase', 'getFilesBase', 'getMeterEventsBase', 'getMaxNetworkRetries', 'getAppInfo',
        'deserialize', 'constructEventWithoutVerification', 'parseEventNotification',
        'parseEventNotificationWithoutVerification', 'notificationHandler', 'notificationHandlerWithoutVerification',
    ];
    $unreviewed = array_values(array_filter(
        (new ReflectionClass(FakeStripeClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method): bool => $method->class !== FakeStripeClient::class
            && ! in_array($method->getName(), $reviewedAsLocalOnly, true),
    ));

    expect(array_map(fn (ReflectionMethod $method): string => $method->getName(), $unreviewed))->toBe([]);
});

test('the fake plugs into the real checkout route', function () {
    $sessions = FakeStripeBillingService::install(app());
    $user = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 2, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $rate = ShippingRate::query()->create([
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => 1000,
        'active_from' => today(),
        'is_active' => true,
    ]);

    $this->actingAs($user)->post(route('shop.checkout.store'), CheckoutForm::confirmed([
        'checkout_key' => str_repeat('a', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $rate->id,
    ]))->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expect($sessions->createCallCount())->toBe(1)
        ->and(Order::query()->sole()->payments()->sole()->provider_checkout_id)->toBe('cs_test_fake_1');
});
