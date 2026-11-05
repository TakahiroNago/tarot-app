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
use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concurrency\CheckoutScenario;
use Tests\Support\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Support\Concurrency\ConcurrencyHarness;
use Tests\Support\Concurrency\ConcurrencyTestCase;
use Tests\Support\Stripe\FakeStripeBillingService;

uses(ConcurrencyTestCase::class);

beforeEach(function () {
    ConcurrencyHarness::rebuildSchema();
});

/**
 * @return array{variant: ProductVariant, balance: InventoryBalance}
 */
function concurrencyStockedVariant(int $onHand): array
{
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 0, 'reserved' => 0]);
    app(InventoryService::class)->setOnHand($variant, $onHand, User::factory()->create(), 'concurrency test stock');

    return ['variant' => $variant, 'balance' => $balance->refresh()];
}

test('the suite is connected to the disposable InnoDB database and did not load a dotenv file', function () {
    $server = DB::selectOne('SELECT DATABASE() AS name, @@default_storage_engine AS engine, VERSION() AS version');
    $tableEngine = DB::selectOne(
        'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['ec_inventory_balances'],
    );

    expect($server->name)->toBe(ConcurrencyDatabaseGuard::REQUIRED_DATABASE)
        ->and(strtolower($server->engine))->toBe('innodb')
        ->and(strtolower($tableEngine->engine))->toBe('innodb')
        ->and(env('CONCURRENCY_ENV_FILE_SENTINEL'))->toBeNull()
        ->and(config('services.stripe.secret'))->toBeEmpty();
});

test('the schema is not rebuilt when unknown tables exist without the marker', function () {
    Schema::dropIfExists(ConcurrencyDatabaseGuard::MARKER_TABLE);

    try {
        expect(fn () => ConcurrencyHarness::rebuildSchema())
            ->toThrow(RuntimeException::class, 'without the harness marker');

        expect(Schema::hasTable('users'))->toBeTrue();
    } finally {
        Schema::create(ConcurrencyDatabaseGuard::MARKER_TABLE, function (Blueprint $table): void {
            $table->id();
        });
    }
});

test('a worker exits before connecting when its environment names another target', function (array $override) {
    $process = ConcurrencyHarness::runWorkerExpectedToRefuseTarget([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '33307',
        'DB_DATABASE' => 'tarot_ec_concurrency_test',
        'DB_URL' => '',
        'DB_SOCKET' => '',
        ...$override,
    ]);

    expect($process->getExitCode())->toBe(3)
        ->and($process->getErrorOutput())->toContain('Refusing to use this database');
})->with([
    'existing local MySQL port' => [['DB_PORT' => '3306']],
    'application database name' => [['DB_DATABASE' => 'tarot_app']],
    'remote host' => [['DB_HOST' => 'db.example.com']],
    'sqlite driver' => [['DB_CONNECTION' => 'sqlite']],
    'connection URL' => [['DB_URL' => 'mysql://user:pass@db.example.com/tarot']],
]);

test('read and write host overrides in the resolved configuration are refused', function () {
    config()->set('database.connections.mysql.read', ['host' => ['db.example.com']]);

    expect(fn () => ConcurrencyHarness::assertCurrentTargetIsSafe())
        ->toThrow(RuntimeException::class, 'read and write hosts');
});

test('workers run in parallel and row locks prevent lost updates', function () {
    ['variant' => $variant, 'balance' => $balance] = concurrencyStockedVariant(0);
    $workerCount = 6;
    $iterations = 40;

    $results = ConcurrencyHarness::run(
        'increment-on-hand',
        array_fill(0, $workerCount, ['variant_id' => $variant->id, 'iterations' => $iterations]),
    );

    expect($results)->toHaveCount($workerCount)
        ->and(max(array_column($results, 'started_at')))->toBeLessThan(min(array_column($results, 'finished_at')))
        ->and(array_unique(array_column($results, 'env_file_loaded')))->toBe([false])
        ->and(array_unique(array_column($results, 'stripe_service')))->toBe([FakeStripeBillingService::class])
        ->and($balance->fresh()->on_hand)->toBe($workerCount * $iterations);
});

test('concurrent reservations against the last unit of stock never oversell', function () {
    ['variant' => $variant, 'balance' => $balance] = concurrencyStockedVariant(1);
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

    $payloads = [];
    foreach (range(0, 7) as $index) {
        $cart = Cart::query()->create([
            'guest_token_hash' => hash('sha256', "concurrency-cart-{$index}"),
            'expires_at' => now()->addDay(),
        ]);
        CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $payloads[] = [
            'cart_id' => $cart->id,
            'data' => [
                'checkout_key' => hash('sha256', "concurrency-key-{$index}"),
                'customer_name' => '購入者',
                'customer_email' => 'buyer@example.test',
                'shipping_recipient' => '宛名',
                'shipping_country_code' => 'JP',
                'shipping_postal_code' => '1000001',
                'shipping_city' => '千代田区',
                'shipping_address_line1' => '千代田1-1',
                'shipping_rate_id' => $rate->id,
            ],
        ];
    }

    $counts = CheckoutScenario::outcomeCounts(ConcurrencyHarness::run('reserve-order', $payloads), 'stock 1, distinct keys');

    expect($counts)->toBe(CheckoutScenario::expectedCounts(['reserved' => 1, 'rejected' => 7]))
        ->and(Order::query()->count())->toBe(1)
        ->and($balance->fresh()->reserved)->toBe(1)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});
