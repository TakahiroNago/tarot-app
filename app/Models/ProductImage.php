<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'storage_disk', 'path', 'alt_text', 'sort_order'])]
class ProductImage extends Model
{
    protected $table = 'ec_product_images';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
