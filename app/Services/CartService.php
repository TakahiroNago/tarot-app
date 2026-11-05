<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    private const GUEST_COOKIE = 'ec_cart';

    private const CART_TTL_DAYS = 30;

    public function current(Request $request): Cart
    {
        $user = $request->user();
        if ($user instanceof User) {
            $now = now();
            DB::table('ec_carts')->insertOrIgnore([
                'user_id' => $user->id,
                'guest_token_hash' => null,
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Cart::query()->where('user_id', $user->id)->firstOrFail();
        }

        $token = $request->cookie(self::GUEST_COOKIE);
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            Cookie::queue(cookie(
                self::GUEST_COOKIE,
                $token,
                60 * 24 * self::CART_TTL_DAYS,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ));
        }

        $tokenHash = hash('sha256', $token);
        $cart = Cart::query()
            ->where('guest_token_hash', $tokenHash)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($cart instanceof Cart) {
            return $cart;
        }

        $now = now();
        DB::table('ec_carts')->insertOrIgnore([
            'user_id' => null,
            'guest_token_hash' => $tokenHash,
            'expires_at' => $now->copy()->addDays(self::CART_TTL_DAYS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cart = Cart::query()->where('guest_token_hash', $tokenHash)->firstOrFail();
        if ($cart->expires_at !== null && $cart->expires_at->isPast()) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            Cookie::queue(cookie(
                self::GUEST_COOKIE,
                $token,
                60 * 24 * self::CART_TTL_DAYS,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ));
            $now = now();
            DB::table('ec_carts')->insertOrIgnore([
                'user_id' => null,
                'guest_token_hash' => $tokenHash,
                'expires_at' => $now->copy()->addDays(self::CART_TTL_DAYS),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Cart::query()->where('guest_token_hash', $tokenHash)->firstOrFail();
        }

        return $cart;
    }

    public function add(Cart $cart, int $variantId, int $quantity): CartItem
    {
        return DB::transaction(function () use ($cart, $variantId, $quantity): CartItem {
            if ($quantity < 1 || $quantity > 99) {
                throw ValidationException::withMessages([
                    'quantity' => '数量は1〜99個で指定してください。',
                ]);
            }

            Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $variant = ProductVariant::query()->sharedLock()->findOrFail($variantId);
            $balance = InventoryBalance::query()->lockForUpdate()->find($variant->id);
            if (
                ! $variant->is_active
                || $variant->product()->where('status', 'published')->doesntExist()
                || $balance === null
            ) {
                throw ValidationException::withMessages([
                    'variant_id' => 'この商品は現在カートに追加できません。',
                ]);
            }

            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('variant_id', $variant->id)
                ->lockForUpdate()
                ->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;

            if ($newQuantity > $balance->available()) {
                throw ValidationException::withMessages([
                    'quantity' => '在庫数を超える数量はカートに追加できません。',
                ]);
            }

            if ($item === null) {
                return $cart->items()->create([
                    'variant_id' => $variant->id,
                    'quantity' => $newQuantity,
                ]);
            }

            $item->quantity = $newQuantity;
            $item->save();

            return $item;
        });
    }

    public function update(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        abort_unless($item->cart_id === $cart->id, 404);
        if ($quantity < 1 || $quantity > 99) {
            throw ValidationException::withMessages([
                'quantity' => '数量は1〜99個で指定してください。',
            ]);
        }

        return DB::transaction(function () use ($cart, $item, $quantity): CartItem {
            Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $balance = InventoryBalance::query()->lockForUpdate()->find($item->variant_id);
            if ($balance === null || $quantity > $balance->available()) {
                throw ValidationException::withMessages([
                    'quantity' => '指定した数量の在庫がありません。',
                ]);
            }

            $item->quantity = $quantity;
            $item->save();

            return $item;
        });
    }

    public function remove(Cart $cart, CartItem $item): void
    {
        abort_unless($item->cart_id === $cart->id, 404);
        $item->delete();
    }

    /**
     * Take a paid order's quantities out of its cart, leaving anything added since.
     */
    public function removeOrderedItems(int $cartId, Order $order): void
    {
        DB::transaction(function () use ($cartId, $order): void {
            Cart::query()->lockForUpdate()->find($cartId);

            foreach ($order->items()->whereNotNull('variant_id')->orderBy('variant_id')->get() as $orderItem) {
                $cartItem = CartItem::query()
                    ->where('cart_id', $cartId)
                    ->where('variant_id', $orderItem->variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($cartItem === null) {
                    continue;
                }

                if ($cartItem->quantity > $orderItem->quantity) {
                    $cartItem->quantity -= $orderItem->quantity;
                    $cartItem->save();
                } else {
                    $cartItem->delete();
                }
            }
        });
    }
}
