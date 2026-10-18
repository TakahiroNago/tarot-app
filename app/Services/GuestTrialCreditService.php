<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class GuestTrialCreditService
{
    private const CREDIT_LIMIT = 1;

    public function remaining(string $deviceToken): int
    {
        $usedCredits = DB::table('guest_trial_credits')
            ->where('device_hash', $this->deviceHash($deviceToken))
            ->value('used_credits');

        return max(0, self::CREDIT_LIMIT - (int) $usedCredits);
    }

    public function consume(string $deviceToken): bool
    {
        $deviceHash = $this->deviceHash($deviceToken);

        return DB::transaction(function () use ($deviceHash): bool {
            $now = now();
            DB::table('guest_trial_credits')->insertOrIgnore([
                'device_hash' => $deviceHash,
                'used_credits' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('guest_trial_credits')
                ->where('device_hash', $deviceHash)
                ->where('used_credits', '<', self::CREDIT_LIMIT)
                ->increment('used_credits', 1, ['updated_at' => $now]) === 1;
        });
    }

    public function refund(string $deviceToken): void
    {
        DB::table('guest_trial_credits')
            ->where('device_hash', $this->deviceHash($deviceToken))
            ->where('used_credits', '>', 0)
            ->decrement('used_credits', 1, ['updated_at' => now()]);
    }

    private function deviceHash(string $deviceToken): string
    {
        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new RuntimeException('APP_KEY is required to protect guest trial IP addresses.');
        }

        return hash_hmac('sha256', $deviceToken, $applicationKey);
    }
}
