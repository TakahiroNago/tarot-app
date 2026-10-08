<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

class PublicReadingShareService
{
    private const EXPIRATION_DAYS = 30;

    /**
     * @param  array{question: string, card: string, interpretation: string}  $reading
     */
    public function create(array $reading): string
    {
        $token = bin2hex(random_bytes(32));

        Cache::put($this->cacheKey($token), [
            'question' => $reading['question'],
            'card' => $reading['card'],
            'interpretation' => $reading['interpretation'],
        ], now()->addDays(self::EXPIRATION_DAYS));

        return $token;
    }

    /**
     * @return array{question: string, card: string, interpretation: string}|null
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
        ) {
            throw new UnexpectedValueException('Cached public tarot reading has an invalid format.');
        }

        return [
            'question' => $reading['question'],
            'card' => $reading['card'],
            'interpretation' => $reading['interpretation'],
        ];
    }

    private function cacheKey(string $token): string
    {
        return 'public_reading_share:'.hash('sha256', $token);
    }
}
