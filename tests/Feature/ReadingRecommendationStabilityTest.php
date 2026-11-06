<?php

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TarotReading;
use App\Models\TarotReadingProductRecommendation;
use App\Models\User;
use App\Services\ProductRecommendationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

function recommendableProduct(string $name = 'Tarot Deck'): Product
{
    $product = Product::factory()->create([
        'name' => $name,
        'status' => 'published',
        'reading_recommendable' => true,
    ]);
    InventoryBalance::factory()
        ->for(ProductVariant::factory()->for($product)->create(), 'variant')
        ->create(['on_hand' => 1, 'reserved' => 0]);

    return $product;
}

function fakeReadingResponses(): void
{
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'An interpretation.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"category":"その他","summary":"全体に関する助言です。"}']]]]),
    ]);
}

/**
 * Makes every product query fail, as a brief database problem would.
 */
function whileProductsAreUnavailable(Closure $callback): mixed
{
    Schema::rename('ec_products', 'ec_products_unavailable');

    try {
        return $callback();
    } finally {
        Schema::rename('ec_products_unavailable', 'ec_products');
    }
}

function sessionReading(array $attributes = []): array
{
    return [
        'question' => 'Before registration?',
        'card' => 'The Fool',
        'interpretation' => 'A reading before registration.',
        ...$attributes,
    ];
}

function savedReading(): TarotReading
{
    return User::factory()->create()->tarotReadings()->create(sessionReading());
}

function registerWithSessionReading(mixed $test, array $reading): User
{
    $test->withSession(['tarot_readings' => [$reading]])->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new-reader@example.com',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
    ])->assertRedirect(route('verification.notice'));

    return User::query()->where('email', 'new-reader@example.com')->sole();
}

test('a member reading with no candidate keeps showing none after a product appears', function () {
    fakeReadingResponses();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'A question?'])
        ->assertOk()
        ->assertViewHas('recommendedProduct', null);
    $reading = $user->tarotReadings()->sole();
    expect($reading->productRecommendation()->sole()->product_id)->toBeNull();

    recommendableProduct();
    Exceptions::fake();

    $this->get(route('readings.show', $reading->id))
        ->assertOk()
        ->assertViewHas('recommendedProduct', null);

    expect(TarotReadingProductRecommendation::query()->count())->toBe(1);
    Exceptions::assertNothingReported();
});

test('a member reading keeps its product even when others become available', function () {
    $product = recommendableProduct('First Deck');
    $reading = savedReading();
    $service = app(ProductRecommendationService::class);

    expect($service->forReading($reading)->is($product))->toBeTrue();

    recommendableProduct('Second Deck');
    Product::query()->whereKey($product->id)->update(['reading_recommendable' => false]);

    expect($service->forReading($reading)->is($product))->toBeTrue();
});

test('a database error is not remembered for a member reading', function () {
    $reading = savedReading();
    $product = recommendableProduct();
    $service = app(ProductRecommendationService::class);

    $duringOutage = whileProductsAreUnavailable(fn () => $service->forReading($reading));

    expect($duringOutage)->toBeNull()
        ->and($reading->productRecommendation()->exists())->toBeFalse()
        ->and($service->forReading($reading)->is($product))->toBeTrue();
});

test('a database error is not remembered for a guest reading', function () {
    $reading = sessionReading();
    $product = recommendableProduct();
    $service = app(ProductRecommendationService::class);

    $duringOutage = whileProductsAreUnavailable(function () use ($service, &$reading) {
        return $service->forSession($reading);
    });

    expect($duringOutage)->toBeNull()
        ->and($reading)->not->toHaveKey('recommended_product_id')
        ->and($service->forSession($reading)->is($product))->toBeTrue()
        ->and($reading['recommended_product_id'])->toBe($product->id);
});

test('a guest reading with no candidate keeps showing none after a product appears', function () {
    $reading = sessionReading();
    $service = app(ProductRecommendationService::class);

    expect($service->forSession($reading))->toBeNull()
        ->and($reading)->toHaveKey('recommended_product_id', null);

    recommendableProduct();

    expect($service->forSession($reading))->toBeNull();
});

test('a reading still appears when the recommendation cannot be checked', function () {
    fakeReadingResponses();
    $user = User::factory()->create();

    whileProductsAreUnavailable(function () use ($user): void {
        $this->actingAs($user)
            ->post(route('tarot.draw'), ['question' => 'A question?'])
            ->assertOk()
            ->assertViewHas('interpretation', 'An interpretation.')
            ->assertViewHas('recommendedProduct', null);
    });

    expect($user->tarotReadings()->count())->toBe(1);
});

test('registering keeps a guest reading that had no candidate empty', function () {
    recommendableProduct();

    $user = registerWithSessionReading($this, sessionReading(['recommended_product_id' => null]));

    $reading = $user->tarotReadings()->sole();
    expect($reading->productRecommendation()->sole()->product_id)->toBeNull()
        ->and(app(ProductRecommendationService::class)->forReading($reading))->toBeNull();
});

test('registering leaves a reading that was never checked to be checked later', function () {
    $product = recommendableProduct();

    $user = registerWithSessionReading($this, sessionReading());

    $reading = $user->tarotReadings()->sole();
    expect($reading->productRecommendation()->exists())->toBeFalse()
        ->and(app(ProductRecommendationService::class)->forReading($reading)->is($product))->toBeTrue();
});

test('registering records no product when the guest reading\'s product is gone', function () {
    $user = registerWithSessionReading($this, sessionReading(['recommended_product_id' => 999999]));

    $reading = $user->tarotReadings()->sole();
    recommendableProduct();

    expect($reading->productRecommendation()->sole()->product_id)->toBeNull()
        ->and(app(ProductRecommendationService::class)->forReading($reading))->toBeNull();
});

test('a recommended product still cannot be removed from the database', function () {
    $product = Product::factory()->create();
    savedReading()->productRecommendation()->create(['product_id' => $product->id, 'selected_at' => now()]);

    expect(fn () => $product->forceDelete())->toThrow(QueryException::class);
});

test('rolling back removes empty rows and keeps recorded products', function () {
    $product = recommendableProduct();
    $withProduct = savedReading();
    app(ProductRecommendationService::class)->forReading($withProduct);
    Product::query()->whereKey($product->id)->update(['reading_recommendable' => false]);
    $empty = savedReading();
    app(ProductRecommendationService::class)->forReading($empty);
    $migration = require database_path('migrations/2026_11_06_120000_allow_readings_without_a_recommended_product.php');

    $migration->down();

    expect($empty->productRecommendation()->exists())->toBeFalse()
        ->and($withProduct->productRecommendation()->sole()->product_id)->toBe($product->id);

    $migration->up();

    expect(fn () => savedReading()->productRecommendation()->create(['product_id' => null, 'selected_at' => now()]))->not->toThrow(QueryException::class);
});
