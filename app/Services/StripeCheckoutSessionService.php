<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Throwable;

/**
 * Creates at most one payable Checkout Session per order, whatever happens to the responses.
 *
 * Each attempt saves its exact request before sending it. Stripe rejects a reused idempotency key
 * whose parameters differ, so a resend must match byte for byte, including expires_at. A new key
 * is used only once the old one is known to have made no session, as Stripe advises for results
 * that are indeterminate. No database lock is held during any Stripe call.
 */
class StripeCheckoutSessionService
{
    private const MAX_ATTEMPTS = 3;

    private const MAX_RESERVATION_MINUTES = 60;

    private const SESSION_LIFETIME_SECONDS = 1830;

    /**
     * Longer than the SDK's request timeout, so a live request is never mistaken for an abandoned one.
     */
    private const CLAIM_SECONDS = 120;

    private const NEW_ATTEMPT_GRACE_SECONDS = 60;

    private const SCAN_LOOKBACK_SECONDS = 300;

    private const SCAN_MAX_PAGES = 5;

    public function __construct(
        private CheckoutService $checkoutService,
        private StripeBillingService $stripeBillingService,
    ) {}

    /**
     * @throws CheckoutSessionUnavailableException
     */
    public function sessionFor(Order $order): Session
    {
        $stripe = $this->stripeBillingService->client();
        [$payment, $wasResend] = $this->claim($order);

        if (is_string($payment->provider_checkout_id)) {
            try {
                return $stripe->checkout->sessions->retrieve($payment->provider_checkout_id);
            } catch (ApiErrorException $exception) {
                report($exception);

                throw new CheckoutSessionUnavailableException(orderStillOpen: true);
            }
        }

        if ($payment->checkout_state === Payment::CHECKOUT_NEEDS_NEW_ATTEMPT) {
            return $this->startNextAttempt($stripe, $order, $payment);
        }

        return $this->submit($stripe, $order, $payment, $wasResend);
    }

    /**
     * Cancel an order awaiting payment, first making sure Stripe can no longer take money for it.
     *
     * @return bool False when Stripe shows it was paid, so it must not be cancelled.
     *
     * @throws CheckoutSessionUnavailableException When Stripe's state cannot be confirmed right now.
     */
    public function cancelPendingOrder(Order $order): bool
    {
        $payment = $order->payments()->where('provider', 'stripe')->firstOrFail();

        if ($this->hasLiveClaim($payment)) {
            throw new CheckoutSessionUnavailableException(orderStillOpen: true);
        }

        $session = $this->sessionToCloseFor($order, $payment);
        if ($session?->status === 'open') {
            $session = $this->expireOrFetch($session->id);
        }
        if ($session?->status === 'complete') {
            return false;
        }

        $this->checkoutService->releaseReservation($order, '購入者の操作により注文を取り消しました。');

        if ($order->fresh()->status === 'cancelled') {
            OrderEvent::query()->create([
                'order_id' => $order->id,
                'event_type' => 'customer_cancelled',
                'from_status' => 'pending_payment',
                'to_status' => 'cancelled',
                'from_payment_status' => 'pending',
                'to_payment_status' => 'failed',
            ]);
        }

        return true;
    }

    public function hasLiveClaim(Payment $payment): bool
    {
        return $payment->checkout_state === Payment::CHECKOUT_CREATING
            && $payment->checkout_claimed_at?->isAfter(now()->subSeconds(self::CLAIM_SECONDS));
    }

    /**
     * @return Session|null The session Stripe holds for this payment, or null when none can be paid.
     *
     * @throws CheckoutSessionUnavailableException When Stripe's state cannot be confirmed right now.
     */
    public function sessionToCloseFor(Order $order, Payment $payment): ?Session
    {
        $stripe = $this->stripeBillingService->client();

        if (is_string($payment->provider_checkout_id)) {
            try {
                return $stripe->checkout->sessions->retrieve($payment->provider_checkout_id);
            } catch (ApiErrorException $exception) {
                report($exception);

                throw new CheckoutSessionUnavailableException(orderStillOpen: true);
            }
        }

        if ($payment->checkout_state === Payment::CHECKOUT_NOT_STARTED) {
            return null;
        }

        return $this->findExistingSession($stripe, $order, $payment);
    }

    /**
     * The customer may finish paying at the moment of expiry, so a refusal is checked, not assumed.
     *
     * @throws CheckoutSessionUnavailableException When Stripe's state cannot be confirmed right now.
     */
    public function expireOrFetch(string $sessionId): Session
    {
        $stripe = $this->stripeBillingService->client();

        try {
            return $stripe->checkout->sessions->expire($sessionId);
        } catch (ApiErrorException) {
            try {
                return $stripe->checkout->sessions->retrieve($sessionId);
            } catch (ApiErrorException $exception) {
                report($exception);

                throw new CheckoutSessionUnavailableException(orderStillOpen: true);
            }
        }
    }

    /**
     * @return array{0: Payment, 1: bool}
     */
    private function claim(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payment = $lockedOrder->payments()->where('provider', 'stripe')->lockForUpdate()->firstOrFail();

            if (is_string($payment->provider_checkout_id)) {
                return [$payment, false];
            }

            if ($lockedOrder->status !== 'pending_payment') {
                throw new CheckoutSessionUnavailableException(orderStillOpen: false);
            }

            if ($this->hasLiveClaim($payment) || $payment->checkout_state === Payment::CHECKOUT_EXHAUSTED) {
                throw new CheckoutSessionUnavailableException(orderStillOpen: true);
            }

            if ($payment->checkout_state === Payment::CHECKOUT_NEEDS_NEW_ATTEMPT) {
                return [$payment, false];
            }

            $wasResend = $payment->checkout_state !== Payment::CHECKOUT_NOT_STARTED;
            if (! $wasResend) {
                $this->prepareAttempt($lockedOrder, $payment, 1);
            }
            $payment->checkout_state = Payment::CHECKOUT_CREATING;
            $payment->checkout_claimed_at = now();
            $payment->save();

            return [$payment, $wasResend];
        });
    }

    /**
     * The caller must hold the order and payment locks and save the payment.
     */
    private function prepareAttempt(Order $order, Payment $payment, int $attempt): void
    {
        $expiresAt = now()->addSeconds(self::SESSION_LIFETIME_SECONDS);

        $payment->checkout_attempt = $attempt;
        $payment->checkout_attempt_started_at = now();
        $payment->checkout_request_payload = $this->buildRequest($order, $payment, $attempt, $expiresAt->timestamp);
        $payment->idempotency_key = preg_replace('/-a\d+\z/', '', $payment->idempotency_key).($attempt > 1 ? '-a'.$attempt : '');

        // The reservation must outlive the session, or the expiry job could release stock
        // that a customer is about to pay for.
        $order->reservation_expires_at = $expiresAt;
        $order->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequest(Order $order, Payment $payment, int $attempt, int $expiresAt): array
    {
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

        return [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'customer_email' => $order->customer_email,
            'client_reference_id' => $order->order_number,
            'metadata' => [
                'ec_order_id' => (string) $order->id,
                'ec_payment_id' => (string) $payment->id,
                'ec_checkout_attempt' => (string) $attempt,
            ],
            'payment_intent_data' => [
                'metadata' => ['ec_order_id' => (string) $order->id],
            ],
            'expires_at' => $expiresAt,
            'success_url' => route('shop.checkout.success', [
                'orderNumber' => $order->order_number,
            ]).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('shop.checkout.cancel'),
        ];
    }

    private function submit(StripeClient $stripe, Order $order, Payment $payment, bool $wasResend, bool $mayStartNextAttempt = true): Session
    {
        try {
            $session = $stripe->checkout->sessions->create($payment->checkout_request_payload, [
                'idempotency_key' => $payment->idempotency_key,
            ]);
        } catch (ApiErrorException $exception) {
            return $this->handleStripeFailure($stripe, $order, $payment, $exception, $wasResend, $mayStartNextAttempt);
        } catch (Throwable $exception) {
            report($exception);

            throw $this->closeOrder($order, $payment, 'Stripe Checkout セッションを作成できませんでした。');
        }

        if (! is_string($session->url) || $session->url === '' || ! is_int($session->expires_at)) {
            report(new RuntimeException('Stripe Checkout session did not include a URL and expiration.'));

            throw $this->closeOrder($order, $payment, 'Stripe Checkout の応答が不完全でした。');
        }

        return $this->saveSession($stripe, $order, $payment, $session);
    }

    private function handleStripeFailure(
        StripeClient $stripe,
        Order $order,
        Payment $payment,
        ApiErrorException $exception,
        bool $wasResend,
        bool $mayStartNextAttempt,
    ): Session {
        if ($exception->getStripeCode() === 'idempotency_key_in_use' || $exception instanceof RateLimitException) {
            $this->setCheckoutState($payment, Payment::CHECKOUT_UNCERTAIN);

            throw new CheckoutSessionUnavailableException(orderStillOpen: true);
        }

        report($exception);

        if ($exception instanceof IdempotencyException) {
            $this->setCheckoutState($payment, Payment::CHECKOUT_UNCERTAIN);

            throw new CheckoutSessionUnavailableException(orderStillOpen: true);
        }

        if ($this->isExpiryRejection($exception) && $mayStartNextAttempt) {
            return $this->startNextAttempt($stripe, $order, $payment);
        }

        if ($this->outcomeIsUnknown($exception)) {
            return $this->handleUnknownOutcome($stripe, $order, $payment, $wasResend);
        }

        throw $this->closeOrder($order, $payment, 'Stripeが決済の開始を拒否しました。');
    }

    /**
     * A first failure is resent as is. A resend that also fails may be a stored server error that
     * will never change, so the result is looked up instead.
     */
    private function handleUnknownOutcome(StripeClient $stripe, Order $order, Payment $payment, bool $wasResend): Session
    {
        $this->setCheckoutState($payment, Payment::CHECKOUT_UNCERTAIN);

        if ($wasResend) {
            $existing = $this->findExistingSession($stripe, $order, $payment);
            if ($existing instanceof Session) {
                return $this->saveSession($stripe, $order, $payment, $existing);
            }

            if ($payment->checkout_attempt_started_at?->isBefore(now()->subSeconds(self::NEW_ATTEMPT_GRACE_SECONDS))) {
                $this->setCheckoutState($payment, Payment::CHECKOUT_NEEDS_NEW_ATTEMPT);
            }
        }

        throw new CheckoutSessionUnavailableException(orderStillOpen: true);
    }

    private function startNextAttempt(StripeClient $stripe, Order $order, Payment $payment): Session
    {
        $existing = $this->findExistingSession($stripe, $order, $payment);
        if ($existing instanceof Session) {
            return $this->saveSession($stripe, $order, $payment, $existing);
        }

        $nextAttempt = DB::transaction(function () use ($order, $payment): ?Payment {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedOrder->status !== 'pending_payment') {
                throw new CheckoutSessionUnavailableException(orderStillOpen: false);
            }

            $latestAllowedExpiry = $lockedOrder->created_at->copy()->addMinutes(self::MAX_RESERVATION_MINUTES);
            if (
                $lockedPayment->checkout_attempt >= self::MAX_ATTEMPTS
                || now()->addSeconds(self::SESSION_LIFETIME_SECONDS)->isAfter($latestAllowedExpiry)
            ) {
                $lockedPayment->checkout_state = Payment::CHECKOUT_EXHAUSTED;
                $lockedPayment->save();

                return null;
            }

            $this->prepareAttempt($lockedOrder, $lockedPayment, $lockedPayment->checkout_attempt + 1);
            $lockedPayment->checkout_state = Payment::CHECKOUT_CREATING;
            $lockedPayment->checkout_claimed_at = now();
            $lockedPayment->save();

            return $lockedPayment;
        });

        if (! $nextAttempt instanceof Payment) {
            throw new CheckoutSessionUnavailableException(orderStillOpen: true);
        }

        return $this->submit($stripe, $order, $nextAttempt, wasResend: false, mayStartNextAttempt: false);
    }

    /**
     * Sessions cannot be filtered by metadata, so recent ones are listed and matched here.
     *
     * @return Session|null An open or completed session already created for this payment.
     */
    private function findExistingSession(StripeClient $stripe, Order $order, Payment $payment): ?Session
    {
        $found = [];
        $query = ['created' => ['gte' => $order->created_at->timestamp - self::SCAN_LOOKBACK_SECONDS], 'limit' => 100];

        try {
            for ($page = 0; $page < self::SCAN_MAX_PAGES; $page++) {
                $listing = $stripe->checkout->sessions->all($query);
                foreach ($listing->data as $session) {
                    if (
                        ($session->metadata->ec_payment_id ?? null) === (string) $payment->id
                        && in_array($session->status, ['open', 'complete'], true)
                    ) {
                        $found[] = $session;
                    }
                }

                if (! $listing->has_more || $listing->data === []) {
                    break;
                }
                $query['starting_after'] = end($listing->data)->id;
            }
        } catch (ApiErrorException $exception) {
            report($exception);
            $this->setCheckoutState($payment, Payment::CHECKOUT_UNCERTAIN);

            throw new CheckoutSessionUnavailableException(orderStillOpen: true);
        }

        usort($found, fn (Session $first, Session $second): int => $this->sessionRank($first) <=> $this->sessionRank($second));
        $chosen = array_shift($found);

        foreach ($found as $extra) {
            if ($extra->status === 'open') {
                $this->expireQuietly($stripe, $extra->id);
            }
        }

        return $chosen;
    }

    /**
     * A paid session must win over an open one, because the customer's money is on it.
     */
    private function sessionRank(Session $session): int
    {
        return $session->status === 'complete' ? 0 : 1;
    }

    private function saveSession(StripeClient $stripe, Order $order, Payment $payment, Session $session): Session
    {
        if ($this->offersMoreThanCards($session)) {
            $this->expireQuietly($stripe, $session->id);
            report(new RuntimeException('Stripe offered payment methods other than card. Check the Dashboard settings.'));

            throw $this->closeOrder($order, $payment, 'カード以外の決済方法が有効なため、決済を開始しませんでした。');
        }

        $orderIsOpen = DB::transaction(function () use ($order, $payment, $session): bool {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedOrder->status !== 'pending_payment') {
                return false;
            }

            $lockedPayment->provider_checkout_id = $session->id;
            $lockedPayment->provider_status = $session->status;
            $lockedPayment->checkout_state = Payment::CHECKOUT_CREATED;
            $lockedPayment->checkout_request_payload = null;
            $lockedPayment->save();
            $lockedOrder->reservation_expires_at = now()->setTimestamp($session->expires_at);
            $lockedOrder->save();

            return true;
        });

        if (! $orderIsOpen) {
            if ($session->status === 'open') {
                $this->expireQuietly($stripe, $session->id);
            }

            throw new CheckoutSessionUnavailableException(orderStillOpen: false);
        }

        return $session;
    }

    /**
     * Stripe no longer accepts payment methods on the request, so the Dashboard decides what a
     * session offers. Apple Pay is reported as card. A completed session is exempt because the
     * customer has already paid and that payment must still be recorded.
     */
    private function offersMoreThanCards(Session $session): bool
    {
        return $session->status === 'open' && $session->payment_method_types !== ['card'];
    }

    private function closeOrder(Order $order, Payment $payment, string $reason): CheckoutSessionUnavailableException
    {
        $this->checkoutService->releaseReservation($order, $reason);

        $payment->refresh();
        $payment->checkout_state = Payment::CHECKOUT_FAILED;
        $payment->checkout_request_payload = null;
        $payment->save();

        return new CheckoutSessionUnavailableException(orderStillOpen: false);
    }

    private function setCheckoutState(Payment $payment, string $state): void
    {
        $payment->checkout_state = $state;
        $payment->save();
    }

    private function expireQuietly(StripeClient $stripe, string $sessionId): void
    {
        try {
            $stripe->checkout->sessions->expire($sessionId);
        } catch (ApiErrorException $exception) {
            report($exception);
        }
    }

    /**
     * Stripe reports this with no error code or parameter, so the message is all there is to match.
     */
    private function isExpiryRejection(ApiErrorException $exception): bool
    {
        return $exception instanceof InvalidRequestException
            && str_contains($exception->getMessage(), 'expires_at');
    }

    /**
     * A connection failure or server error may have created a session. Anything else is a decision.
     */
    private function outcomeIsUnknown(ApiErrorException $exception): bool
    {
        return $exception->getHttpStatus() === null
            || $exception->getHttpStatus() >= 500;
    }
}
