<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Event;
use Stripe\StripeObject;

class EcommercePaymentWebhookService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private CheckoutService $checkoutService,
        private PaymentSettlementService $settlementService,
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

            if (! in_array($event->type, [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'checkout.session.expired',
                'refund.updated',
                'charge.refunded',
            ], true)) {
                $this->markProcessed($event->id);

                return;
            }

            if (in_array($event->type, ['refund.updated', 'charge.refunded'], true)) {
                $this->handleRefundEvent($event);
                $this->markProcessed($event->id);

                return;
            }

            $session = $event->data->object;
            if (! $session instanceof StripeObject || ! is_string($session->id ?? null)) {
                throw new RuntimeException('Stripe Checkout webhook did not include a valid session.');
            }

            $reference = Payment::query()
                ->where('provider', 'stripe')
                ->where('provider_checkout_id', $session->id)
                ->first(['id', 'order_id']);
            if (! $reference instanceof Payment) {
                throw new RuntimeException('Stripe Checkout session does not match an ecommerce payment.');
            }
            [$order, $payment] = $this->lockOrderAndPayment($reference);

            $metadataOrderId = $session->metadata->ec_order_id ?? null;
            $metadataPaymentId = $session->metadata->ec_payment_id ?? null;
            if (
                (string) $order->id !== (string) $metadataOrderId
                || (string) $payment->id !== (string) $metadataPaymentId
                || ($session->client_reference_id ?? null) !== $order->order_number
            ) {
                throw new RuntimeException('Stripe Checkout metadata does not match the recorded order.');
            }

            if (in_array($event->type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                $this->checkoutService->releaseReservation($order, 'Stripe決済が期限切れまたは失敗しました。');
                $this->markProcessed($event->id);

                return;
            }

            if (($session->payment_status ?? null) !== 'paid') {
                if ($event->type === 'checkout.session.async_payment_succeeded') {
                    throw new RuntimeException('Stripe reported async payment success without paid status.');
                }

                $payment->provider_status = is_string($session->status ?? null) ? $session->status : null;
                $payment->save();
                $this->markProcessed($event->id);

                return;
            }

            $this->settlementService->applyPaidSession($order, $payment, $session);
            $this->markProcessed($event->id);
        }, self::TRANSACTION_ATTEMPTS);
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

    private function markProcessed(string $eventId): void
    {
        DB::table('ec_payment_webhook_events')
            ->where('provider', 'stripe')
            ->where('event_id', $eventId)
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function handleRefundEvent(Event $event): void
    {
        $refundOrCharge = $event->data->object;
        if (! $refundOrCharge instanceof StripeObject) {
            throw new RuntimeException('Stripe refund webhook did not include a valid object.');
        }

        $paymentIntent = $refundOrCharge->payment_intent ?? null;
        if (! is_string($paymentIntent)) {
            return;
        }

        $reference = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_payment_id', $paymentIntent)
            ->first(['id', 'order_id']);
        if (! $reference instanceof Payment) {
            throw new RuntimeException('Stripe refund does not match an ecommerce payment.');
        }
        [$order, $payment] = $this->lockOrderAndPayment($reference);

        $refundedAmount = $event->type === 'charge.refunded'
            ? ($refundOrCharge->amount_refunded ?? null)
            : ($refundOrCharge->amount ?? null);
        $refundStatus = $event->type === 'charge.refunded'
            ? $this->chargeRefundStatus($refundOrCharge, $refundedAmount)
            : ($refundOrCharge->status ?? null);
        $refundCurrency = strtoupper((string) ($refundOrCharge->currency ?? ''));

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
     * A charge that is not fully refunded but has refunded money was partially refunded, which the
     * amount comparison in the caller flags for review.
     */
    private function chargeRefundStatus(StripeObject $charge, mixed $refundedAmount): string
    {
        return ($charge->refunded ?? false) || (is_int($refundedAmount) && $refundedAmount > 0)
            ? 'succeeded'
            : 'pending';
    }
}
