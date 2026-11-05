<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminWebhookAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【要確認】Stripe の通知を注文に反映できませんでした',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orders.admin-webhook-alert',
        );
    }
}
