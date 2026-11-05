<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'provider', 'provider_checkout_id', 'provider_payment_id',
    'provider_status', 'status', 'amount_minor', 'currency', 'idempotency_key', 'paid_at',
])]
class Payment extends Model
{
    protected $table = 'ec_payments';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
