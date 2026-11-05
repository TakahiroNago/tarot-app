<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutService
{
    private const TAX_DENOMINATOR = 10000;

    private const RESERVATION_TRANSACTION_ATTEMPTS = 3;

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
        $fingerprint = $this->checkoutFingerprint(
            CartItem::query()->where('cart_id', $cart->id)->get(),
            $data,
        );

        $existingOrderId = $this->existingOrderId($idempotencyKey, $cart);
        if ($existingOrderId !== null) {
            return $this->resumeExistingOrder($existingOrderId, $user, $fingerprint);
        }

        try {
            return $this->createReservedOrder($cart, $data, $user, $idempotencyKey);
        } catch (UniqueConstraintViolationException|ValidationException $exception) {
            if (
                $exception instanceof UniqueConstraintViolationException
                && ! $this->isDuplicateOrderViolation($exception)
            ) {
                throw $exception;
            }

            $existingOrderId = $this->existingOrderId($idempotencyKey, $cart);
            if ($existingOrderId === null) {
                throw $exception;
            }

            return $this->resumeExistingOrder($existingOrderId, $user, $fingerprint);
        }
    }

    /**
     * What the order would be if placed now, for the buyer to confirm. Nothing is reserved. The hash
     * covers the details and amounts, so placing the order detects any change since this was shown.
     *
     * @param  array<string, mixed>  $data
     * @return array{lines: array<int, array{name: string, label: string, quantity: int, unit_price: int, line_total: int}>, subtotal: int, tax: int, shipping: int, total: int, shipping_method: string, hash: string}
     */
    public function quote(Cart $cart, array $data): array
    {
        $shippingRate = $this->shippingRateFor($data);
        $taxRate = $this->taxRateFor($data);
        $items = CartItem::query()
            ->with(['variant.product', 'variant.inventoryBalance'])
            ->where('cart_id', $cart->id)
            ->orderBy('variant_id')
            ->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'カートに商品がありません。',
            ]);
        }

        $lines = [];
        $hashLines = [];
        $subtotal = 0;
        $tax = 0;
        foreach ($items as $item) {
            $variant = $item->variant;
            $product = $variant?->product;
            if (
                $product === null
                || $product->status !== 'published'
                || ! $variant->is_active
                || $variant->inventoryBalance === null
                || $variant->inventoryBalance->available() < $item->quantity
            ) {
                throw ValidationException::withMessages([
                    'cart' => '商品または在庫の状態が変わりました。カートを確認してください。',
                ]);
            }

            $lineTotal = $variant->price_minor * $item->quantity;
            $lineTax = $this->includedTax($lineTotal, $taxRate->tax_basis_points);
            $lines[] = [
                'name' => $product->name,
                'label' => $variant->label,
                'quantity' => $item->quantity,
                'unit_price' => $variant->price_minor,
                'line_total' => $lineTotal,
            ];
            $hashLines[] = [$variant->id, $variant->price_minor, $lineTax];
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

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => $subtotal + $shipping,
            'shipping_method' => $shippingRate->shippingMethod->name,
            'hash' => $this->quoteHash($items, $data, $hashLines, $shipping, $tax, $taxRate->tax_basis_points),
        ];
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  array<string, mixed>  $data
     * @param  array<int, array{0: int, 1: int, 2: int}>  $lines
     */
    private function quoteHash(Collection $items, array $data, array $lines, int $shipping, int $tax, int $taxBasisPoints): string
    {
        return hash_hmac('sha256', json_encode([
            'fingerprint' => $this->checkoutFingerprint($items, $data),
            'lines' => $lines,
            'shipping' => $shipping,
            'tax' => $tax,
            'tax_basis_points' => $taxBasisPoints,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shippingRateFor(array $data): ShippingRate
    {
        $shippingRate = ShippingRate::query()
            ->with('shippingMethod')
            ->whereKey($data['shipping_rate_id'])
            ->where('country_code', $data['shipping_country_code'])
            ->where('currency', 'JPY')
            ->where('is_active', true)
            ->whereHas('shippingMethod', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $shippingRate instanceof ShippingRate) {
            throw ValidationException::withMessages([
                'shipping_rate_id' => '選択した配送先・配送方法は現在利用できません。',
            ]);
        }

        return $shippingRate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function taxRateFor(array $data): TaxRate
    {
        $taxRates = TaxRate::query()
            ->where('country_code', $data['shipping_country_code'])
            ->where('is_active', true)
            ->whereDate('active_from', '<=', today())
            ->where(function ($query): void {
                $query->whereNull('active_until')->orWhereDate('active_until', '>=', today());
            })
            ->get();

        if ($taxRates->count() !== 1) {
            throw ValidationException::withMessages([
                'shipping_country_code' => '配送先の税ルールが未設定または重複しているため、注文を確定できません。',
            ]);
        }

        return $taxRates->sole();
    }

    public function activeOrderFor(Cart $cart): ?Order
    {
        return Order::query()->with('items')->where('active_cart_id', $cart->id)->first();
    }

    /**
     * Whether a checkout key belongs to an order that can no longer take payment.
     */
    public function checkoutKeyIsClosed(string $checkoutKey): bool
    {
        return Order::query()
            ->where('idempotency_key', hash('sha256', $checkoutKey))
            ->where('status', '<>', 'pending_payment')
            ->exists();
    }

    /**
     * A keyed HMAC, because the inputs are personal data and an unkeyed hash could be guessed.
     *
     * @param  Collection<int, CartItem>  $items
     * @param  array<string, mixed>  $data
     */
    private function checkoutFingerprint(Collection $items, array $data): string
    {
        $lines = $items
            ->map(fn (CartItem $item): array => [(int) $item->variant_id, (int) $item->quantity])
            ->sortBy(fn (array $line): int => $line[0])
            ->values()
            ->all();

        return hash_hmac('sha256', json_encode([
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
            'shipping_rate_id' => (int) $data['shipping_rate_id'],
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * Read outside any transaction so a concurrent request's committed order is visible and no
     * gap lock is taken on the unique index for a key that does not exist yet.
     */
    private function existingOrderId(string $idempotencyKey, Cart $cart): ?int
    {
        return Order::query()->where('idempotency_key', $idempotencyKey)->value('id')
            ?? Order::query()->where('active_cart_id', $cart->id)->value('id');
    }

    private function resumeExistingOrder(int $orderId, ?User $user, string $fingerprint): Order
    {
        return DB::transaction(function () use ($orderId, $user, $fingerprint): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            abort_unless($order->user_id === $user?->id, 404);
            if ($order->status !== 'pending_payment') {
                throw ValidationException::withMessages([
                    'checkout_key' => 'この注文は決済を再開できません。チェックアウトを開き直してください。',
                ]);
            }

            if ($order->checkout_fingerprint !== null && ! hash_equals($order->checkout_fingerprint, $fingerprint)) {
                throw new CheckoutConflictException;
            }

            return $order;
        }, self::RESERVATION_TRANSACTION_ATTEMPTS);
    }

    /**
     * Only these unique indexes mean another request created this order or holds this cart.
     * The driver's own message is used because the full exception message also contains bindings.
     */
    private function isDuplicateOrderViolation(UniqueConstraintViolationException $exception): bool
    {
        $driverMessage = (string) ($exception->errorInfo[2] ?? '');

        return str_contains($driverMessage, "ec_orders_idempotency_key_unique'")
            || str_contains($driverMessage, 'ec_orders.idempotency_key')
            || str_contains($driverMessage, "ec_orders_active_cart_id_unique'")
            || str_contains($driverMessage, 'ec_orders.active_cart_id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createReservedOrder(Cart $cart, array $data, ?User $user, string $idempotencyKey): Order
    {
        return DB::transaction(function () use ($cart, $data, $user, $idempotencyKey): Order {
            // The first lock in every checkout path, so one cart's requests run one at a time.
            Cart::query()->lockForUpdate()->findOrFail($cart->id);
            if (Order::query()->where('active_cart_id', $cart->id)->exists()) {
                throw ValidationException::withMessages([
                    'cart' => 'お支払い待ちのご注文があります。',
                ]);
            }

            $shippingRate = $this->shippingRateFor($data);
            $taxRate = $this->taxRateFor($data);

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
                // Shared, not exclusive: every ledger insert takes a shared lock on the variant through
                // its foreign key while holding the balance, which would deadlock with an exclusive
                // lock taken here before the balance. A shared lock still blocks a price change.
                $variant = $item->variant()->sharedLock()->first();
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

            if (isset($data['quote_hash'])) {
                $currentHash = $this->quoteHash($items, $data, array_map(fn (array $line): array => [
                    $line['variant']->id,
                    $line['variant']->price_minor,
                    $line['line_tax'],
                ], $orderLines), $shipping, $tax, $taxRate->tax_basis_points);

                if (! hash_equals($currentHash, (string) $data['quote_hash'])) {
                    throw new CheckoutQuoteChangedException;
                }
            }

            $now = now();
            $orderNumber = 'EC'.$now->format('ymd').Str::upper(Str::random(8));
            $guestAccessExpiresAt = $user === null ? $now->copy()->addDays(30) : null;
            $order = Order::query()->create([
                'order_number' => $orderNumber,
                'user_id' => $user?->id,
                'idempotency_key' => $idempotencyKey,
                'checkout_fingerprint' => $this->checkoutFingerprint($items, $data),
                'active_cart_id' => $cart->id,
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
                $guestOrderPath = $this->signedGuestOrderPath($order->order_number, $guestAccessExpiresAt);
                parse_str((string) parse_url($guestOrderPath, PHP_URL_QUERY), $signatureParameters);
                $signature = $signatureParameters['signature'] ?? null;
                if (! is_string($signature)) {
                    throw new RuntimeException('Could not generate the signed guest order URL.');
                }

                $order->guest_access_token_hash = hash('sha256', $signature);
                $order->save();
            }

            if (! empty($data['terms_accepted'])) {
                OrderEvent::query()->create([
                    'order_id' => $order->id,
                    'event_type' => 'terms_accepted',
                    'to_status' => $order->status,
                    'to_payment_status' => $order->payment_status,
                ]);
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
                    'idempotency_key' => InventoryService::movementKey('reservation', $order->id, $variant->id),
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
        }, self::RESERVATION_TRANSACTION_ATTEMPTS);
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
                    'idempotency_key' => InventoryService::movementKey('reservation_release', $lockedOrder->id, $item->variant_id),
                    'note' => $reason,
                ]);
            }

            $lockedOrder->status = 'cancelled';
            $lockedOrder->payment_status = 'failed';
            $lockedOrder->reservation_expires_at = null;
            $lockedOrder->active_cart_id = null;
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

        return url($this->signedGuestOrderPath($order->order_number, $order->guest_access_expires_at));
    }

    /**
     * Signs the path alone, so a link written by a queue worker still opens on the public host.
     */
    private function signedGuestOrderPath(string $orderNumber, DateTimeInterface $expiresAt): string
    {
        return URL::temporarySignedRoute(
            'shop.orders.guest-show',
            $expiresAt,
            ['orderNumber' => $orderNumber],
            false,
        );
    }

    private function includedTax(int $gross, int $basisPoints): int
    {
        $numerator = $gross * $basisPoints;
        $denominator = self::TAX_DENOMINATOR + $basisPoints;

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
