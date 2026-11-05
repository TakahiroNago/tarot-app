<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'event_id', 'event_type', 'status', 'failure_reason', 'processed_at'])]
class PaymentWebhookEvent extends Model
{
    protected $table = 'ec_payment_webhook_events';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
