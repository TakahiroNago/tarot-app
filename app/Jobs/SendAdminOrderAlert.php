<?php

namespace App\Jobs;

use App\Mail\AdminOrderAlertMail;
use App\Models\Order;
use Illuminate\Mail\Mailable;

class SendAdminOrderAlert extends SendOrderEmail
{
    public function __construct(int $orderId, public string $alertType)
    {
        parent::__construct($orderId);
    }

    protected function emailType(): string
    {
        return 'admin_alert_'.$this->alertType;
    }

    protected function shouldSend(Order $order): bool
    {
        return is_string(config('services.contact.email')) && config('services.contact.email') !== '';
    }

    protected function recipient(Order $order): string
    {
        return config('services.contact.email');
    }

    protected function mailable(Order $order, ?string $guestOrderUrl): Mailable
    {
        return new AdminOrderAlertMail($order, $this->alertType);
    }
}
