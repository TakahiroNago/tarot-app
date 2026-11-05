<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaymentReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $guestOrderUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ご注文の確認を行っています（'.$this->order->order_number.'）',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orders.payment-review',
        );
    }
}
