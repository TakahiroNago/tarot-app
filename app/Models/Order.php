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
    'review_reason', 'review_opened_at', 'review_resolved_at', 'review_resolved_by', 'review_note',
])]
class Order extends Model
{
    public const REVIEW_AMOUNT_MISMATCH = 'amount_mismatch';

    public const REVIEW_CURRENCY_MISMATCH = 'currency_mismatch';

    public const REVIEW_LATE_PAYMENT = 'late_payment';

    public const REVIEW_INVENTORY_INCONSISTENT = 'inventory_inconsistent';

    public const REVIEW_VARIANT_MISSING = 'variant_missing';

    /**
     * Reasons an admin may approve. The rest can only be refunded.
     */
    public const APPROVABLE_REVIEW_REASONS = [
        self::REVIEW_LATE_PAYMENT,
        self::REVIEW_INVENTORY_INCONSISTENT,
    ];

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
            'review_opened_at' => 'datetime',
            'review_resolved_at' => 'datetime',
        ];
    }

    public function isUnderReview(): bool
    {
        return $this->review_reason !== null && $this->review_resolved_at === null;
    }

    public function canApproveReview(): bool
    {
        return $this->isUnderReview()
            && $this->status === 'payment_review'
            && $this->payment_status === 'payment_review'
            && in_array($this->review_reason, self::APPROVABLE_REVIEW_REASONS, true);
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

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function reviewResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_resolved_by');
    }
}
