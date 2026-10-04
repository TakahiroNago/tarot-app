<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'question', 'card', 'interpretation', 'category', 'summary'])]
class TarotReading extends Model
{
    public const CATEGORIES = ['仕事', '恋愛', '人間関係', 'お金', '健康', '将来', 'その他'];

    public const SUMMARY_MAX_LENGTH = 150;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
