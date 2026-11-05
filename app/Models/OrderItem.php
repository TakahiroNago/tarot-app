<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'product_id', 'variant_id', 'product_name_snapshot',
    'variant_label_snapshot', 'sku_snapshot', 'unit_price_minor', 'quantity',
    'tax_minor', 'line_total_minor', 'tax_rate_basis_points', 'currency',
])]
class OrderItem extends Model
{
    protected $table = 'ec_order_items';

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'tax_minor' => 'integer',
            'line_total_minor' => 'integer',
            'tax_rate_basis_points' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
