<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefundService
{
    public function __construct(
        private StripeBillingService $stripeBillingService,
        private PaymentSettlementService $settlementService,
    ) {}

    /**
     * The request is recorded before Stripe is called, so a refund that Stripe carries out
     * still names who asked for it if this process stops before saving the outcome.
     */
    public function refundFull(Order $order, User $actor, string $note): string
    {
        $payment = DB::transaction(function () use ($order, $actor, $note): Payment {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($lockedOrder->payment_status, ['paid', 'payment_review', 'refund_pending'], true)) {
                throw ValidationException::withMessages([
                    'refund' => '全額返金できる決済状態ではありません。',
                ]);
            }

            $payment = $lockedOrder->payments()
                ->where('provider', 'stripe')
                ->lockForUpdate()
                ->first();
            if (! $payment instanceof Payment || ! is_string($payment->provider_payment_id)) {
                throw ValidationException::withMessages([
                    'refund' => 'Stripeの決済参照がないため返金できません。',
                ]);
            }

            $this->recordEvent($lockedOrder, $actor, 'refund_requested', note: $note);

            return $payment;
        });

        try {
            $refund = $this->stripeBillingService->ecommerceClient()->refunds->create([
                'payment_intent' => $payment->provider_payment_id,
            ], [
                'idempotency_key' => 'ec-full-refund-'.$payment->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordEvent($order->fresh(), $actor, 'refund_request_failed', 'stripe_error');

            throw ValidationException::withMessages([
                'refund' => 'Stripeで返金を開始できませんでした。決済状態を確認してから再度お試しください。',
            ]);
        }

        if ($refund->amount !== $payment->refundableAmount()) {
            $this->recordEvent($order->fresh(), $actor, 'refund_request_failed', 'amount_mismatch');

            throw ValidationException::withMessages([
                'refund' => 'Stripeから全額返金ではない応答が返ったため、注文状態を変更しませんでした。',
            ]);
        }

        return DB::transaction(function () use ($order, $payment, $refund, $actor): string {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($lockedOrder->payment_status === 'refunded') {
                return 'succeeded';
            }

            $status = is_string($refund->status) ? $refund->status : 'unknown';

            if ($status === 'succeeded') {
                $this->settlementService->completeRefund($lockedOrder, $lockedPayment);

                return $status;
            }

            $fromPaymentStatus = $lockedOrder->payment_status;
            $lockedPayment->provider_status = 'refund_'.$status;
            if (in_array($status, ['pending', 'requires_action'], true)) {
                $lockedPayment->status = 'refund_pending';
                $lockedOrder->payment_status = 'refund_pending';
            } else {
                $lockedPayment->provider_status = 'refund_failed';
            }

            $lockedPayment->save();
            $lockedOrder->save();

            $this->recordEvent(
                $lockedOrder,
                $actor,
                $lockedOrder->payment_status === 'refund_pending' ? 'refund_pending' : 'refund_request_failed',
                $lockedOrder->payment_status === 'refund_pending' ? null : 'stripe_refund_'.$status,
                $fromPaymentStatus,
            );

            return $status;
        });
    }

    private function recordEvent(
        Order $order,
        User $actor,
        string $eventType,
        ?string $reason = null,
        ?string $fromPaymentStatus = null,
        ?string $note = null,
    ): void {
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'actor_user_id' => $actor->id,
            'event_type' => $eventType,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'from_payment_status' => $fromPaymentStatus ?? $order->payment_status,
            'to_payment_status' => $order->payment_status,
            'reason' => $reason,
            'note' => $note,
        ]);
    }
}
