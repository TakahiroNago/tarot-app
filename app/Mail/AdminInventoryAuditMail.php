<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInventoryAuditMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{variant_id: int, on_hand: int, reserved: int, ledger_on_hand: int, ledger_reserved: int}>  $differences
     */
    public function __construct(public array $differences) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【要確認】在庫残高と台帳に差異があります',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orders.admin-inventory-audit',
        );
    }
}
