<?php

namespace App\Jobs;

use App\Mail\AdminWebhookAlertMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendAdminWebhookAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $reason,
    ) {}

    public function handle(): void
    {
        $recipient = config('services.contact.email');
        if (! is_string($recipient) || $recipient === '') {
            return;
        }

        Mail::to($recipient)->send(new AdminWebhookAlertMail($this->eventId, $this->eventType, $this->reason));
    }
}
