<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['variant_id', 'order_id', 'actor_user_id', 'movement_type', 'on_hand_delta', 'reserved_delta', 'idempotency_key', 'note'])]
class InventoryMovement extends Model
{
    protected $table = 'ec_inventory_movements';

    protected function casts(): array
    {
        return [
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
