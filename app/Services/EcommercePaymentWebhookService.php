<?php

namespace App\Services;

use App\Jobs\SendAdminWebhookAlert;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Event;
use Stripe\StripeObject;
use Throwable;

/**
 * Records every Stripe event once and applies the ones that belong to an ecommerce order.
 *
 * An event that can never be applied is recorded and answered with 200, because Stripe would
 * otherwise retry it for days and may disable the endpoint. Only a failure that a retry could
 * fix, such as a database error or a refund arriving before its payment, reaches Stripe as a 500.
 */
class EcommercePaymentWebhookService
{
    private const TRANSACTION_ATTEMPTS = 3;

    private const STATUS_PROCESSED = 'processed';

    private const STATUS_IGNORED = 'ignored';

    private const STATUS_UNMATCHED = 'unmatched';

    private const SESSION_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
    ];

    private const REFUND_EVENTS = ['refund.updated', 'charge.refunded'];

    public function __construct(
        private CheckoutService $checkoutService,
        private PaymentSettlementService $settlementService,
        private StripeBillingService $stripeBillingService,
    ) {}

    public function handle(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            $inserted = DB::table('ec_payment_webhook_events')->insertOrIgnore([
                'provider' => 'stripe',
                'event_id' => $event->id,
                'event_type' => $event->type,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return;
            }

            if ($this->isFromTheWrongStripeMode($event)) {
                $this->flagUnapplied($event, self::STATUS_IGNORED, 'The event mode does not match this environment.');

                return;
            }

            if (in_array($event->type, self::SESSION_EVENTS, true)) {
                $this->handleSessionEvent($event);
            } elseif (in_array($event->type, self::REFUND_EVENTS, true)) {
                $this->handleRefundEvent($event);
            } else {
                $this->record($event, self::STATUS_IGNORED, 'This event type is not handled.');
            }
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * A test event must never change a live order, or the reverse.
     */
    private function isFromTheWrongStripeMode(Event $event): bool
    {
        $secret = config('services.stripe.ecommerce_secret');
        if (! is_string($secret) || preg_match('/\A[a-z]+_(live|test)_/', $secret, $matches) !== 1) {
            return false;
        }

        return ($matches[1] === 'live') !== (bool) ($event->livemode ?? false);
    }

    private function handleSessionEvent(Event $event): void
    {
        $session = $event->data->object;
        if (! $session instanceof StripeObject || ! is_string($session->id ?? null)) {
            $this->flagUnapplied($event, self::STATUS_UNMATCHED, 'The event did not include a checkout session.');

            return;
        }

        $reference = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_checkout_id', $session->id)
            ->first(['id', 'order_id']);
        $isSavedSession = $reference instanceof Payment;

        if (! $isSavedSession) {
            $paymentId = $session->metadata->ec_payment_id ?? null;
            if (! is_string($paymentId) || $paymentId === '') {
                $this->record($event, self::STATUS_IGNORED, 'The session has no ecommerce metadata.');

                return;
            }

            $reference = Payment::query()->where('provider', 'stripe')->whereKey($paymentId)->first(['id', 'order_id']);
            if (! $reference instanceof Payment) {
                $this->flagUnapplied($event, self::STATUS_UNMATCHED, "No payment matches session {$session->id}.");

                return;
            }
        }

        [$order, $payment] = $this->lockOrderAndPayment($reference);

        if (
            (string) $order->id !== (string) ($session->metadata->ec_order_id ?? null)
            || (string) $payment->id !== (string) ($session->metadata->ec_payment_id ?? null)
            || ($session->client_reference_id ?? null) !== $order->order_number
        ) {
            $this->flagUnapplied($event, self::STATUS_UNMATCHED, "Session {$session->id} does not match order {$order->order_number}.");

            return;
        }

        if (! $isSavedSession) {
            $this->handleUnsavedSession($event, $order, $payment, $session);

            return;
        }

        if (in_array($event->type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
            $this->checkoutService->releaseReservation($order, 'Stripe決済が期限切れまたは失敗しました。');
            $this->record($event, self::STATUS_PROCESSED);

            return;
        }

        if (($session->payment_status ?? null) !== 'paid') {
            if ($event->type === 'checkout.session.async_payment_succeeded') {
                $this->flagUnapplied($event, self::STATUS_UNMATCHED, "Session {$session->id} reported success without a paid status.");

                return;
            }

            $payment->provider_status = is_string($session->status ?? null) ? $session->status : null;
            $payment->save();
            $this->record($event, self::STATUS_PROCESSED);

            return;
        }

        $this->settlementService->applyPaidSession($order, $payment, $session);
        $this->record($event, self::STATUS_PROCESSED);
    }

    /**
     * A session whose id was never saved, because its response was lost or it belongs to an earlier
     * attempt. Unless it was paid nothing needs to happen, since nobody was given its URL.
     */
    private function handleUnsavedSession(Event $event, Order $order, Payment $payment, StripeObject $session): void
    {
        if (
            ! in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            || ($session->payment_status ?? null) !== 'paid'
        ) {
            $this->record($event, self::STATUS_IGNORED, "Session {$session->id} is not the order's saved session and was not paid.");

            return;
        }

        if ($this->orderAlreadyHasAPayment($order)) {
            $this->recordDuplicatePayment($event, $order, $session);

            return;
        }

        $previousSessionId = $payment->provider_checkout_id;
        $payment->provider_checkout_id = $session->id;
        $payment->checkout_state = Payment::CHECKOUT_CREATED;
        $payment->checkout_request_payload = null;
        $payment->save();

        $this->settlementService->applyPaidSession($order, $payment, $session);
        $this->record($event, self::STATUS_PROCESSED);

        if (is_string($previousSessionId)) {
            $this->expireSessionAfterCommit($previousSessionId);
        }
    }

    private function orderAlreadyHasAPayment(Order $order): bool
    {
        return in_array(
            $order->payment_status,
            ['paid', 'payment_review', 'refund_pending', 'refund_review', 'refunded'],
            true,
        );
    }

    /**
     * The order itself stays valid, so it is not held for review. The extra charge has no order to
     * pay for, and a person must refund it in Stripe.
     */
    private function recordDuplicatePayment(Event $event, Order $order, StripeObject $session): void
    {
        $paymentIntent = is_string($session->payment_intent ?? null) ? $session->payment_intent : 'unknown';

        OrderEvent::query()->create([
            'order_id' => $order->id,
            'event_type' => 'duplicate_payment_detected',
            'from_status' => $order->status,
            'to_status' => $order->status,
            'from_payment_status' => $order->payment_status,
            'to_payment_status' => $order->payment_status,
            'note' => "session={$session->id} payment_intent={$paymentIntent}",
        ]);

        $this->flagUnapplied(
            $event,
            self::STATUS_UNMATCHED,
            "Order {$order->order_number} was paid twice. Refund payment {$paymentIntent} in Stripe.",
        );
    }

    /**
     * Stripe must not be called while the order is locked, and a failure here must not undo the
     * payment that was just recorded.
     */
    private function expireSessionAfterCommit(string $sessionId): void
    {
        DB::afterCommit(function () use ($sessionId): void {
            try {
                $this->stripeBillingService->ecommerceClient()->checkout->sessions->expire($sessionId);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * The order is always locked before its payment, in every code path, so they cannot deadlock.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function lockOrderAndPayment(Payment $reference): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($reference->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($reference->id);

        return [$order, $payment];
    }

    private function handleRefundEvent(Event $event): void
    {
        $refundOrCharge = $event->data->object;
        if (! $refundOrCharge instanceof StripeObject) {
            $this->flagUnapplied($event, self::STATUS_UNMATCHED, 'The event did not include a refund or charge.');

            return;
        }

        $paymentIntent = $refundOrCharge->payment_intent ?? null;
        if (! is_string($paymentIntent)) {
            $this->record($event, self::STATUS_IGNORED, 'The refund has no payment intent.');

            return;
        }

        $reference = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_payment_id', $paymentIntent)
            ->first(['id', 'order_id']);
        if (! $reference instanceof Payment) {
            $this->handleRefundOfUnknownPayment($event, $refundOrCharge, $paymentIntent);

            return;
        }
        [$order, $payment] = $this->lockOrderAndPayment($reference);

        $refundedAmount = $event->type === 'charge.refunded'
            ? ($refundOrCharge->amount_refunded ?? null)
            : ($refundOrCharge->amount ?? null);
        $refundStatus = $event->type === 'charge.refunded'
            ? $this->chargeRefundStatus($refundOrCharge, $refundedAmount)
            : ($refundOrCharge->status ?? null);
        $refundCurrency = strtoupper((string) ($refundOrCharge->currency ?? ''));

        $this->record($event, self::STATUS_PROCESSED);

        if (
            $refundStatus === 'succeeded'
            && (
                $refundedAmount !== $payment->refundableAmount()
                || $refundCurrency !== ($payment->received_currency ?? $payment->currency)
            )
        ) {
            $this->settlementService->flagPartialRefund($order, $payment);

            return;
        }

        if (in_array($refundStatus, ['failed', 'canceled'], true)) {
            if ($order->payment_status === 'refund_pending') {
                $this->settlementService->revertFailedRefund($order, $payment);
            } else {
                $payment->provider_status = 'refund_'.$refundStatus;
                $payment->save();
            }

            return;
        }

        if ($refundStatus !== 'succeeded') {
            $payment->provider_status = 'refund_'.(is_string($refundStatus) ? $refundStatus : 'updated');
            $payment->save();

            return;
        }

        $this->settlementService->completeRefund($order, $payment);
    }

    /**
     * A charge carrying ecommerce metadata has a payment event still on its way, so a retry will
     * find it. Without metadata there is nothing to wait for, because a retry could never match.
     */
    private function handleRefundOfUnknownPayment(Event $event, StripeObject $refundOrCharge, string $paymentIntent): void
    {
        if (is_string($refundOrCharge->metadata->ec_order_id ?? null)) {
            throw new RuntimeException('Stripe refund arrived before its ecommerce payment was recorded.');
        }

        $this->flagUnapplied($event, self::STATUS_UNMATCHED, "No payment matches payment intent {$paymentIntent}.");
    }

    /**
     * A charge that is not fully refunded but has refunded money was partially refunded, which the
     * amount comparison in the caller flags for review.
     */
    private function chargeRefundStatus(StripeObject $charge, mixed $refundedAmount): string
    {
        return ($charge->refunded ?? false) || (is_int($refundedAmount) && $refundedAmount > 0)
            ? 'succeeded'
            : 'pending';
    }

    /**
     * Record an event a person should look at, and tell them. The ledger makes this happen once.
     */
    private function flagUnapplied(Event $event, string $status, string $reason): void
    {
        $this->record($event, $status, $reason);

        SendAdminWebhookAlert::dispatch($event->id, $event->type, $reason)->afterCommit();
    }

    private function record(Event $event, string $status, ?string $reason = null): void
    {
        DB::table('ec_payment_webhook_events')
            ->where('provider', 'stripe')
            ->where('event_id', $event->id)
            ->update([
                'status' => $status,
                'failure_reason' => $reason,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
