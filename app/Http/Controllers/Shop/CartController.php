<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request, CartService $cartService): View
    {
        $cart = $cartService->current($request);
        $cart->load('items.variant.product.images', 'items.variant.inventoryBalance');
        $subtotal = $cart->items->sum(
            fn (CartItem $item): int => $item->variant->price_minor * $item->quantity,
        );

        return view('shop.cart.index', compact('cart', 'subtotal'));
    }

    public function store(Request $request, CartService $cartService): RedirectResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:ec_product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
        $cartService->add(
            $cartService->current($request),
            (int) $validated['variant_id'],
            (int) $validated['quantity'],
        );

        return to_route('shop.cart.index')->with('status', '商品をカートに追加しました。');
    }

    public function update(Request $request, CartItem $item, CartService $cartService): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
        $cartService->update(
            $cartService->current($request),
            $item,
            (int) $validated['quantity'],
        );

        return to_route('shop.cart.index')->with('status', '数量を更新しました。');
    }

    public function destroy(Request $request, CartItem $item, CartService $cartService): RedirectResponse
    {
        $cartService->remove($cartService->current($request), $item);

        return to_route('shop.cart.index')->with('status', '商品をカートから削除しました。');
    }
}
