<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->user = User::factory()->create();
    $this->cart = Cart::query()->create(['user_id' => $this->user->id]);
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 2200]);
    InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $this->rate = ShippingRate::query()->create([
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 0,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->form = [
        'checkout_key' => str_repeat('a', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $this->rate->id,
    ];
});

function jpTaxRate(int $basisPoints, string $from, ?string $until = null): TaxRate
{
    return TaxRate::query()->create([
        'country_code' => 'JP',
        'tax_basis_points' => $basisPoints,
        'active_from' => $from,
        'active_until' => $until,
        'is_active' => true,
    ]);
}

/**
 * ¥2,200 including tax holds ¥163 at 8% and ¥200 at 10%.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function taxChangeMoments(): array
{
    return [
        'one second before midnight in Japan' => ['2030-03-31 14:59:59', '¥163'],
        'midnight in Japan' => ['2030-03-31 15:00:00', '¥200'],
        'one second after midnight in Japan' => ['2030-03-31 15:00:01', '¥200'],
        'midnight in UTC, already the new date in Japan' => ['2030-04-01 00:00:00', '¥200'],
    ];
}

test('a rate change takes effect at midnight in Japan', function (string $utc, string $expectedTax) {
    jpTaxRate(800, '2020-01-01', '2030-03-31');
    jpTaxRate(1000, '2030-04-01');
    $this->travelTo(Carbon::parse($utc, 'UTC'));

    $this->actingAs($this->user)
        ->post(route('shop.checkout.confirm'), $this->form)
        ->assertOk()
        ->assertSee($expectedTax);
})->with(taxChangeMoments());

test('a country can be sold to from midnight in Japan on the first day of its rate', function () {
    jpTaxRate(1000, '2030-04-01');
    $service = app(CheckoutService::class);

    $this->travelTo(Carbon::parse('2030-03-31 14:59:59', 'UTC'));
    expect($service->availableShippingRates())->toHaveCount(0);

    $this->travelTo(Carbon::parse('2030-03-31 15:00:00', 'UTC'));
    expect($service->availableShippingRates())->toHaveCount(1);
});

test('a rate covers its last day until midnight in Japan', function () {
    jpTaxRate(1000, '2020-01-01', '2030-03-31');
    $service = app(CheckoutService::class);

    $this->travelTo(Carbon::parse('2030-03-31 14:59:59', 'UTC'));
    expect($service->availableShippingRates())->toHaveCount(1);

    $this->travelTo(Carbon::parse('2030-03-31 15:00:00', 'UTC'));
    expect($service->availableShippingRates())->toHaveCount(0);
});

test('an order cannot use a rate that ended at midnight in Japan', function () {
    jpTaxRate(1000, '2020-01-01', '2030-03-31');
    $this->travelTo(Carbon::parse('2030-03-31 15:00:00', 'UTC'));

    $this->actingAs($this->user)
        ->post(route('shop.checkout.confirm'), $this->form)
        ->assertSessionHasErrors('shipping_country_code');
});

test('the date is taken in Japan whatever timezone the application runs in', function () {
    jpTaxRate(800, '2020-01-01', '2030-03-31');
    jpTaxRate(1000, '2030-04-01');
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');

    try {
        $this->travelTo(Carbon::parse('2030-03-31 15:00:00', 'UTC'));

        $this->actingAs($this->user)
            ->post(route('shop.checkout.confirm'), $this->form)
            ->assertOk()
            ->assertSee('¥200');
    } finally {
        date_default_timezone_set('UTC');
    }
});

test('the application itself keeps its date and time in UTC', function () {
    jpTaxRate(1000, '2020-01-01');
    $this->travelTo(Carbon::parse('2030-03-31 15:00:00', 'UTC'));

    app(CheckoutService::class)->availableShippingRates();

    expect(config('app.timezone'))->toBe('UTC')
        ->and(today()->toDateString())->toBe('2030-03-31')
        ->and(date_default_timezone_get())->toBe('UTC');
});
