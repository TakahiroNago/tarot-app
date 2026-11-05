<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderRefundCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $guestOrderUrl,
        public int $refundedAmount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ご注文の返金が完了しました（'.$this->order->order_number.'）',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orders.refund-completed',
        );
    }
}
