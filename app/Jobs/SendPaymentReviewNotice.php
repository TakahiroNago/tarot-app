<?php

namespace App\Jobs;

use App\Mail\OrderPaymentReviewMail;
use App\Models\Order;
use Illuminate\Mail\Mailable;

class SendPaymentReviewNotice extends SendOrderEmail
{
    protected function emailType(): string
    {
        return 'payment_review_notice';
    }

    protected function shouldSend(Order $order): bool
    {
        return $order->isUnderReview();
    }

    protected function mailable(Order $order, ?string $guestOrderUrl): Mailable
    {
        return new OrderPaymentReviewMail($order, $guestOrderUrl);
    }
}
