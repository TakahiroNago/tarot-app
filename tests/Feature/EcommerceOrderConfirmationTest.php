<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\Stripe\FakeStripeBillingService;

uses(RefreshDatabase::class);

const CHANGED_NOTICE = '価格・送料・税額などが、確認いただいた内容から変更されました。最新の内容をご確認のうえ、もう一度ご注文ください。';

beforeEach(function () {
    $this->sessions = FakeStripeBillingService::install(app());
    $this->user = User::factory()->create();
    $this->cart = Cart::query()->create(['user_id' => $this->user->id]);
    $this->variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published', 'name' => 'Moon Deck']))
        ->create(['price_minor' => 1100, 'label' => '標準版']);
    $this->balance = InventoryBalance::factory()->for($this->variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $this->variant->id, 'quantity' => 2]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $this->rate = ShippingRate::query()->create([
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->taxRate = TaxRate::query()->create(['country_code' => 'JP', 'tax_basis_points' => 1000, 'active_from' => today(), 'is_active' => true]);
    $this->form = [
        'checkout_key' => str_repeat('a', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'customer_phone' => '0300000000',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $this->rate->id,
    ];
    $this->actingAs($this->user);
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * @return array{quote_hash: string, quoted_total: string}
 */
function confirmedQuote(mixed $response): array
{
    preg_match('/name="quote_hash" value="([a-f0-9]{64})"/', $response->getContent(), $hash);
    preg_match('/name="quoted_total" value="(\d+)"/', $response->getContent(), $total);

    return ['quote_hash' => $hash[1], 'quoted_total' => $total[1]];
}

test('the confirmation page shows everything the buyer is agreeing to and reserves nothing', function () {
    $response = $this->post(route('shop.checkout.confirm'), $this->form)->assertOk();

    $response
        ->assertSee('Moon Deck')
        ->assertSee('標準版')
        ->assertSee('¥1,100 × 2 = ¥2,200')
        ->assertSee('¥500')
        ->assertSee('¥200')
        ->assertSee('¥2,700')
        ->assertSee('通常配送')
        ->assertSee('クレジットカード')
        ->assertSee('購入者')
        ->assertSee('buyer@example.test')
        ->assertSee('0300000000')
        ->assertSee('千代田1-1')
        ->assertSee(route('legal.terms'))
        ->assertSee(route('legal.privacy'))
        ->assertSee(route('legal.commercial-transactions'))
        ->assertSee('name="terms_accepted"', false)
        ->assertSee('data-single-submit', false)
        ->assertSee(route('shop.checkout.store'));

    expect(Order::query()->count())->toBe(0)
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and($this->sessions->createCallCount())->toBe(0);
});

test('the checkout form now leads to the confirmation page', function () {
    $this->get(route('shop.checkout.index'))
        ->assertOk()
        ->assertSee(route('shop.checkout.confirm'))
        ->assertDontSee('action="'.route('shop.checkout.store').'"', false);
});

test('details are escaped on the confirmation page', function () {
    $this->post(route('shop.checkout.confirm'), [...$this->form, 'customer_name' => '<script>alert(1)</script>'])
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

test('confirming then placing creates one order and goes to payment', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])
        ->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    $order = Order::query()->sole();
    expect($order->total_minor)->toBe(2700)
        ->and($order->tax_minor)->toBe(200)
        ->and($this->balance->fresh()->reserved)->toBe(2)
        ->and(OrderEvent::query()->where('event_type', 'terms_accepted')->where('order_id', $order->id)->count())->toBe(1);
});

test('a cart with several items placed out of order still matches its confirmation', function () {
    $second = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 3300]);
    InventoryBalance::factory()->for($second, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    CartItem::query()->delete();
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $second->id, 'quantity' => 1]);
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $this->variant->id, 'quantity' => 2]);
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])
        ->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expect(Order::query()->sole()->total_minor)->toBe(6000);
});

test('an order cannot be placed without going through the confirmation', function () {
    $this->post(route('shop.checkout.store'), [...$this->form, 'terms_accepted' => '1'])
        ->assertSessionHasErrors('quote_hash');

    expect(Order::query()->count())->toBe(0);
});

test('an order cannot be placed without agreeing to the terms', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote])
        ->assertOk()
        ->assertSee('ご注文には、利用規約と表示内容への同意が必要です。');

    expect(Order::query()->count())->toBe(0)
        ->and($this->balance->fresh()->reserved)->toBe(0);
});

test('a change is also announced when the terms were not accepted', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $this->variant->update(['price_minor' => 1200]);

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote])
        ->assertOk()
        ->assertSee('ご注文には、利用規約と表示内容への同意が必要です。')
        ->assertSee(CHANGED_NOTICE)
        ->assertSee('¥2,700 → ¥2,900');

    expect(Order::query()->count())->toBe(0);
});

test('any change after confirming stops the order and shows the new total', function (Closure $change, string $newTotal) {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $change($this);

    $response = $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])
        ->assertOk()
        ->assertSee(CHANGED_NOTICE)
        ->assertSee('¥2,700 → '.$newTotal);

    expect(Order::query()->count())->toBe(0)
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and($this->sessions->createCallCount())->toBe(0);

    $this->post(route('shop.checkout.store'), [...$this->form, ...confirmedQuote($response), 'terms_accepted' => '1'])->assertRedirect();
    expect(Order::query()->count())->toBe(1);
})->with([
    'price' => [fn (mixed $test) => $test->variant->update(['price_minor' => 1200]), '¥2,900'],
    'shipping' => [fn (mixed $test) => $test->rate->update(['fee_minor' => 900]), '¥3,100'],
    'cart quantity' => [fn (mixed $test) => CartItem::query()->update(['quantity' => 1]), '¥1,600'],
]);

test('a tax change stops the order even though the total is the same', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $this->taxRate->update(['tax_basis_points' => 800]);

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])
        ->assertOk()
        ->assertSee(CHANGED_NOTICE)
        ->assertDontSee('→ ¥');

    expect(Order::query()->count())->toBe(0);
});

test('a tax rate change stops the order even when it rounds to the same tax', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $this->taxRate->update(['tax_basis_points' => 1001]);

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])
        ->assertOk()
        ->assertSee(CHANGED_NOTICE)
        ->assertSee('¥200');

    expect(Order::query()->count())->toBe(0);
});

test('tampered details or amounts are refused', function (array $tamper) {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));

    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1', ...$tamper])
        ->assertOk()
        ->assertSee(CHANGED_NOTICE);

    expect(Order::query()->count())->toBe(0);
})->with([
    'address' => [['shipping_address_line1' => '別の住所']],
    'email' => [['customer_email' => 'other@example.test']],
    'hash' => [['quote_hash' => str_repeat('f', 64)]],
]);

test('stock running out after confirming stops the order', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $this->balance->update(['on_hand' => 1]);

    $this->from(route('shop.checkout.confirm'))
        ->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])
        ->assertSessionHasErrors('cart');

    expect(Order::query()->count())->toBe(0);
});

test('a problem found when confirming returns to the form with the input kept', function () {
    $this->balance->update(['on_hand' => 1]);

    $this->from(route('shop.checkout.confirm'))
        ->post(route('shop.checkout.confirm'), $this->form)
        ->assertRedirect(route('shop.checkout.confirm'));

    $this->get(route('shop.checkout.confirm'))->assertRedirect(route('shop.checkout.index'));

    $this->get(route('shop.checkout.index'))
        ->assertSee('商品または在庫の状態が変わりました')
        ->assertSee('value="千代田1-1"', false);
});

test('reloading the confirmation address returns to the form', function () {
    $this->get(route('shop.checkout.confirm'))->assertRedirect(route('shop.checkout.index'));
});

test('going back to edit keeps the input and the key', function () {
    $this->post(route('shop.checkout.edit'), $this->form)->assertRedirect(route('shop.checkout.index'));

    $this->get(route('shop.checkout.index'))
        ->assertSee('value="千代田1-1"', false)
        ->assertSee('value="'.$this->form['checkout_key'].'"', false);
});

test('confirming with an order already pending shows that order instead', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $this->post(route('shop.checkout.store'), [...$this->form, ...$quote, 'terms_accepted' => '1'])->assertRedirect();

    $this->post(route('shop.checkout.confirm'), $this->form)->assertRedirect(route('shop.checkout.index'));
    $this->get(route('shop.checkout.index'))->assertSee('お支払い待ちのご注文');
});

test('the same confirmed form sent twice resumes the one order', function () {
    $quote = confirmedQuote($this->post(route('shop.checkout.confirm'), $this->form));
    $confirmed = [...$this->form, ...$quote, 'terms_accepted' => '1'];

    $this->post(route('shop.checkout.store'), $confirmed)->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');
    $this->post(route('shop.checkout.store'), $confirmed)->assertRedirect('https://checkout.stripe.test/pay/cs_test_fake_1');

    expect(Order::query()->count())->toBe(1)
        ->and($this->sessions->createCallCount())->toBe(1);
});

test('the page script disables the submit button on single-submit forms', function () {
    $script = file_get_contents(resource_path('js/app.js'));

    expect($script)->toContain("querySelectorAll('[data-single-submit]')")
        ->and($script)->toContain('pageshow');
});

test('personal details never appear in a URL', function () {
    $response = $this->post(route('shop.checkout.confirm'), $this->form);
    preg_match_all('/action="([^"]+)"|href="([^"]+)"/', $response->getContent(), $urls);

    expect(implode(' ', $urls[0]))->not->toContain('buyer@example.test')
        ->and(implode(' ', $urls[0]))->not->toContain('千代田');
});
