<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminOrderAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ALERT_PAYMENT_REVIEW = 'payment_review';

    public const ALERT_REFUND_REVIEW = 'refund_review';

    public const ALERT_REVIEW_OVERDUE = 'review_overdue';

    public const ALERT_RECONCILE_STUCK = 'reconcile_stuck';

    public const ALERT_UNPAID_COMPLETE = 'unpaid_complete';

    public function __construct(
        public Order $order,
        public string $alertType,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【要確認】注文 '.$this->order->order_number.' の確認が必要です',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orders.admin-alert',
        );
    }
}
