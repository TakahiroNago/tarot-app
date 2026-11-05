<?php

namespace Tests\Support\Concurrency;

use App\Models\Cart;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutConflictException;
use App\Services\CheckoutService;
use App\Services\PaymentSettlementService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Support\Stripe\FakeStripeBillingService;
use Throwable;

/**
 * The operations a concurrency worker can run after the start barrier opens.
 *
 * Failures are reported as outcomes rather than exceptions, so a test can distinguish them.
 */
final class ConcurrencyActions
{
    public const BUSY_NOTICE = 'ご注文が混み合っています。入力内容は保持されていますので、もう一度お試しください。';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function run(string $action, array $payload): array
    {
        return match ($action) {
            'increment-on-hand' => self::incrementOnHand($payload),
            'reserve-order' => self::reserveOrder($payload),
            'reserve-order-with-injected-deadlocks' => self::reserveOrderWithInjectedDeadlocks($payload),
            'http-checkout' => self::httpCheckout($payload),
            'change-shipping-and-tax' => self::changeShippingAndTax($payload),
            'approve-review' => self::approveReview($payload),
            'add-to-cart' => self::addToCart($payload),
            'mixed' => self::run($payload['action'], $payload['payload']),
            default => throw new InvalidArgumentException("Unknown concurrency action [{$action}]."),
        };
    }

    /**
     * Exercises InnoDB row locks directly, independent of application behaviour.
     *
     * @param  array{variant_id: int, iterations: int}  $payload
     * @return array<string, mixed>
     */
    private static function incrementOnHand(array $payload): array
    {
        for ($iteration = 0; $iteration < $payload['iterations']; $iteration++) {
            DB::transaction(function () use ($payload): void {
                $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($payload['variant_id']);
                $balance->on_hand++;
                $balance->save();
            });
        }

        return ['outcome' => 'completed'];
    }

    /**
     * @param  array{cart_id: int, data: array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    private static function reserveOrder(array $payload): array
    {
        try {
            $order = app(CheckoutService::class)->reserveOrder(
                Cart::query()->findOrFail($payload['cart_id']),
                $payload['data'],
                null,
            );

            return ['outcome' => 'reserved', 'order_id' => $order->id];
        } catch (CheckoutConflictException) {
            return ['outcome' => 'conflict'];
        } catch (ValidationException) {
            return ['outcome' => 'rejected'];
        } catch (QueryException $exception) {
            return match ($exception->errorInfo[1] ?? null) {
                1213 => ['outcome' => 'deadlock'],
                1205 => ['outcome' => 'lock_wait_timeout'],
                default => self::errorOutcome($exception),
            };
        } catch (Throwable $exception) {
            return self::errorOutcome($exception);
        }
    }

    /**
     * @param  array{deadlocks: int, cart_id: int, data: array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    private static function reserveOrderWithInjectedDeadlocks(array $payload): array
    {
        InjectedDeadlock::onPaymentCreation($payload['deadlocks']);

        return self::reserveOrder($payload);
    }

    /**
     * Sends the checkout form through the real HTTP kernel, with Stripe shared between workers.
     *
     * @param  array{user_id: int, data: array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    private static function httpCheckout(array $payload): array
    {
        FakeStripeBillingService::install(app(), new SharedDatabaseStripeSessions);
        Auth::guard('web')->setUser(User::query()->findOrFail($payload['user_id']));

        $response = app(Kernel::class)->handle(Request::create('/checkout', 'POST', $payload['data']));
        $location = (string) $response->headers->get('Location');
        $status = session('status');

        return match (true) {
            $response->getStatusCode() === 302 && str_starts_with($location, 'https://checkout.stripe.test/') => [
                'outcome' => 'reserved',
                'location' => $location,
            ],
            $status === self::BUSY_NOTICE => ['outcome' => 'deadlock'],
            session('errors') !== null => ['outcome' => 'rejected'],
            $response->getStatusCode() === 302 => ['outcome' => 'stripe_retry_notice'],
            default => [
                'outcome' => 'error',
                'error' => 'HTTP '.$response->getStatusCode().' '.($response->exception?->getMessage() ?? ''),
            ],
        };
    }

    /**
     * Changes settings the way the admin controller does while orders are being placed.
     *
     * @param  array{rate_id: int, tax_rate_id: int, iterations: int}  $payload
     * @return array<string, mixed>
     */
    private static function changeShippingAndTax(array $payload): array
    {
        $rate = ShippingRate::query()->findOrFail($payload['rate_id']);
        $taxRate = TaxRate::query()->findOrFail($payload['tax_rate_id']);

        for ($iteration = 0; $iteration < $payload['iterations']; $iteration++) {
            $rate->fill(['fee_minor' => $iteration % 2 === 0 ? 900 : 500])->save();
            $taxRate->fill(['tax_basis_points' => $iteration % 2 === 0 ? 800 : 1000])->save();
        }

        return ['outcome' => 'settings_changed'];
    }

    /**
     * @param  array{cart_id: int, variant_id: int}  $payload
     * @return array<string, mixed>
     */
    private static function addToCart(array $payload): array
    {
        try {
            app(CartService::class)->add(Cart::query()->findOrFail($payload['cart_id']), $payload['variant_id'], 1);

            return ['outcome' => 'added'];
        } catch (ValidationException) {
            return ['outcome' => 'rejected'];
        } catch (QueryException $exception) {
            return ($exception->errorInfo[1] ?? null) === 1213
                ? ['outcome' => 'deadlock']
                : self::errorOutcome($exception);
        } catch (Throwable $exception) {
            return self::errorOutcome($exception);
        }
    }

    /**
     * @param  array{order_id: int, actor_id: int}  $payload
     * @return array<string, mixed>
     */
    private static function approveReview(array $payload): array
    {
        try {
            app(PaymentSettlementService::class)->approveReview(
                Order::query()->findOrFail($payload['order_id']),
                User::query()->findOrFail($payload['actor_id']),
                'concurrency test approval',
            );

            return ['outcome' => 'approved'];
        } catch (ValidationException) {
            return ['outcome' => 'rejected'];
        } catch (QueryException $exception) {
            return ($exception->errorInfo[1] ?? null) === 1213
                ? ['outcome' => 'deadlock']
                : self::errorOutcome($exception);
        } catch (Throwable $exception) {
            return self::errorOutcome($exception);
        }
    }

    /**
     * @return array{outcome: string, error: string}
     */
    private static function errorOutcome(Throwable $exception): array
    {
        return ['outcome' => 'error', 'error' => $exception::class.': '.substr($exception->getMessage(), 0, 300)];
    }
}
