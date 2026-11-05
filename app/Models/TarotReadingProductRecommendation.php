<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tarot_reading_id', 'product_id', 'selected_at'])]
class TarotReadingProductRecommendation extends Model
{
    protected $table = 'tarot_reading_product_recommendations';

    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
        ];
    }

    public function tarotReading(): BelongsTo
    {
        return $this->belongsTo(TarotReading::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
