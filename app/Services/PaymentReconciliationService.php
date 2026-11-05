<?php

namespace App\Services;

use App\Jobs\SendAdminOrderAlert;
use App\Mail\AdminOrderAlertMail;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Brings orders back in line with Stripe when webhooks are late or never arrive.
 *
 * Stripe is the source of truth, so stock is released only once Stripe confirms a session cannot be
 * paid. While Stripe is unreachable nothing is released. No lock is held during any Stripe call,
 * and every change is re-checked under lock so a webhook arriving at the same moment cannot apply twice.
 */
class PaymentReconciliationService
{
    /**
     * Gives a webhook for a payment made at the last moment time to arrive first.
     */
    private const EXPIRY_GRACE_SECONDS = 120;

    private const STUCK_AFTER_MINUTES = 30;

    private const MISSED_WEBHOOK_AFTER_MINUTES = 10;

    private const REFUND_CHECK_AFTER_MINUTES = 10;

    private const REVIEW_OVERDUE_AFTER_HOURS = 24;

    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private CheckoutService $checkoutService,
        private PaymentSettlementService $settlementService,
        private StripeCheckoutSessionService $checkoutSessionService,
        private StripeBillingService $stripeBillingService,
    ) {}

    public function run(): void
    {
        $this->eachOrder(
            Order::query()
                ->where('status', 'pending_payment')
                ->whereNotNull('reservation_expires_at')
                ->where('reservation_expires_at', '<=', now()),
            fn (Order $order) => $this->closeExpiredOrder($order),
        );

        $this->eachOrder(
            Order::query()
                ->where('status', 'pending_payment')
                ->where('reservation_expires_at', '>', now())
                ->where('created_at', '<=', now()->subMinutes(self::MISSED_WEBHOOK_AFTER_MINUTES))
                ->whereHas('payments', fn ($query) => $query->whereNotNull('provider_checkout_id')),
            fn (Order $order) => $this->recordMissedPayment($order),
        );

        $this->eachOrder(
            Order::query()
                ->where('payment_status', 'refund_pending')
                ->where('updated_at', '<=', now()->subMinutes(self::REFUND_CHECK_AFTER_MINUTES)),
            fn (Order $order) => $this->settlePendingRefund($order),
        );

        $this->eachOrder(
            Order::query()
                ->where('status', 'payment_review')
                ->whereNull('review_resolved_at')
                ->where('review_opened_at', '<=', now()->subHours(self::REVIEW_OVERDUE_AFTER_HOURS)),
            fn (Order $order) => SendAdminOrderAlert::dispatch($order->id, AdminOrderAlertMail::ALERT_REVIEW_OVERDUE),
        );
    }

    /**
     * One order failing must not stop the others from being reconciled.
     *
     * @param  Builder<Order>  $orders
     * @param  callable(Order): void  $reconcile
     */
    private function eachOrder(Builder $orders, callable $reconcile): void
    {
        $orders->orderBy('id')->chunkById(100, function ($chunk) use ($reconcile): void {
            foreach ($chunk as $order) {
                try {
                    $reconcile($order);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    private function closeExpiredOrder(Order $order): void
    {
        $payment = $this->paymentFor($order);

        if ($payment->checkout_state === Payment::CHECKOUT_NOT_STARTED) {
            $this->release($order);

            return;
        }

        if (
            $order->reservation_expires_at->copy()->addSeconds(self::EXPIRY_GRACE_SECONDS)->isFuture()
            || $this->checkoutSessionService->hasLiveClaim($payment)
        ) {
            return;
        }

        try {
            $session = $this->checkoutSessionService->sessionToCloseFor($order, $payment);
            if ($session?->status === 'open') {
                $session = $this->checkoutSessionService->expireOrFetch($session->id);
            }
        } catch (CheckoutSessionUnavailableException) {
            $this->alertIfStuck($order);

            return;
        }

        if ($session === null || $session->status === 'expired') {
            $this->release($order);

            return;
        }

        if ($session->status === 'complete') {
            $this->recordCompletedSession($order, $session);
        }
    }

    private function recordMissedPayment(Order $order): void
    {
        $payment = $this->paymentFor($order);

        try {
            $session = $this->stripeBillingService->client()->checkout->sessions->retrieve($payment->provider_checkout_id);
        } catch (ApiErrorException $exception) {
            report($exception);

            return;
        }

        if ($session->status === 'complete') {
            $this->recordCompletedSession($order, $session);
        }
    }

    private function recordCompletedSession(Order $order, Session $session): void
    {
        if ($session->payment_status !== 'paid') {
            SendAdminOrderAlert::dispatch($order->id, AdminOrderAlertMail::ALERT_UNPAID_COMPLETE);

            return;
        }

        DB::transaction(function () use ($order, $session): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedPayment = $lockedOrder->payments()->where('provider', 'stripe')->lockForUpdate()->firstOrFail();

            if (
                $lockedOrder->status !== 'pending_payment'
                || (string) ($session->metadata->ec_payment_id ?? null) !== (string) $lockedPayment->id
                || ($session->client_reference_id ?? null) !== $lockedOrder->order_number
            ) {
                return;
            }

            $lockedPayment->provider_checkout_id = $session->id;
            $lockedPayment->checkout_state = Payment::CHECKOUT_CREATED;
            $lockedPayment->checkout_request_payload = null;
            $lockedPayment->save();

            $this->settlementService->applyPaidSession($lockedOrder, $lockedPayment, $session);

            OrderEvent::query()->create([
                'order_id' => $lockedOrder->id,
                'event_type' => 'payment_reconciled',
                'from_status' => 'pending_payment',
                'to_status' => $lockedOrder->status,
                'from_payment_status' => 'pending',
                'to_payment_status' => $lockedOrder->payment_status,
                'note' => "session={$session->id}",
            ]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function settlePendingRefund(Order $order): void
    {
        $payment = $this->paymentFor($order);
        if (! is_string($payment->provider_payment_id)) {
            return;
        }

        try {
            $refunds = $this->stripeBillingService->client()->refunds->all([
                'payment_intent' => $payment->provider_payment_id,
                'limit' => 100,
            ])->data;
        } catch (ApiErrorException $exception) {
            report($exception);

            return;
        }

        $statuses = array_map(fn ($refund): ?string => $refund->status, $refunds);
        $refunded = array_sum(array_map(
            fn ($refund): int => $refund->status === 'succeeded' ? (int) $refund->amount : 0,
            $refunds,
        ));

        DB::transaction(function () use ($order, $statuses, $refunded): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedPayment = $lockedOrder->payments()->where('provider', 'stripe')->lockForUpdate()->firstOrFail();

            if ($lockedOrder->payment_status !== 'refund_pending') {
                return;
            }

            if ($refunded >= $lockedPayment->refundableAmount()) {
                $this->settlementService->completeRefund($lockedOrder, $lockedPayment);
            } elseif (array_intersect($statuses, ['pending', 'requires_action']) !== []) {
                return;
            } elseif ($refunded > 0) {
                $this->settlementService->flagPartialRefund($lockedOrder, $lockedPayment);
            } elseif ($statuses !== []) {
                $this->settlementService->revertFailedRefund($lockedOrder, $lockedPayment);
            }
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function alertIfStuck(Order $order): void
    {
        if ($order->reservation_expires_at->copy()->addMinutes(self::STUCK_AFTER_MINUTES)->isPast()) {
            SendAdminOrderAlert::dispatch($order->id, AdminOrderAlertMail::ALERT_RECONCILE_STUCK);
        }
    }

    private function release(Order $order): void
    {
        $this->checkoutService->releaseReservation($order, '決済期限を過ぎたため在庫予約を解除しました。');
    }

    private function paymentFor(Order $order): Payment
    {
        return $order->payments()->where('provider', 'stripe')->firstOrFail();
    }
}
