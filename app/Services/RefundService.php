<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefundService
{
    public function __construct(
        private StripeBillingService $stripeBillingService,
        private PaymentSettlementService $settlementService,
    ) {}

    public function refundFull(Order $order): string
    {
        $payment = DB::transaction(function () use ($order): Payment {
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

            return $payment;
        });

        try {
            $refund = $this->stripeBillingService->client()->refunds->create([
                'payment_intent' => $payment->provider_payment_id,
            ], [
                'idempotency_key' => 'ec-full-refund-'.$payment->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'refund' => 'Stripeで返金を開始できませんでした。決済状態を確認してから再度お試しください。',
            ]);
        }

        if ($refund->amount !== $payment->refundableAmount()) {
            throw ValidationException::withMessages([
                'refund' => 'Stripeから全額返金ではない応答が返ったため、注文状態を変更しませんでした。',
            ]);
        }

        return DB::transaction(function () use ($order, $payment, $refund): string {
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

            $lockedPayment->provider_status = 'refund_'.$status;
            if (in_array($status, ['pending', 'requires_action'], true)) {
                $lockedPayment->status = 'refund_pending';
                $lockedOrder->payment_status = 'refund_pending';
            } else {
                $lockedPayment->provider_status = 'refund_failed';
            }

            $lockedPayment->save();
            $lockedOrder->save();

            return $status;
        });
    }
}
