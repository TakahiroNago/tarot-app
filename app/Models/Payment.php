<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'provider', 'provider_checkout_id', 'provider_payment_id',
    'provider_status', 'status', 'amount_minor', 'currency', 'received_amount_minor',
    'received_currency', 'idempotency_key', 'paid_at', 'checkout_state', 'checkout_attempt',
    'checkout_attempt_started_at', 'checkout_claimed_at', 'checkout_request_payload',
])]
class Payment extends Model
{
    public const CHECKOUT_NOT_STARTED = 'not_started';

    public const CHECKOUT_CREATING = 'creating';

    /**
     * Stripe may or may not have created a session; resending the saved request finds out.
     */
    public const CHECKOUT_UNCERTAIN = 'uncertain';

    /**
     * The request is known to have made no session, so a new key and expiry may be used.
     */
    public const CHECKOUT_NEEDS_NEW_ATTEMPT = 'needs_new_attempt';

    public const CHECKOUT_CREATED = 'created';

    public const CHECKOUT_FAILED = 'failed';

    public const CHECKOUT_EXHAUSTED = 'exhausted';

    protected $table = 'ec_payments';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'checkout_attempt' => 'integer',
            'checkout_attempt_started_at' => 'datetime',
            'checkout_claimed_at' => 'datetime',
            'checkout_request_payload' => 'encrypted:array',
        ];
    }

    /**
     * The amount Stripe actually took, which a full refund returns.
     */
    public function refundableAmount(): int
    {
        return $this->received_amount_minor ?? $this->amount_minor;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
