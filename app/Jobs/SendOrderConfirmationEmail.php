<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Mail\Mailable;

class SendOrderConfirmationEmail extends SendOrderEmail
{
    protected function emailType(): string
    {
        return 'order_confirmation';
    }

    /**
     * Checked when sending as well as when dispatching, because the queue can run later.
     */
    protected function shouldSend(Order $order): bool
    {
        return $order->payment_status === 'paid';
    }

    protected function mailable(Order $order, ?string $guestOrderUrl): Mailable
    {
        return new OrderConfirmationMail($order, $guestOrderUrl);
    }
}
