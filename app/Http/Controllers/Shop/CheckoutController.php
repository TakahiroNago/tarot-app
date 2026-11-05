<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\CheckoutSessionUnavailableException;
use App\Services\StripeCheckoutSessionService;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function index(Request $request, CartService $cartService, CheckoutService $checkoutService): View
    {
        $cart = $cartService->current($request);
        $cart->load('items.variant.product', 'items.variant.inventoryBalance');
        $shippingRates = $checkoutService->availableShippingRates();
        $subtotal = $cart->items->sum(
            fn ($item): int => $item->variant->price_minor * $item->quantity,
        );

        return view('shop.checkout.index', [
            'cart' => $cart,
            'shippingRates' => $shippingRates,
            'subtotal' => $subtotal,
            'checkoutKey' => old('checkout_key', bin2hex(random_bytes(32))),
        ]);
    }

    public function store(
        Request $request,
        CartService $cartService,
        CheckoutService $checkoutService,
        StripeCheckoutSessionService $checkoutSessionService,
        ConcurrencyErrorDetector $concurrencyErrorDetector,
    ): RedirectResponse {
        $availableCountries = $checkoutService->availableShippingRates()
            ->pluck('country_code')
            ->unique()
            ->all();
        $validated = $request->validate([
            'checkout_key' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email:rfc', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'shipping_recipient' => ['required', 'string', 'max:255'],
            'shipping_country_code' => ['required', 'string', 'size:2', Rule::in($availableCountries)],
            'shipping_postal_code' => ['required', 'string', 'max:40'],
            'shipping_region' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:255'],
            'shipping_address_line1' => ['required', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_rate_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        abort_unless($user === null || $user instanceof User, 403);
        $stripeSecret = config('services.stripe.secret');
        if (! is_string($stripeSecret) || $stripeSecret === '') {
            return to_route('shop.checkout.index')
                ->with('status', '決済サービスが準備中のため、注文を確定できません。時間をおいて再度お試しください。')
                ->withInput();
        }

        $cart = $cartService->current($request);
        try {
            $order = $checkoutService->reserveOrder($cart, $validated, $user);
        } catch (QueryException $exception) {
            if (! $concurrencyErrorDetector->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            report($exception);

            return to_route('shop.checkout.index')
                ->with('status', 'ご注文が混み合っています。入力内容は保持されていますので、もう一度お試しください。')
                ->withInput();
        }

        try {
            $session = $checkoutSessionService->sessionFor($order);
        } catch (CheckoutSessionUnavailableException $exception) {
            if ($exception->orderStillOpen) {
                return to_route('shop.checkout.index')
                    ->with('status', '決済の準備状況を確認できませんでした。入力内容と注文情報は保持されています。時間をおいて同じ画面から再試行してください。在庫予約は有効期限まで維持されます。')
                    ->withInput();
            }

            return to_route('shop.checkout.index')
                ->with('status', '決済を開始できなかったため、ご注文を取り消しました。在庫の予約は解除しています。入力内容をご確認のうえ、もう一度お試しください。')
                ->withInput($request->except('checkout_key'));
        }
        $cart->items()->delete();

        if (! is_string($session->url) || $session->url === '') {
            abort(503, '決済ページを準備できませんでした。時間をおいて再度お試しください。');
        }

        return redirect()->away($session->url);
    }

    public function success(Request $request, string $orderNumber, CheckoutService $checkoutService): View
    {
        $sessionId = $request->query('session_id');
        abort_unless(is_string($sessionId) && $sessionId !== '', 404);
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->whereHas('payments', fn ($query) => $query
                ->where('provider', 'stripe')
                ->where('provider_checkout_id', $sessionId))
            ->firstOrFail();
        $guestOrderUrl = $order->user_id === null && $order->payment_status === 'paid'
            ? $checkoutService->guestOrderUrl($order)
            : null;

        return view('shop.checkout.complete', compact('order', 'guestOrderUrl'));
    }

    public function cancel(): RedirectResponse
    {
        return to_route('shop.checkout.index')
            ->with('status', '決済は完了していません。在庫は予約期限まで確保されています。');
    }
}
