<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

class PublicReadingShareService
{
    private const EXPIRATION_DAYS = 30;

    /**
     * @param  array{question: string, card: string, interpretation: string, recommended_product_id?: int|null}  $reading
     */
    public function create(array $reading): string
    {
        $token = bin2hex(random_bytes(32));

        Cache::put($this->cacheKey($token), [
            'question' => $reading['question'],
            'card' => $reading['card'],
            'interpretation' => $reading['interpretation'],
            'recommended_product_id' => $reading['recommended_product_id'] ?? null,
        ], now()->addDays(self::EXPIRATION_DAYS));

        return $token;
    }

    /**
     * @return array{question: string, card: string, interpretation: string, recommended_product_id: int|null}|null
     */
    public function find(string $token): ?array
    {
        $reading = Cache::get($this->cacheKey($token));
        if ($reading === null) {
            return null;
        }

        if (
            ! is_array($reading)
            || ! is_string($reading['question'] ?? null)
            || ! is_string($reading['card'] ?? null)
            || ! is_string($reading['interpretation'] ?? null)
            || (
                array_key_exists('recommended_product_id', $reading)
                && $reading['recommended_product_id'] !== null
                && ! is_int($reading['recommended_product_id'])
            )
        ) {
            throw new UnexpectedValueException('Cached public tarot reading has an invalid format.');
        }

        return [
            'question' => $reading['question'],
            'card' => $reading['card'],
            'interpretation' => $reading['interpretation'],
            'recommended_product_id' => $reading['recommended_product_id'] ?? null,
        ];
    }

    private function cacheKey(string $token): string
    {
        return 'public_reading_share:'.hash('sha256', $token);
    }
}
