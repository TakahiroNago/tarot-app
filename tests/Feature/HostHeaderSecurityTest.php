<?php

use App\Jobs\SendAdminOrderAlert;
use App\Jobs\SendOrderConfirmationEmail;
use App\Jobs\SendPaymentReviewNotice;
use App\Jobs\SendRefundCompletedNotice;
use App\Mail\AdminOrderAlertMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\CheckoutService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

const CANONICAL_URL = 'https://iroca.art-full-stack.com';

/**
 * The framework skips the Host allow-list while testing, so these tests run as production.
 */
beforeEach(function () {
    config(['app.url' => CANONICAL_URL, 'mail.default' => 'array', 'services.contact.email' => 'admin@example.test']);
    $this->app['env'] = 'production';
    (new AppServiceProvider($this->app))->boot();
    $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

/**
 * @return list<string>
 */
function builtMessages(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($message) => quoted_printable_decode($message->toString()))
        ->all();
}

/**
 * Every address a message links to, as written in its HTML.
 *
 * @return list<string>
 */
function linksIn(string $message): array
{
    preg_match_all('#https?://[^\s"\'<>]+#', $message, $matches);

    return array_values(array_filter(
        array_map(fn (string $url): string => html_entity_decode($url), $matches[0]),
        fn (string $url): bool => ! str_contains($url, 'w3.org'),
    ));
}

function hostOrder(array $attributes = []): Order
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
        'guest_access_expires_at' => now()->addDays(30),
        ...$attributes,
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_'.$order->order_number,
        'status' => 'succeeded',
        'amount_minor' => 1500,
        'received_amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.$order->order_number,
    ]);

    return $order;
}

test('only the exact site name is on the allow-list, without subdomains', function () {
    expect(app(TrustHosts::class)->hosts())->toBe(['^iroca\.art-full-stack\.com$'])
        ->and(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue();
});

test('a forged Host is refused before any page is served', function (string $host) {
    Exceptions::fake();

    $this->get("https://{$host}/login")->assertBadRequest();
})->with([
    'another site' => ['evil.test'],
    'the real name followed by another site' => ['iroca.art-full-stack.com.evil.test'],
    'the real name inside a longer name' => ['eviliroca.art-full-stack.com'],
    'a subdomain' => ['evil.iroca.art-full-stack.com'],
    'the parent domain' => ['art-full-stack.com'],
    'a character in place of a dot' => ['irocaXart-full-stack.com'],
    'another character in place of a dot' => ['iroca.artXfull-stack.com'],
    'an address' => ['203.0.113.10'],
    'localhost' => ['localhost'],
]);

test('every page refuses a forged Host, whatever the method', function (string $method, string $path) {
    Exceptions::fake();

    $this->call($method, 'https://evil.test'.$path)->assertBadRequest();
})->with([
    ['GET', '/'],
    ['GET', '/tarot'],
    ['GET', '/forgot-password'],
    ['GET', '/register'],
    ['GET', '/auth/google/callback'],
    ['GET', '/shop/products'],
    ['GET', '/up'],
    ['POST', '/forgot-password'],
    ['POST', '/register'],
    ['POST', '/login'],
    ['POST', '/contact'],
    ['POST', '/stripe/ecommerce/webhook'],
    ['POST', '/stripe/webhook'],
]);

test('a forged Host sends no password reset mail', function () {
    Exceptions::fake();
    User::factory()->create(['email' => 'victim@example.test']);

    $this->post('https://evil.test/forgot-password', ['email' => 'victim@example.test'])->assertBadRequest();

    expect(builtMessages())->toBe([]);
});

test('a forged Host creates no account and sends no verification mail', function () {
    Exceptions::fake();

    $this->post('https://evil.test/register', [
        'name' => 'N',
        'email' => 'new@example.test',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
    ])->assertBadRequest();

    expect(builtMessages())->toBe([])
        ->and(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});

test('a signed-in user with a forged Host cannot have a verification mail resent', function () {
    Exceptions::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('https://evil.test/email/verification-notification')->assertBadRequest();

    expect(builtMessages())->toBe([]);
});

test('an administrator with a forged Host cannot send a reset mail for a user', function () {
    Exceptions::fake();
    $target = User::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post('https://evil.test'.route('admin.users.password-reset', $target, false))
        ->assertBadRequest();

    expect(builtMessages())->toBe([]);
});

test('a forwarded host header cannot change the links', function () {
    User::factory()->create(['email' => 'user@example.test']);

    $this->withHeaders(['X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Proto' => 'http', 'Forwarded' => 'host=evil.test'])
        ->post(CANONICAL_URL.'/forgot-password', ['email' => 'user@example.test'])
        ->assertRedirect();

    expect(builtMessages()[0])->not->toContain('evil.test');
});

test('the site works normally under its real name', function (string $path) {
    $this->get(CANONICAL_URL.$path)->assertOk();
})->with(['/', '/tarot', '/login', '/register', '/forgot-password', '/terms', '/privacy', '/contact', '/up']);

test('the real name is accepted in any letter case', function () {
    $this->get('https://IROCA.Art-Full-Stack.com/login')->assertOk();
});

test('a password reset link points to the real site and opens', function () {
    $user = User::factory()->create(['email' => 'user@example.test']);

    $this->post(CANONICAL_URL.'/forgot-password', ['email' => $user->email])->assertRedirect();

    $links = linksIn(builtMessages()[0]);
    $reset = collect($links)->first(fn (string $url): bool => str_contains($url, '/reset-password/'));
    expect($reset)->toStartWith(CANONICAL_URL.'/reset-password/')
        ->and($links)->each->toStartWith(CANONICAL_URL);

    $this->get($reset)->assertOk();
});

test('a reset mail sent by an administrator points to the real site', function () {
    $target = User::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(CANONICAL_URL.route('admin.users.password-reset', $target, false))
        ->assertRedirect();

    expect(linksIn(builtMessages()[0]))->each->toStartWith(CANONICAL_URL);
});

test('a verification link from signing up points to the real site and verifies the account', function () {
    $this->post(CANONICAL_URL.'/register', [
        'name' => 'N',
        'email' => 'new@example.test',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
    ])->assertRedirect();

    $links = linksIn(builtMessages()[0]);
    $verify = collect($links)->first(fn (string $url): bool => str_contains($url, '/email/verify/'));
    expect($verify)->toStartWith(CANONICAL_URL.'/email/verify/')
        ->and($links)->each->toStartWith(CANONICAL_URL);

    $user = User::query()->where('email', 'new@example.test')->sole();
    $this->actingAs($user)->get($verify)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a resent verification mail points to the real site', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->post(CANONICAL_URL.'/email/verification-notification')
        ->assertRedirect();

    expect(linksIn(builtMessages()[0]))->each->toStartWith(CANONICAL_URL);
});

test('order mail links point to the real site', function (Closure $dispatch) {
    $order = hostOrder(['status' => 'payment_review', 'payment_status' => 'paid']);

    $dispatch($order);

    expect(builtMessages())->toHaveCount(1);
    $links = linksIn(builtMessages()[0]);
    expect($links)->not->toBe([])
        ->and($links)->each->toStartWith(CANONICAL_URL);
})->with([
    'confirmation' => [fn (Order $order) => SendOrderConfirmationEmail::dispatchSync($order->id)],
    'payment review' => [function (Order $order) {
        $order->update(['payment_status' => 'payment_review', 'review_reason' => Order::REVIEW_LATE_PAYMENT]);
        SendPaymentReviewNotice::dispatchSync($order->id);
    }],
    'refund' => [function (Order $order) {
        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);
        SendRefundCompletedNotice::dispatchSync($order->id);
    }],
    'admin alert' => [fn (Order $order) => SendAdminOrderAlert::dispatchSync($order->id, AdminOrderAlertMail::ALERT_PAYMENT_REVIEW)],
]);

test('a guest order link in mail opens on the real site', function () {
    $order = hostOrder();
    $url = app(CheckoutService::class)->guestOrderUrl($order);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $order->forceFill(['guest_access_token_hash' => hash('sha256', $query['signature'])])->save();

    expect($url)->toStartWith(CANONICAL_URL.'/orders/guest/');
    $this->get($url)->assertOk()->assertSee($order->order_number);
});

test('links keep the real site name even if a request reaches the app under another', function () {
    app('url')->setRequest(Request::create('http://evil.test/anything'));

    expect(route('login'))->toBe(CANONICAL_URL.'/login')
        ->and(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => 1, 'hash' => 'x']))->toStartWith(CANONICAL_URL.'/')
        ->and(url('/shop/products'))->toBe(CANONICAL_URL.'/shop/products');
});

test('links use https even when the address is written with http', function () {
    config(['app.url' => 'http://iroca.art-full-stack.com']);
    (new AppServiceProvider($this->app))->boot();

    expect(route('login'))->toBe(CANONICAL_URL.'/login');
});

test('the root name is left alone outside production', function () {
    $this->app['env'] = 'local';
    URL::forceRootUrl(null);
    URL::forceScheme(null);
    config(['app.url' => 'http://localhost:8000']);
    (new AppServiceProvider($this->app))->boot();

    $this->get('http://localhost:8000/login')->assertOk();
    expect(route('login'))->toBe('http://localhost:8000/login');
});

test('local development is not limited by the allow-list', function () {
    $this->app['env'] = 'local';

    $this->get('http://localhost:8000/login')->assertOk();
});
