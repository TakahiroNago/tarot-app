<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TarotReadingProductRecommendation;
use App\Models\User;
use App\Services\ProductRecommendationService;
use App\Services\PublicReadingShareService;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Stripe\WebhookSignature;
use Tests\Support\Stripe\FakeStripeBillingService;

uses(RefreshDatabase::class);

function closeShop(): void
{
    config(['ecommerce.enabled' => false]);
}

function switchProduct(): Product
{
    $product = Product::factory()->create([
        'name' => 'Hidden Tarot Deck',
        'slug' => 'hidden-tarot-deck',
        'status' => 'published',
        'reading_recommendable' => true,
    ]);
    InventoryBalance::factory()
        ->for(ProductVariant::factory()->for($product)->create(), 'variant')
        ->create(['on_hand' => 3, 'reserved' => 0]);

    return $product;
}

function switchOrder(array $attributes = []): Order
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

/**
 * @return array<string, array{0: bool, 1: mixed}>
 */
function switchEnvValues(): array
{
    return [
        'not set' => [false, null],
        'empty' => [false, ''],
        'false' => [false, 'false'],
        'zero' => [false, '0'],
        'off' => [false, 'off'],
        'a typing mistake' => [false, 'ture'],
        'true' => [true, 'true'],
        'one' => [true, '1'],
    ];
}

test('the switch stays closed unless it is clearly on', function (bool $expected, ?string $value) {
    $originalEnv = $_ENV['EC_ENABLED'] ?? null;
    $originalServer = $_SERVER['EC_ENABLED'] ?? null;
    $originalPutenv = getenv('EC_ENABLED');
    unset($_ENV['EC_ENABLED'], $_SERVER['EC_ENABLED']);
    putenv('EC_ENABLED');
    if ($value !== null) {
        $_ENV['EC_ENABLED'] = $_SERVER['EC_ENABLED'] = $value;
    }

    try {
        expect((require config_path('ecommerce.php'))['enabled'])->toBe($expected);
    } finally {
        unset($_ENV['EC_ENABLED'], $_SERVER['EC_ENABLED']);
        $originalEnv === null || $_ENV['EC_ENABLED'] = $originalEnv;
        $originalServer === null || $_SERVER['EC_ENABLED'] = $originalServer;
        $originalPutenv === false || putenv('EC_ENABLED='.$originalPutenv);
    }
})->with(switchEnvValues());

test('every public shop route is behind the switch', function () {
    $publicShopRoutes = collect(Route::getRoutes()->getRoutes())->filter(function (RoutingRoute $route): bool {
        $controller = (string) $route->getActionName();

        return str_contains($controller, 'Controllers\\Shop\\');
    });

    $unprotected = $publicShopRoutes
        ->reject(fn (RoutingRoute $route): bool => in_array('ecommerce', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($publicShopRoutes->count())->toBeGreaterThanOrEqual(17)
        ->and($unprotected)->toBe([]);
});

test('nothing outside the shop is behind the switch', function () {
    $outsideTheShop = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('ecommerce', $route->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $route): bool => str_contains((string) $route->getActionName(), 'Controllers\\Shop\\'))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($outsideTheShop)->toBe([]);
});

test('a closed shop answers 404 on every route, for anyone', function (string $method, Closure $url, Closure $actor) {
    $user = User::factory()->create();
    $product = switchProduct();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $item = CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $product->variants()->first()->id, 'quantity' => 1]);
    $order = switchOrder(['user_id' => $user->id]);
    closeShop();
    if (($as = $actor($user)) !== null) {
        $this->actingAs($as);
    }

    $this->withHeaders(['Accept' => 'text/html'])
        ->call($method, $url($product, $item, $order), ['quantity' => 2, 'variant_id' => $item->variant_id])
        ->assertNotFound();

    expect($item->fresh()->quantity)->toBe(1)
        ->and(Order::query()->count())->toBe(1);
})->with([
    'product list' => ['GET', fn () => route('shop.products.index')],
    'product page' => ['GET', fn (Product $product) => route('shop.products.show', $product->slug)],
    'view cart' => ['GET', fn () => route('shop.cart.index')],
    'add to cart' => ['POST', fn () => route('shop.cart.store')],
    'change quantity' => ['PATCH', fn ($product, CartItem $item) => route('shop.cart.update', $item)],
    'remove from cart' => ['DELETE', fn ($product, CartItem $item) => route('shop.cart.destroy', $item)],
    'view checkout' => ['GET', fn () => route('shop.checkout.index')],
    'reload confirmation' => ['GET', fn () => url('/checkout/confirm')],
    'submit confirmation' => ['POST', fn () => route('shop.checkout.confirm')],
    'edit details' => ['POST', fn () => route('shop.checkout.edit')],
    'place order' => ['POST', fn () => route('shop.checkout.store')],
    'resume payment' => ['POST', fn () => route('shop.checkout.pending.resume')],
    'cancel pending order' => ['POST', fn () => route('shop.checkout.pending.cancel')],
    'payment result' => ['GET', fn ($product, $item, Order $order) => route('shop.checkout.success', ['orderNumber' => $order->order_number]).'?session_id=cs_'.$order->order_number],
    'payment cancelled' => ['GET', fn () => route('shop.checkout.cancel')],
    'order history' => ['GET', fn () => route('account.orders.index')],
    'order detail' => ['GET', fn ($product, $item, Order $order) => route('account.orders.show', $order)],
])->with([
    'guest' => [fn () => null],
    'member' => [fn (User $user) => $user],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('a valid guest order link also answers 404 while closed', function () {
    $order = switchOrder(['guest_access_expires_at' => now()->addDay()]);
    closeShop();
    $url = URL::temporarySignedRoute('shop.orders.guest-show', now()->addDay(), ['orderNumber' => $order->order_number], false);

    $this->get($url)->assertNotFound();
});

test('a suspended user is not logged out by a closed shop', function () {
    closeShop();
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)->get(route('shop.cart.index'))->assertNotFound();

    $this->assertAuthenticatedAs($user);
});

test('a closed shop answers JSON requests with 404 too, without its data', function () {
    switchProduct();
    closeShop();

    $this->getJson(route('shop.products.index'))
        ->assertNotFound()
        ->assertDontSee('Hidden Tarot Deck');
});

test('there is no separate API that could bypass the switch', function () {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api'))
        ->count();

    expect($apiRoutes)->toBe(0)
        ->and(is_file(base_path('routes/api.php')))->toBeFalse();
});

test('a closed shop does not read its tables or set a cart cookie', function () {
    Schema::rename('ec_products', 'ec_products_missing');
    Schema::rename('ec_carts', 'ec_carts_missing');
    closeShop();

    try {
        $product = $this->get(route('shop.products.show', 'anything'));
        $cart = $this->get(route('shop.cart.index'));
    } finally {
        Schema::rename('ec_products_missing', 'ec_products');
        Schema::rename('ec_carts_missing', 'ec_carts');
    }

    $product->assertNotFound();
    $cart->assertNotFound()->assertCookieMissing('ec_cart');
});

test('closing the shop takes effect at once, with nothing left over from an open page', function () {
    switchProduct();
    $this->get(route('shop.products.index'))->assertOk()->assertSee('Hidden Tarot Deck');

    closeShop();

    $this->get(route('shop.products.index'))->assertNotFound()->assertDontSee('Hidden Tarot Deck');

    config(['ecommerce.enabled' => true]);

    $this->get(route('shop.products.index'))->assertOk();
});

test('the shop pages are not marked as cacheable by shared caches', function () {
    switchProduct();

    $open = $this->get(route('shop.products.index'));
    closeShop();
    $closed = $this->get(route('shop.products.index'));

    expect($open->headers->get('Cache-Control'))->not->toContain('public')
        ->and($closed->headers->get('Cache-Control'))->not->toContain('public');
});

test('site navigation hides the shop while closed', function () {
    $user = User::factory()->create();
    closeShop();

    foreach ([null, $user] as $viewer) {
        if ($viewer !== null) {
            $this->actingAs($viewer);
        }

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('shop.products.index'))
            ->assertDontSee(route('shop.cart.index'))
            ->assertDontSee(route('account.orders.index'));
    }
});

test('site navigation shows the shop while open', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('shop.products.index'))
        ->assertSee(route('shop.cart.index'))
        ->assertSee(route('account.orders.index'));
});

test('administrators keep the shop management pages while closed', function () {
    $order = switchOrder();
    $product = switchProduct();
    closeShop();
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('home'))->assertSee(route('admin.products.index'));
    foreach ([
        route('admin.products.index'),
        route('admin.products.edit', $product),
        route('admin.categories.index'),
        route('admin.orders.index'),
        route('admin.orders.show', $order),
        route('admin.shipping.index'),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

test('non-administrators still cannot reach the management pages while closed', function () {
    closeShop();

    $this->actingAs(User::factory()->create())->get(route('admin.products.index'))->assertForbidden();
});

test('an administrator can still refund an order while closed', function () {
    Mail::fake();
    FakeStripeBillingService::install(app());
    app(StripeBillingService::class)->ecommerceClient()->fakeRefunds->willReturn('succeeded', 1500);
    $order = switchOrder();
    closeShop();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.orders.refund', $order), ['refund_note' => '公開停止中の返金'])
        ->assertRedirect(route('admin.orders.show', $order));

    expect($order->fresh()->status)->toBe('refunded')
        ->and(OrderEvent::query()->where('event_type', 'refund_requested')->sole()->actor_user_id)->toBe($admin->id);
});

test('a payment made before closing still settles through its webhook', function () {
    Mail::fake();
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_switch_test', 'services.stripe.ecommerce_secret' => 'sk_test_fake']);
    $order = switchOrder(['status' => 'pending_payment', 'payment_status' => 'pending', 'reservation_expires_at' => now()->addMinutes(30)]);
    $payment = $order->payments()->sole();
    $payment->update(['status' => 'pending', 'provider_payment_id' => null]);
    closeShop();
    $payload = json_encode([
        'id' => 'evt_switch_paid',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => $payment->provider_checkout_id,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 1500,
            'currency' => 'jpy',
            'payment_intent' => 'pi_switch',
            'client_reference_id' => $order->order_number,
            'metadata' => ['ec_order_id' => (string) $order->id, 'ec_payment_id' => (string) $payment->id],
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('stripe.ecommerce.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_switch_test'),
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload)->assertOk();

    expect($order->fresh()->payment_status)->not->toBe('pending');
});

test('the webhook still refuses a bad signature while closed', function () {
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_switch_test']);
    closeShop();

    $this->call('POST', route('stripe.ecommerce.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
        'CONTENT_TYPE' => 'application/json',
    ], content: '{}')->assertBadRequest();
});

test('a tarot reading works while closed, without a recommendation or touching the shop tables', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'An interpretation.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"category":"その他","summary":"全体に関する助言です。"}']]]]),
    ]);
    switchProduct();
    closeShop();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'A question?'])
        ->assertOk()
        ->assertSee('An interpretation.')
        ->assertViewHas('recommendedProduct', null)
        ->assertDontSee('Hidden Tarot Deck');

    expect(TarotReadingProductRecommendation::query()->count())->toBe(0)
        ->and($user->tarotReadings()->count())->toBe(1);

    $this->get(route('readings.show', $user->tarotReadings()->sole()->id))
        ->assertOk()
        ->assertSee('An interpretation.')
        ->assertDontSee('Hidden Tarot Deck');
    $this->get($response->viewData('shareUrl'))->assertOk()->assertDontSee('Hidden Tarot Deck');
});

test('a guest reading works while closed, and stays free to be checked after opening', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'A guest interpretation.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"category":"その他","summary":"全体に関する助言です。"}']]]]),
    ]);
    $product = switchProduct();
    closeShop();

    $this->post(route('tarot.draw'), ['question' => 'A guest question?'])
        ->assertOk()
        ->assertSee('A guest interpretation.')
        ->assertDontSee('Hidden Tarot Deck');
    expect(session('tarot_readings.0'))->not->toHaveKey('recommended_product_id');

    config(['ecommerce.enabled' => true]);
    $reading = session('tarot_readings.0');

    expect(app(ProductRecommendationService::class)->forSession($reading)?->is($product))->toBeTrue();
});

test('a member reading from while closed is checked once the shop opens', function () {
    $product = switchProduct();
    $reading = User::factory()->create()->tarotReadings()->create([
        'question' => 'Q', 'card' => 'The Fool', 'interpretation' => 'I',
    ]);
    $service = app(ProductRecommendationService::class);
    closeShop();

    expect($service->forReading($reading))->toBeNull()
        ->and($reading->productRecommendation()->exists())->toBeFalse();

    config(['ecommerce.enabled' => true]);

    expect($service->forReading($reading)?->is($product))->toBeTrue();
});

test('a recommendation made while open is hidden while closed, and returns on reopening', function () {
    $product = switchProduct();
    $user = User::factory()->create();
    $reading = $user->tarotReadings()->create(['question' => 'Q', 'card' => 'The Fool', 'interpretation' => 'I']);
    app(ProductRecommendationService::class)->forReading($reading);
    $token = app(PublicReadingShareService::class)->create([
        'question' => 'Q', 'card' => 'The Fool', 'interpretation' => 'I', 'recommended_product_id' => $product->id,
    ]);
    closeShop();

    $this->get(route('readings.share', ['token' => $token]))->assertOk()->assertDontSee('Hidden Tarot Deck');
    $this->actingAs($user)->get(route('readings.show', $reading->id))->assertOk()->assertDontSee('Hidden Tarot Deck');

    config(['ecommerce.enabled' => true]);

    $this->get(route('readings.show', $reading->id))->assertOk()->assertSee('Hidden Tarot Deck');
});

test('registering while closed keeps a guest reading without recording a recommendation', function () {
    $product = switchProduct();
    closeShop();

    $this->withSession(['tarot_readings' => [[
        'question' => 'Before registration?',
        'card' => 'The Fool',
        'interpretation' => 'A reading before registration.',
        'recommended_product_id' => $product->id,
    ]]])->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'closed-reader@example.com',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'closed-reader@example.com')->sole();
    expect($user->tarotReadings()->count())->toBe(1)
        ->and(TarotReadingProductRecommendation::query()->count())->toBe(0);
});

test('pages outside the shop are unaffected while closed', function () {
    closeShop();

    foreach (['home', 'tarot', 'legal.terms', 'legal.privacy', 'legal.commercial-transactions', 'contact.create', 'login', 'register'] as $name) {
        $this->get(route($name))->assertOk();
    }
});
