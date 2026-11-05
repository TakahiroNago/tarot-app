<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_number', 'user_id', 'idempotency_key', 'status', 'payment_status',
    'customer_name', 'customer_email', 'customer_phone', 'shipping_recipient',
    'shipping_country_code', 'shipping_postal_code', 'shipping_region',
    'shipping_city', 'shipping_address_line1', 'shipping_address_line2',
    'shipping_method_name', 'subtotal_minor', 'tax_minor', 'shipping_minor',
    'total_minor', 'currency', 'tax_rate_basis_points', 'reservation_expires_at',
    'guest_access_token_hash', 'guest_access_expires_at', 'paid_at',
])]
class Order extends Model
{
    protected $table = 'ec_orders';

    protected function casts(): array
    {
        return [
            'customer_name' => 'encrypted',
            'customer_email' => 'encrypted',
            'customer_phone' => 'encrypted',
            'shipping_recipient' => 'encrypted',
            'shipping_postal_code' => 'encrypted',
            'shipping_region' => 'encrypted',
            'shipping_city' => 'encrypted',
            'shipping_address_line1' => 'encrypted',
            'shipping_address_line2' => 'encrypted',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'reservation_expires_at' => 'datetime',
            'guest_access_expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(OrderEmail::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
