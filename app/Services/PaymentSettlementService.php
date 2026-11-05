<?php

namespace App\Services;

use App\Jobs\SendAdminOrderAlert;
use App\Jobs\SendOrderConfirmationEmail;
use App\Jobs\SendPaymentReviewNotice;
use App\Jobs\SendRefundCompletedNotice;
use App\Mail\AdminOrderAlertMail;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\StripeObject;
use Throwable;

/**
 * Moves an order through payment, review, and refund.
 *
 * Except for approveReview, callers must hold the order lock and then the payment lock.
 */
class PaymentSettlementService
{
    private const APPROVAL_TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private InventoryService $inventoryService,
        private CartService $cartService,
    ) {}

    public function applyPaidSession(Order $order, Payment $payment, StripeObject $session): void
    {
        if (in_array($order->payment_status, ['paid', 'payment_review'], true)) {
            return;
        }

        if (in_array($order->payment_status, ['refund_pending', 'refund_review', 'refunded'], true)) {
            report(new RuntimeException("A paid session arrived for order {$order->id} after its refund began."));

            return;
        }

        $reviewReason = $this->reviewReasonFor($order, $payment, $session);
        if ($reviewReason !== null) {
            $this->openReview($order, $payment, $session, $reviewReason);

            return;
        }

        $this->settleReservedOrder($order, $payment, $session);
    }

    /**
     * Confirm a review after an admin's check. Amount mismatches can only be refunded.
     */
    public function approveReview(Order $order, User $actor, string $note): void
    {
        DB::transaction(function () use ($order, $actor, $note): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $lockedOrder->canApproveReview()) {
                throw ValidationException::withMessages([
                    'review' => 'この注文は承認できません。返金してください。',
                ]);
            }

            $payment = $lockedOrder->payments()->where('provider', 'stripe')->lockForUpdate()->firstOrFail();
            $this->sellHeldOrAvailableStock($lockedOrder);

            $lockedOrder->review_resolved_at = now();
            $lockedOrder->review_resolved_by = $actor->id;
            $lockedOrder->review_note = $note;
            $lockedOrder->reservation_expires_at = null;
            $this->transition($lockedOrder, 'paid', 'paid', 'review_approved', $actor, $lockedOrder->review_reason, $note);

            $payment->provider_status = 'review_approved';
            $payment->save();

            SendOrderConfirmationEmail::dispatch($lockedOrder->id)->afterCommit();
        }, self::APPROVAL_TRANSACTION_ATTEMPTS);
    }

    public function completeRefund(Order $order, Payment $payment): void
    {
        if ($order->payment_status === 'refunded') {
            return;
        }

        $this->inventoryService->releaseHeldReservation($order, '返金完了により在庫予約を解除しました。');

        $payment->status = 'refunded';
        $payment->provider_status = 'refunded';
        $payment->save();

        if ($order->isUnderReview()) {
            $order->review_resolved_at = now();
        }
        $order->reservation_expires_at = null;
        $this->transition($order, 'refunded', 'refunded', 'refund_completed', null, $order->review_reason);

        SendRefundCompletedNotice::dispatch($order->id)->afterCommit();
    }

    public function revertFailedRefund(Order $order, Payment $payment): void
    {
        if ($order->payment_status !== 'refund_pending') {
            return;
        }

        $payment->status = 'succeeded';
        $payment->provider_status = 'refund_failed';
        $payment->save();

        $this->transition(
            $order,
            $order->status,
            $order->isUnderReview() ? 'payment_review' : 'paid',
            'refund_failed',
            null,
            $order->review_reason,
        );
    }

    /**
     * A partial refund came from outside this application, so a person must decide what happens next.
     */
    public function flagPartialRefund(Order $order, Payment $payment): void
    {
        if (in_array($order->payment_status, ['refunded', 'refund_review'], true)) {
            return;
        }

        $payment->status = 'refund_review';
        $payment->provider_status = 'partial_refund_detected';
        $payment->save();

        $this->transition($order, $order->status, 'refund_review', 'partial_refund_detected');

        SendAdminOrderAlert::dispatch($order->id, AdminOrderAlertMail::ALERT_REFUND_REVIEW)->afterCommit();
    }

    private function reviewReasonFor(Order $order, Payment $payment, StripeObject $session): ?string
    {
        if (($session->amount_total ?? null) !== $payment->amount_minor) {
            return Order::REVIEW_AMOUNT_MISMATCH;
        }
        if (
            strtoupper((string) ($session->currency ?? '')) !== $payment->currency
            || $payment->currency !== $order->currency
        ) {
            return Order::REVIEW_CURRENCY_MISMATCH;
        }
        if ($order->status === 'cancelled') {
            return Order::REVIEW_LATE_PAYMENT;
        }
        if ($order->status !== 'pending_payment' || $order->reservation_expires_at === null) {
            return Order::REVIEW_INVENTORY_INCONSISTENT;
        }

        return null;
    }

    private function settleReservedOrder(Order $order, Payment $payment, StripeObject $session): void
    {
        $lines = [];
        foreach ($order->items()->orderBy('variant_id')->get() as $item) {
            if ($item->variant_id === null) {
                $this->openReview($order, $payment, $session, Order::REVIEW_VARIANT_MISSING);

                return;
            }

            $balance = InventoryBalance::query()->lockForUpdate()->find($item->variant_id);
            if ($balance === null || $balance->reserved < $item->quantity || $balance->on_hand < $item->quantity) {
                $this->openReview($order, $payment, $session, Order::REVIEW_INVENTORY_INCONSISTENT);

                return;
            }
            $lines[] = [$item, $balance];
        }

        foreach ($lines as [$item, $balance]) {
            $balance->on_hand -= $item->quantity;
            $balance->reserved -= $item->quantity;
            $balance->save();

            InventoryMovement::query()->create([
                'variant_id' => $item->variant_id,
                'order_id' => $order->id,
                'movement_type' => 'sale',
                'on_hand_delta' => -$item->quantity,
                'reserved_delta' => -$item->quantity,
                'idempotency_key' => InventoryService::movementKey('sale', $order->id, $item->variant_id),
                'note' => '注文 '.$order->order_number.' の決済確定',
            ]);
        }

        $this->releaseCartAfterCommit($order);
        $order->status = 'paid';
        $order->payment_status = 'paid';
        $order->paid_at = now();
        $order->reservation_expires_at = null;
        $order->save();
        $this->recordPayment($payment, $session);

        SendOrderConfirmationEmail::dispatch($order->id)->afterCommit();
    }

    /**
     * Once money has moved the buyer should not see these items as still to buy. The cart is
     * changed after the commit because a cart lock must never be taken while holding an order lock.
     */
    private function releaseCartAfterCommit(Order $order): void
    {
        $cartId = $order->active_cart_id;
        if ($cartId === null) {
            return;
        }

        $order->active_cart_id = null;
        DB::afterCommit(function () use ($cartId, $order): void {
            try {
                $this->cartService->removeOrderedItems($cartId, $order);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function openReview(Order $order, Payment $payment, StripeObject $session, string $reason): void
    {
        $this->releaseCartAfterCommit($order);
        $order->paid_at = now();
        $order->reservation_expires_at = null;
        $order->review_reason = $reason;
        $order->review_opened_at = now();
        $this->transition($order, 'payment_review', 'payment_review', 'review_opened', null, $reason);
        $this->recordPayment($payment, $session);

        SendPaymentReviewNotice::dispatch($order->id)->afterCommit();
        SendAdminOrderAlert::dispatch($order->id, AdminOrderAlertMail::ALERT_PAYMENT_REVIEW)->afterCommit();
    }

    private function recordPayment(Payment $payment, StripeObject $session): void
    {
        $payment->status = 'succeeded';
        $payment->provider_status = is_string($session->status ?? null) ? $session->status : 'complete';
        $payment->provider_payment_id = $this->paymentIntentId($session);
        $payment->received_amount_minor = is_int($session->amount_total ?? null) ? $session->amount_total : null;
        $payment->received_currency = is_string($session->currency ?? null) ? strtoupper($session->currency) : null;
        $payment->paid_at = now();
        $payment->save();
    }

    /**
     * The whole order must be fulfillable, so any shortage rolls back the approval.
     */
    private function sellHeldOrAvailableStock(Order $order): void
    {
        foreach ($order->items()->orderBy('variant_id')->get() as $item) {
            if ($item->variant_id === null) {
                throw ValidationException::withMessages([
                    'review' => '商品情報がないため承認できません。返金してください。',
                ]);
            }

            $balance = InventoryBalance::query()->lockForUpdate()->find($item->variant_id);
            $held = min($this->inventoryService->heldReservation($order->id, $item->variant_id), $item->quantity);
            if (
                $balance === null
                || $balance->reserved < $held
                || $balance->available() < $item->quantity - $held
            ) {
                throw ValidationException::withMessages([
                    'review' => '在庫が不足しているため承認できません。返金してください。',
                ]);
            }

            $balance->on_hand -= $item->quantity;
            $balance->reserved -= $held;
            $balance->save();

            InventoryMovement::query()->create([
                'variant_id' => $item->variant_id,
                'order_id' => $order->id,
                'movement_type' => 'sale',
                'on_hand_delta' => -$item->quantity,
                'reserved_delta' => -$held,
                'idempotency_key' => InventoryService::movementKey('sale', $order->id, $item->variant_id),
                'note' => '注文 '.$order->order_number.' のレビュー承認による販売確定',
            ]);
        }
    }

    private function transition(
        Order $order,
        string $status,
        string $paymentStatus,
        string $eventType,
        ?User $actor = null,
        ?string $reason = null,
        ?string $note = null,
    ): void {
        $fromStatus = $order->getOriginal('status');
        $fromPaymentStatus = $order->getOriginal('payment_status');

        $order->status = $status;
        $order->payment_status = $paymentStatus;
        $order->save();

        OrderEvent::query()->create([
            'order_id' => $order->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $status,
            'from_payment_status' => $fromPaymentStatus,
            'to_payment_status' => $paymentStatus,
            'reason' => $reason,
            'note' => $note,
        ]);
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
}
