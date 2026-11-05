<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'actor_user_id', 'event_type', 'from_status', 'to_status',
    'from_payment_status', 'to_payment_status', 'reason', 'note',
])]
class OrderEvent extends Model
{
    protected $table = 'ec_order_events';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
