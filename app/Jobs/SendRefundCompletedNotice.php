<?php

namespace App\Jobs;

use App\Mail\OrderRefundCompletedMail;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Mail\Mailable;

class SendRefundCompletedNotice extends SendOrderEmail
{
    protected function emailType(): string
    {
        return 'refund_completed';
    }

    protected function shouldSend(Order $order): bool
    {
        return $order->payment_status === 'refunded';
    }

    protected function mailable(Order $order, ?string $guestOrderUrl): Mailable
    {
        $payment = $order->payments()->where('provider', 'stripe')->first();

        return new OrderRefundCompletedMail(
            $order,
            $guestOrderUrl,
            $payment instanceof Payment ? $payment->refundableAmount() : $order->total_minor,
        );
    }
}
