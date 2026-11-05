<?php

namespace App\Services;

use App\Jobs\SendOrderConfirmationEmail;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Event;
use Stripe\StripeObject;

class EcommercePaymentWebhookService
{
    public function __construct(private CheckoutService $checkoutService) {}

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

            $payment = Payment::query()
                ->where('provider', 'stripe')
                ->where('provider_checkout_id', $session->id)
                ->lockForUpdate()
                ->first();
            if (! $payment instanceof Payment) {
                throw new RuntimeException('Stripe Checkout session does not match an ecommerce payment.');
            }

            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
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

            $paymentStatus = $session->payment_status ?? null;
            $payment->provider_status = is_string($session->status ?? null) ? $session->status : null;
            if ($paymentStatus !== 'paid') {
                if ($event->type === 'checkout.session.async_payment_succeeded') {
                    throw new RuntimeException('Stripe reported async payment success without paid status.');
                }

                $payment->save();
                $this->markProcessed($event->id);

                return;
            }

            if (
                ($session->amount_total ?? null) !== $payment->amount_minor
                || strtoupper((string) ($session->currency ?? '')) !== $payment->currency
                || $payment->currency !== $order->currency
            ) {
                $this->markForPaymentReview($order, $payment, $session);
                $this->markProcessed($event->id);

                return;
            }

            $this->completePaidOrder($order, $payment, $session);
            $this->markProcessed($event->id);
        });
    }

    private function completePaidOrder(Order $order, Payment $payment, StripeObject $session): void
    {
        if ($order->payment_status === 'paid') {
            return;
        }

        if ($order->status !== 'pending_payment' || $order->reservation_expires_at === null) {
            $this->markForPaymentReview($order, $payment, $session);

            return;
        }

        $items = $order->items()->orderBy('variant_id')->get();
        $balances = [];
        foreach ($items as $item) {
            if ($item->variant_id === null) {
                $this->markForPaymentReview($order, $payment, $session);

                return;
            }

            $balance = InventoryBalance::query()
                ->lockForUpdate()
                ->find($item->variant_id);
            if (
                $balance === null
                || $balance->reserved < $item->quantity
                || $balance->on_hand < $item->quantity
            ) {
                $this->markForPaymentReview($order, $payment, $session);

                return;
            }
            $balances[] = [$item, $balance];
        }

        foreach ($balances as [$item, $balance]) {
            $balance->on_hand -= $item->quantity;
            $balance->reserved -= $item->quantity;
            $balance->save();

            InventoryMovement::query()->create([
                'variant_id' => $item->variant_id,
                'order_id' => $order->id,
                'movement_type' => 'sale',
                'on_hand_delta' => -$item->quantity,
                'reserved_delta' => -$item->quantity,
                'idempotency_key' => bin2hex(random_bytes(32)),
                'note' => '注文 '.$order->order_number.' の決済確定',
            ]);
        }

        $order->status = 'paid';
        $order->payment_status = 'paid';
        $order->paid_at = now();
        $order->reservation_expires_at = null;
        $order->save();
        SendOrderConfirmationEmail::dispatch($order->id)->afterCommit();

        $payment->status = 'succeeded';
        $payment->provider_status = is_string($session->status ?? null) ? $session->status : 'complete';
        $payment->provider_payment_id = $this->paymentIntentId($session);
        $payment->paid_at = now();
        $payment->save();
    }

    private function markForPaymentReview(Order $order, Payment $payment, StripeObject $session): void
    {
        $order->status = 'payment_review';
        $order->payment_status = 'paid';
        $order->paid_at = now();
        $order->reservation_expires_at = null;
        $order->save();
        SendOrderConfirmationEmail::dispatch($order->id)->afterCommit();

        $payment->status = 'succeeded';
        $payment->provider_status = is_string($session->status ?? null) ? $session->status : 'complete';
        $payment->provider_payment_id = $this->paymentIntentId($session);
        $payment->paid_at = now();
        $payment->save();
    }

    private function paymentIntentId(StripeObject $session): ?string
    {
        $paymentIntent = $session->payment_intent ?? null;

        if (is_string($paymentIntent)) {
            return $paymentIntent;
        }

        return is_object($paymentIntent) && is_string($paymentIntent->id ?? null)
            ? $paymentIntent->id
            : null;
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

        $payment = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_payment_id', $paymentIntent)
            ->lockForUpdate()
            ->first();
        if (! $payment instanceof Payment) {
            throw new RuntimeException('Stripe refund does not match an ecommerce payment.');
        }

        $refundStatus = $event->type === 'charge.refunded'
            ? (($refundOrCharge->refunded ?? false) ? 'succeeded' : 'pending')
            : ($refundOrCharge->status ?? null);
        $refundedAmount = $event->type === 'charge.refunded'
            ? ($refundOrCharge->amount_refunded ?? null)
            : ($refundOrCharge->amount ?? null);
        $refundCurrency = strtoupper((string) ($refundOrCharge->currency ?? ''));
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);

        if (
            $refundStatus === 'succeeded'
            && ($refundedAmount !== $payment->amount_minor || $refundCurrency !== $payment->currency)
        ) {
            $payment->status = 'refund_review';
            $payment->provider_status = 'partial_refund_detected';
            $order->status = 'payment_review';
            $order->payment_status = 'payment_review';
            $payment->save();
            $order->save();

            return;
        }

        if (in_array($refundStatus, ['failed', 'canceled'], true)) {
            if ($order->payment_status === 'refund_pending') {
                $payment->status = 'succeeded';
                $payment->provider_status = 'refund_failed';
                $order->payment_status = 'paid';
                $order->save();
            } else {
                $payment->provider_status = 'refund_'.$refundStatus;
            }
            $payment->save();

            return;
        }

        if ($refundStatus !== 'succeeded') {
            $payment->provider_status = 'refund_'.(is_string($refundStatus) ? $refundStatus : 'updated');
            $payment->save();

            return;
        }

        $payment->status = 'refunded';
        $payment->provider_status = 'refunded';
        $payment->save();
        $order->status = 'refunded';
        $order->payment_status = 'refunded';
        $order->save();
    }
}
