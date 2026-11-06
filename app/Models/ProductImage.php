<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable(['product_id', 'storage_disk', 'path', 'alt_text', 'sort_order'])]
class ProductImage extends Model
{
    protected $table = 'ec_product_images';

    /**
     * For pages where the image is an extra, so a storage problem must not take the page down.
     */
    public function publicUrlOrNull(): ?string
    {
        try {
            return Storage::disk($this->storage_disk)->url($this->path);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
