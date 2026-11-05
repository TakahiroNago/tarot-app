<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Throwable;

class CheckoutService
{
    private const TAX_DENOMINATOR = 10000;

    /**
     * @return Collection<int, ShippingRate>
     */
    public function availableShippingRates(): Collection
    {
        $today = today()->toDateString();
        $taxableCountries = TaxRate::query()
            ->where('is_active', true)
            ->whereDate('active_from', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('active_until')->orWhereDate('active_until', '>=', $today);
            })
            ->select('country_code');

        return ShippingRate::query()
            ->with('shippingMethod')
            ->where('is_active', true)
            ->whereHas('shippingMethod', fn ($query) => $query->where('is_active', true))
            ->whereIn('country_code', $taxableCountries)
            ->orderBy('country_code')
            ->orderBy('shipping_method_id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reserveOrder(Cart $cart, array $data, ?User $user): Order
    {
        $idempotencyKey = hash('sha256', $data['checkout_key']);

        return DB::transaction(function () use ($cart, $data, $user, $idempotencyKey): Order {
            $existingOrder = Order::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingOrder instanceof Order) {
                abort_unless($existingOrder->user_id === $user?->id, 404);
                if ($existingOrder->status !== 'pending_payment') {
                    throw ValidationException::withMessages([
                        'checkout_key' => 'この注文は決済を再開できません。チェックアウトを開き直してください。',
                    ]);
                }

                return $existingOrder;
            }

            $shippingRate = ShippingRate::query()
                ->with('shippingMethod')
                ->whereKey($data['shipping_rate_id'])
                ->where('country_code', $data['shipping_country_code'])
                ->where('currency', 'JPY')
                ->where('is_active', true)
                ->whereHas('shippingMethod', fn ($query) => $query->where('is_active', true))
                ->lockForUpdate()
                ->first();
            if (! $shippingRate instanceof ShippingRate) {
                throw ValidationException::withMessages([
                    'shipping_rate_id' => '選択した配送先・配送方法は現在利用できません。',
                ]);
            }

            $taxRates = TaxRate::query()
                ->where('country_code', $data['shipping_country_code'])
                ->where('is_active', true)
                ->whereDate('active_from', '<=', today())
                ->where(function ($query): void {
                    $query->whereNull('active_until')->orWhereDate('active_until', '>=', today());
                })
                ->lockForUpdate()
                ->get();
            if ($taxRates->count() !== 1) {
                throw ValidationException::withMessages([
                    'shipping_country_code' => '配送先の税ルールが未設定または重複しているため、注文を確定できません。',
                ]);
            }
            $taxRate = $taxRates->sole();

            $items = CartItem::query()
                ->where('cart_id', $cart->id)
                ->orderBy('variant_id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'カートに商品がありません。',
                ]);
            }

            $orderLines = [];
            $subtotal = 0;
            $tax = 0;

            foreach ($items as $item) {
                $variant = $item->variant()->lockForUpdate()->first();
                $product = $variant?->product;
                $balance = $variant === null
                    ? null
                    : InventoryBalance::query()->lockForUpdate()->find($variant->id);

                if (
                    $variant === null
                    || $product === null
                    || $product->status !== 'published'
                    || ! $variant->is_active
                    || $balance === null
                    || $balance->available() < $item->quantity
                ) {
                    throw ValidationException::withMessages([
                        'cart' => '商品または在庫の状態が変わりました。カートを確認してください。',
                    ]);
                }

                $lineTotal = $variant->price_minor * $item->quantity;
                $lineTax = $this->includedTax($lineTotal, $taxRate->tax_basis_points);
                $orderLines[] = [
                    'item' => $item,
                    'variant' => $variant,
                    'product' => $product,
                    'balance' => $balance,
                    'line_total' => $lineTotal,
                    'line_tax' => $lineTax,
                ];
                $subtotal += $lineTotal;
                $tax += $lineTax;
            }

            $shipping = $shippingRate->fee_minor;
            if ($taxRate->shipping_taxable) {
                $tax += $this->includedTax($shipping, $taxRate->tax_basis_points);
            }

            if ($subtotal + $shipping < 1) {
                throw ValidationException::withMessages([
                    'cart' => '合計金額が0円のため、オンライン決済を利用できません。',
                ]);
            }

            $now = now();
            $orderNumber = 'EC'.$now->format('ymd').Str::upper(Str::random(8));
            $guestAccessExpiresAt = $user === null ? $now->copy()->addDays(30) : null;
            $order = Order::query()->create([
                'order_number' => $orderNumber,
                'user_id' => $user?->id,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending_payment',
                'payment_status' => 'pending',
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'shipping_recipient' => $data['shipping_recipient'],
                'shipping_country_code' => $data['shipping_country_code'],
                'shipping_postal_code' => $data['shipping_postal_code'],
                'shipping_region' => $data['shipping_region'] ?? null,
                'shipping_city' => $data['shipping_city'],
                'shipping_address_line1' => $data['shipping_address_line1'],
                'shipping_address_line2' => $data['shipping_address_line2'] ?? null,
                'shipping_method_name' => $shippingRate->shippingMethod->name,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'shipping_minor' => $shipping,
                'total_minor' => $subtotal + $shipping,
                'currency' => 'JPY',
                'tax_rate_basis_points' => $taxRate->tax_basis_points,
                'reservation_expires_at' => $now->copy()->addMinutes(30),
                'guest_access_expires_at' => $guestAccessExpiresAt,
            ]);

            if ($guestAccessExpiresAt !== null) {
                $guestOrderUrl = URL::temporarySignedRoute(
                    'shop.orders.guest-show',
                    $guestAccessExpiresAt,
                    ['orderNumber' => $order->order_number],
                );
                parse_str((string) parse_url($guestOrderUrl, PHP_URL_QUERY), $signatureParameters);
                $signature = $signatureParameters['signature'] ?? null;
                if (! is_string($signature)) {
                    throw new RuntimeException('Could not generate the signed guest order URL.');
                }

                $order->guest_access_token_hash = hash('sha256', $signature);
                $order->save();
            }

            foreach ($orderLines as $line) {
                /** @var CartItem $item */
                $item = $line['item'];
                $variant = $line['variant'];
                $product = $line['product'];
                /** @var InventoryBalance $balance */
                $balance = $line['balance'];

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'product_name_snapshot' => $product->name,
                    'variant_label_snapshot' => $variant->label,
                    'sku_snapshot' => $variant->sku,
                    'unit_price_minor' => $variant->price_minor,
                    'quantity' => $item->quantity,
                    'tax_minor' => $line['line_tax'],
                    'line_total_minor' => $line['line_total'],
                    'tax_rate_basis_points' => $taxRate->tax_basis_points,
                    'currency' => 'JPY',
                ]);

                $balance->reserved += $item->quantity;
                $balance->save();
                InventoryMovement::query()->create([
                    'variant_id' => $variant->id,
                    'order_id' => $order->id,
                    'movement_type' => 'reservation',
                    'on_hand_delta' => 0,
                    'reserved_delta' => $item->quantity,
                    'idempotency_key' => bin2hex(random_bytes(32)),
                    'note' => '注文 '.$order->order_number.' の決済期限付き在庫予約',
                ]);
            }

            Payment::query()->create([
                'order_id' => $order->id,
                'provider' => 'stripe',
                'status' => 'pending',
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'idempotency_key' => 'ec-'.$idempotencyKey,
            ]);

            return $order;
        });
    }

    public function createStripeSession(Order $order, StripeBillingService $stripeBillingService): Session
    {
        $payment = $order->payments()->where('provider', 'stripe')->firstOrFail();
        $stripe = $stripeBillingService->client();

        if (is_string($payment->provider_checkout_id)) {
            return $stripe->checkout->sessions->retrieve($payment->provider_checkout_id);
        }

        $order->loadMissing('items');
        $lineItems = $order->items->map(fn (OrderItem $item): array => [
            'price_data' => [
                'currency' => strtolower($item->currency),
                'product_data' => [
                    'name' => $item->product_name_snapshot.' - '.$item->variant_label_snapshot,
                ],
                'unit_amount' => $item->unit_price_minor,
            ],
            'quantity' => $item->quantity,
        ])->all();

        if ($order->shipping_minor > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'product_data' => ['name' => '送料（'.$order->shipping_method_name.'）'],
                    'unit_amount' => $order->shipping_minor,
                ],
                'quantity' => 1,
            ];
        }

        try {
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => $lineItems,
                'customer_email' => $order->customer_email,
                'client_reference_id' => $order->order_number,
                'metadata' => [
                    'ec_order_id' => (string) $order->id,
                    'ec_payment_id' => (string) $payment->id,
                ],
                'payment_intent_data' => [
                    'metadata' => ['ec_order_id' => (string) $order->id],
                ],
                'expires_at' => now()->addMinutes(30)->addSeconds(30)->timestamp,
                'success_url' => route('shop.checkout.success', [
                    'orderNumber' => $order->order_number,
                ]).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('shop.checkout.cancel'),
            ], [
                'idempotency_key' => $payment->idempotency_key,
            ]);
        } catch (ApiErrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->releaseReservation($order, 'Stripe Checkout セッションを作成できませんでした。');

            throw $exception;
        }

        if (! is_string($session->url) || $session->url === '' || ! is_int($session->expires_at)) {
            $this->releaseReservation($order, 'Stripe Checkout の応答が不完全でした。');

            throw new RuntimeException('Stripe Checkout session did not include a URL and expiration.');
        }

        DB::transaction(function () use ($order, $payment, $session): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedPayment->provider_checkout_id = $session->id;
            $lockedPayment->provider_status = $session->status;
            $lockedPayment->save();
            $lockedOrder->reservation_expires_at = now()->setTimestamp($session->expires_at);
            $lockedOrder->save();
        });

        return $session;
    }

    public function releaseReservation(Order $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status !== 'pending_payment') {
                return;
            }

            $items = $lockedOrder->items()->orderBy('variant_id')->get();
            foreach ($items as $item) {
                if ($item->variant_id === null) {
                    throw new RuntimeException('Cannot release a reservation without its product variant.');
                }

                $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($item->variant_id);
                if ($balance->reserved < $item->quantity) {
                    throw new RuntimeException('Reserved inventory is inconsistent with the order items.');
                }

                $balance->reserved -= $item->quantity;
                $balance->save();
                InventoryMovement::query()->create([
                    'variant_id' => $item->variant_id,
                    'order_id' => $lockedOrder->id,
                    'movement_type' => 'reservation_release',
                    'on_hand_delta' => 0,
                    'reserved_delta' => -$item->quantity,
                    'idempotency_key' => bin2hex(random_bytes(32)),
                    'note' => $reason,
                ]);
            }

            $lockedOrder->status = 'cancelled';
            $lockedOrder->payment_status = 'failed';
            $lockedOrder->reservation_expires_at = null;
            $lockedOrder->save();

            $lockedOrder->payments()
                ->where('provider', 'stripe')
                ->where('status', 'pending')
                ->update(['status' => 'failed', 'provider_status' => 'failed', 'updated_at' => now()]);
        });
    }

    public function guestOrderUrl(Order $order): string
    {
        abort_unless($order->user_id === null && $order->guest_access_expires_at?->isFuture(), 404);

        return URL::temporarySignedRoute(
            'shop.orders.guest-show',
            $order->guest_access_expires_at,
            ['orderNumber' => $order->order_number],
        );
    }

    private function includedTax(int $gross, int $basisPoints): int
    {
        $numerator = $gross * $basisPoints;
        $denominator = self::TAX_DENOMINATOR + $basisPoints;

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
