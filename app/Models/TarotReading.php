<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'question', 'card', 'interpretation', 'category', 'summary', 'daily_reading_date'])]
class TarotReading extends Model
{
    public const CATEGORIES = ['仕事', '恋愛', 'お金', '家族', '人間関係', '健康', '将来', '自分自身', 'その他'];

    public const SUMMARY_MAX_LENGTH = 150;

    protected function casts(): array
    {
        return [
            'daily_reading_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
