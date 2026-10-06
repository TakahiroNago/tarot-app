<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactInquiry extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $inquiryName,
        public string $replyEmail,
        public string $inquirySubject,
        public string $inquiryMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->replyEmail, $this->inquiryName)],
            subject: 'お問い合わせ：'.$this->inquirySubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contact-inquiry',
            with: [
                'name' => $this->inquiryName,
                'email' => $this->replyEmail,
                'subject' => $this->inquirySubject,
                'inquiryMessage' => $this->inquiryMessage,
            ],
        );
    }
}
