<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['product_id', 'sku', 'label', 'options', 'price_minor', 'currency', 'is_active'])]
class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;

    protected $table = 'ec_product_variants';

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryBalance(): HasOne
    {
        return $this->hasOne(InventoryBalance::class, 'variant_id');
    }
}
