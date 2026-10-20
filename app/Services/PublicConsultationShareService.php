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
    public function create(array $consultation, bool $hideConcern = false): string
    {
        $token = bin2hex(random_bytes(32));

        Cache::put($this->cacheKey($token), [
            'concern' => $hideConcern ? null : $consultation['concern'],
            'response' => $consultation['response'],
            'concern_hidden' => $hideConcern,
        ], now()->addDays(self::EXPIRATION_DAYS));

        return $token;
    }

    /**
     * @return array{concern: ?string, response: string, concern_hidden: bool}|null
     */
    public function find(string $token): ?array
    {
        $cachedConsultation = Cache::get($this->cacheKey($token));
        if ($cachedConsultation === null) {
            return null;
        }

        if (
            ! is_array($cachedConsultation)
            || ! (is_string($cachedConsultation['concern'] ?? null) || ($cachedConsultation['concern_hidden'] ?? false) === true)
            || ! is_string($cachedConsultation['response'] ?? null)
        ) {
            throw new UnexpectedValueException('Cached public consultation has an invalid format.');
        }

        return [
            'concern' => is_string($cachedConsultation['concern'] ?? null) ? $cachedConsultation['concern'] : null,
            'response' => $cachedConsultation['response'],
            'concern_hidden' => ($cachedConsultation['concern_hidden'] ?? false) === true,
        ];
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function revoke(?string $tokenHash): void
    {
        if ($tokenHash !== null) {
            Cache::forget('public_consultation_share:'.$tokenHash);
        }
    }

    private function cacheKey(string $token): string
    {
        return 'public_consultation_share:'.$this->tokenHash($token);
    }
}
