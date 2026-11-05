<?php

namespace Tests\Support\Concurrency;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Committed checkout data and result assertions shared by the concurrency tests.
 */
final class CheckoutScenario
{
    public const OUTCOMES = ['reserved', 'rejected', 'deadlock', 'lock_wait_timeout', 'error'];

    private const SEEDED_ORDER_PREFIX = 'SEED';

    public static function shippingRate(): ShippingRate
    {
        $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
        TaxRate::query()->create([
            'country_code' => 'JP',
            'tax_basis_points' => 1000,
            'active_from' => today(),
            'is_active' => true,
        ]);

        return ShippingRate::query()->create([
            'shipping_method_id' => $method->id,
            'country_code' => 'JP',
            'fee_minor' => 500,
            'currency' => 'JPY',
            'is_active' => true,
        ]);
    }

    public static function stockedVariant(int $onHand): ProductVariant
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['status' => 'published']))
            ->create(['price_minor' => 1000]);
        InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 0, 'reserved' => 0]);
        app(InventoryService::class)->setOnHand($variant, $onHand, User::factory()->create(), 'concurrency test stock');

        return $variant;
    }

    public static function guestCart(ProductVariant $variant): Cart
    {
        $cart = Cart::query()->create([
            'guest_token_hash' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDay(),
        ]);
        CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);

        return $cart;
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkoutData(ShippingRate $rate, string $keySeed): array
    {
        return [
            'checkout_key' => hash('sha256', $keySeed),
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
     * @return array{cart_id: int, data: array<string, mixed>}
     */
    public static function reservePayload(Cart $cart, ShippingRate $rate, string $keySeed): array
    {
        return ['cart_id' => $cart->id, 'data' => self::checkoutData($rate, $keySeed)];
    }

    public static function seedExistingOrders(int $count): void
    {
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            DB::table('ec_orders')->insert(array_map(fn (int $number): array => [
                'order_number' => self::SEEDED_ORDER_PREFIX.$number,
                'idempotency_key' => bin2hex(random_bytes(32)),
                'status' => 'cancelled',
                'payment_status' => 'failed',
                'customer_name' => 'x',
                'customer_email' => 'x',
                'shipping_recipient' => 'x',
                'shipping_country_code' => 'JP',
                'shipping_postal_code' => 'x',
                'shipping_city' => 'x',
                'shipping_address_line1' => 'x',
                'shipping_method_name' => 'x',
                'subtotal_minor' => 1,
                'shipping_minor' => 1,
                'total_minor' => 2,
            ], $chunk));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, int>
     */
    public static function outcomeCounts(array $results, string $label): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);
        foreach ($results as $result) {
            $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
        }

        fwrite(STDERR, PHP_EOL.'[outcomes] '.$label.' '.json_encode($counts).PHP_EOL);
        foreach ($results as $result) {
            if ($result['outcome'] === 'error') {
                fwrite(STDERR, '[worker error] '.($result['error'] ?? 'unknown').PHP_EOL);
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    public static function expectedCounts(array $overrides): array
    {
        return [...array_fill_keys(self::OUTCOMES, 0), ...$overrides];
    }

    /**
     * @return array<int, string>
     */
    public static function orderViolations(): array
    {
        $violations = [];
        $orders = Order::query()
            ->where('order_number', 'not like', self::SEEDED_ORDER_PREFIX.'%')
            ->with(['items', 'payments'])
            ->get();

        foreach ($orders as $order) {
            if ($order->payments->count() !== 1 || $order->payments->first()->amount_minor !== $order->total_minor) {
                $violations[] = "order {$order->id}: expected one payment for the order total";
            }
            if ($order->items->isEmpty() || $order->total_minor !== $order->items->sum('line_total_minor') + $order->shipping_minor) {
                $violations[] = "order {$order->id}: the total does not match its lines and shipping";
            }
            foreach ($order->items as $item) {
                $reservations = InventoryMovement::query()
                    ->where('order_id', $order->id)
                    ->where('variant_id', $item->variant_id)
                    ->where('movement_type', 'reservation');
                if ($reservations->count() !== 1 || (int) $reservations->sum('reserved_delta') !== $item->quantity) {
                    $violations[] = "order {$order->id}: expected one reservation movement per line";
                }
            }
        }

        return $violations;
    }
}
