<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutConflictException;
use App\Services\CheckoutQuoteChangedException;
use App\Services\CheckoutService;
use App\Services\CheckoutSessionUnavailableException;
use App\Services\StripeCheckoutSessionService;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    private const NOTICE_STILL_OPEN = '決済の準備状況を確認できませんでした。入力内容と注文情報は保持されています。時間をおいて同じ画面から再試行してください。在庫予約は有効期限まで維持されます。';

    private const NOTICE_CLOSED = '決済を開始できなかったため、ご注文を取り消しました。在庫の予約は解除しています。入力内容をご確認のうえ、もう一度お試しください。';

    private const NOTICE_QUOTE_CHANGED = '価格・送料・税額などが、確認いただいた内容から変更されました。最新の内容をご確認のうえ、もう一度ご注文ください。';

    private const DETAIL_FIELDS = [
        'checkout_key', 'customer_name', 'customer_email', 'customer_phone', 'shipping_recipient',
        'shipping_country_code', 'shipping_postal_code', 'shipping_region', 'shipping_city',
        'shipping_address_line1', 'shipping_address_line2', 'shipping_rate_id',
    ];

    public function index(Request $request, CartService $cartService, CheckoutService $checkoutService): View
    {
        $cart = $cartService->current($request);
        $cart->load('items.variant.product', 'items.variant.inventoryBalance');
        $shippingRates = $checkoutService->availableShippingRates();
        $subtotal = $cart->items->sum(
            fn ($item): int => $item->variant->price_minor * $item->quantity,
        );
        $pendingOrder = $checkoutService->activeOrderFor($cart);

        if ($pendingOrder instanceof Order) {
            $request->session()->keep(['_old_input']);
        }

        return view('shop.checkout.index', [
            'cart' => $cart,
            'shippingRates' => $shippingRates,
            'subtotal' => $subtotal,
            'pendingOrder' => $pendingOrder,
            'checkoutKey' => $this->checkoutKeyFor($request, $cart, $checkoutService),
        ]);
    }

    public function confirm(
        Request $request,
        CartService $cartService,
        CheckoutService $checkoutService,
    ): View|RedirectResponse {
        $validated = $request->validate($this->detailRules($checkoutService));
        $cart = $cartService->current($request);

        if ($checkoutService->activeOrderFor($cart) instanceof Order) {
            return to_route('shop.checkout.index')->withInput();
        }

        return $this->confirmationPage($cart, $validated, $checkoutService);
    }

    /**
     * A reload or a failed validation can land on this address, which only accepts a form post.
     */
    public function showConfirm(Request $request): RedirectResponse
    {
        $request->session()->reflash();

        return to_route('shop.checkout.index');
    }

    public function edit(Request $request): RedirectResponse
    {
        return to_route('shop.checkout.index')->withInput($request->only(self::DETAIL_FIELDS));
    }

    public function store(
        Request $request,
        CartService $cartService,
        CheckoutService $checkoutService,
        StripeCheckoutSessionService $checkoutSessionService,
        ConcurrencyErrorDetector $concurrencyErrorDetector,
    ): View|RedirectResponse {
        $validated = $request->validate([
            ...$this->detailRules($checkoutService),
            'quote_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'quoted_total' => ['nullable', 'integer'],
        ]);

        if (! $request->boolean('terms_accepted')) {
            return $this->confirmationPage(
                $cartService->current($request),
                $validated,
                $checkoutService,
                termsError: 'ご注文には、利用規約と表示内容への同意が必要です。',
            );
        }
        $validated['terms_accepted'] = true;

        $user = $request->user();
        abort_unless($user === null || $user instanceof User, 403);
        $stripeSecret = config('services.stripe.ecommerce_secret');
        if (! is_string($stripeSecret) || $stripeSecret === '') {
            return to_route('shop.checkout.index')
                ->with('status', '決済サービスが準備中のため、注文を確定できません。時間をおいて再度お試しください。')
                ->withInput();
        }

        $cart = $cartService->current($request);
        try {
            $order = $checkoutService->reserveOrder($cart, $validated, $user);
        } catch (CheckoutQuoteChangedException) {
            return $this->confirmationPage($cart, $validated, $checkoutService);
        } catch (CheckoutConflictException) {
            return to_route('shop.checkout.index')
                ->with('status', 'お支払い待ちのご注文と、入力内容またはカートの内容が異なります。そのまま決済を再開するか、ご注文を取り消してから新しくご注文ください。')
                ->withInput();
        } catch (QueryException $exception) {
            if (! $concurrencyErrorDetector->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            report($exception);

            return to_route('shop.checkout.index')
                ->with('status', 'ご注文が混み合っています。入力内容は保持されていますので、もう一度お試しください。')
                ->withInput();
        }

        return $this->redirectToPayment($request, $order, $checkoutService, $checkoutSessionService);
    }

    public function resume(
        Request $request,
        CartService $cartService,
        CheckoutService $checkoutService,
        StripeCheckoutSessionService $checkoutSessionService,
    ): RedirectResponse {
        $order = $checkoutService->activeOrderFor($cartService->current($request));
        if (! $order instanceof Order) {
            return to_route('shop.checkout.index')->with('status', 'お支払い待ちのご注文はありません。');
        }

        return $this->redirectToPayment($request, $order, $checkoutService, $checkoutSessionService);
    }

    public function cancelPending(
        Request $request,
        CartService $cartService,
        CheckoutService $checkoutService,
        StripeCheckoutSessionService $checkoutSessionService,
    ): RedirectResponse {
        $input = Arr::except((array) $request->old(), ['checkout_key']);
        $order = $checkoutService->activeOrderFor($cartService->current($request));
        if (! $order instanceof Order) {
            return to_route('shop.checkout.index')->with('status', 'お支払い待ちのご注文はありません。')->withInput($input);
        }

        try {
            $cancelled = $checkoutSessionService->cancelPendingOrder($order);
        } catch (CheckoutSessionUnavailableException) {
            return to_route('shop.checkout.index')
                ->with('status', '決済の状況を確認できなかったため、ご注文を取り消せませんでした。時間をおいて再度お試しください。');
        }

        if (! $cancelled) {
            return to_route('shop.checkout.index')
                ->with('status', 'このご注文はお支払いが完了している可能性があるため、取り消せませんでした。確認が済むまでお待ちください。');
        }

        return to_route('shop.checkout.index')
            ->with('status', 'ご注文を取り消しました。在庫の予約を解除しています。カートの内容はそのまま残っています。')
            ->withInput($input);
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function confirmationPage(
        Cart $cart,
        array $validated,
        CheckoutService $checkoutService,
        ?string $termsError = null,
    ): View {
        $quote = $checkoutService->quote($cart, $validated);
        $hasChanged = isset($validated['quote_hash']) && ! hash_equals($quote['hash'], $validated['quote_hash']);

        return view('shop.checkout.confirm', [
            'details' => Arr::only($validated, self::DETAIL_FIELDS),
            'quote' => $quote,
            'termsError' => $termsError,
            'changedNotice' => $hasChanged ? self::NOTICE_QUOTE_CHANGED : null,
            'previousTotal' => $hasChanged && isset($validated['quoted_total']) ? (int) $validated['quoted_total'] : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function detailRules(CheckoutService $checkoutService): array
    {
        $availableCountries = $checkoutService->availableShippingRates()
            ->pluck('country_code')
            ->unique()
            ->all();

        return [
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
        ];
    }

    private function redirectToPayment(
        Request $request,
        Order $order,
        CheckoutService $checkoutService,
        StripeCheckoutSessionService $checkoutSessionService,
    ): RedirectResponse {
        try {
            $session = $checkoutSessionService->sessionFor($order);
        } catch (CheckoutSessionUnavailableException $exception) {
            if ($exception->orderStillOpen) {
                return to_route('shop.checkout.index')
                    ->with('status', self::NOTICE_STILL_OPEN)
                    ->withInput();
            }

            return to_route('shop.checkout.index')
                ->with('status', self::NOTICE_CLOSED)
                ->withInput($request->except('checkout_key'));
        }

        if ($session->status === 'complete') {
            return redirect()->to(
                route('shop.checkout.success', ['orderNumber' => $order->order_number]).'?session_id='.urlencode($session->id),
            );
        }

        if ($session->status === 'expired') {
            $checkoutService->releaseReservation($order, 'Stripe Checkout セッションの有効期限が切れていました。');

            return to_route('shop.checkout.index')
                ->with('status', self::NOTICE_CLOSED)
                ->withInput($request->except('checkout_key'));
        }

        if (! is_string($session->url) || $session->url === '') {
            abort(503, '決済ページを準備できませんでした。時間をおいて再度お試しください。');
        }

        return redirect()->away($session->url);
    }

    /**
     * The key lives in the session rather than the page, so reloading cannot start a second order.
     */
    private function checkoutKeyFor(Request $request, Cart $cart, CheckoutService $checkoutService): string
    {
        $sessionKey = 'checkout_key.'.$cart->id;
        $checkoutKey = old('checkout_key') ?? $request->session()->get($sessionKey);

        if (
            ! is_string($checkoutKey)
            || preg_match('/\A[a-f0-9]{64}\z/', $checkoutKey) !== 1
            || $checkoutService->checkoutKeyIsClosed($checkoutKey)
        ) {
            $checkoutKey = bin2hex(random_bytes(32));
        }

        $request->session()->put($sessionKey, $checkoutKey);

        return $checkoutKey;
    }
}
