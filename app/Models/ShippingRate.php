<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shipping_method_id', 'country_code', 'fee_minor', 'currency', 'is_active'])]
class ShippingRate extends Model
{
    protected $table = 'ec_shipping_rates';

    protected function casts(): array
    {
        return [
            'fee_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
