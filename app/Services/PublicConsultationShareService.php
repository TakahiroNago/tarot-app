<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

class PublicConsultationShareService
{
    private const EXPIRATION_DAYS = 30;

    /**
     * @param  array{concern: string, response: string}  $consultation
     */
    public function create(array $consultation): string
    {
        $token = bin2hex(random_bytes(32));

        Cache::put($this->cacheKey($token), [
            'concern' => $consultation['concern'],
            'response' => $consultation['response'],
        ], now()->addDays(self::EXPIRATION_DAYS));

        return $token;
    }

    /**
     * @return array{concern: string, response: string}|null
     */
    public function find(string $token): ?array
    {
        $cachedConsultation = Cache::get($this->cacheKey($token));
        if ($cachedConsultation === null) {
            return null;
        }

        if (
            ! is_array($cachedConsultation)
            || ! is_string($cachedConsultation['concern'] ?? null)
            || ! is_string($cachedConsultation['response'] ?? null)
        ) {
            throw new UnexpectedValueException('Cached public consultation has an invalid format.');
        }

        return [
            'concern' => $cachedConsultation['concern'],
            'response' => $cachedConsultation['response'],
        ];
    }

    private function cacheKey(string $token): string
    {
        return 'public_consultation_share:'.hash('sha256', $token);
    }
}
