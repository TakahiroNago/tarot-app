<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReadingCreditService
{
    private const DAILY_LIMIT = 3;

    /** @return array{daily: int, purchased: int, total: int} */
    public function balance(User $user): array
    {
        $this->refreshDailyCredits($user);
        $user->refresh();

        return [
            'daily' => $user->daily_credits,
            'purchased' => $user->purchased_credits,
            'total' => $user->daily_credits + $user->purchased_credits,
        ];
    }

    public function consume(User $user): ?string
    {
        return DB::transaction(function () use ($user): ?string {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->refreshLockedUser($lockedUser);

            if ($lockedUser->daily_credits > 0) {
                $lockedUser->decrement('daily_credits');

                return 'daily';
            }

            if ($lockedUser->purchased_credits > 0) {
                $lockedUser->decrement('purchased_credits');

                return 'purchased';
            }

            return null;
        });
    }

    public function refund(User $user, string $bucket): void
    {
        if (! in_array($bucket, ['daily', 'purchased'], true)) {
            throw new \InvalidArgumentException('Unknown reading credit bucket.');
        }

        DB::transaction(function () use ($user, $bucket): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($bucket === 'daily') {
                $lockedUser->daily_credits = min(self::DAILY_LIMIT, $lockedUser->daily_credits + 1);
                $lockedUser->save();

                return;
            }

            $lockedUser->increment('purchased_credits');
        });
    }

    private function refreshDailyCredits(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->refreshLockedUser($lockedUser);
        });
    }

    private function refreshLockedUser(User $user): void
    {
        $today = CarbonImmutable::today();
        $refreshedAt = $user->daily_credits_refreshed_at;

        // Accounts created under the removed paid plans are treated as free accounts.
        if (
            $user->plan !== User::PLAN_FREE
            || $user->daily_credits_plan !== User::PLAN_FREE
            || $refreshedAt === null
            || $refreshedAt->isBefore($today)
        ) {
            $user->plan = User::PLAN_FREE;
            $user->daily_credits_plan = User::PLAN_FREE;
            $user->daily_credits = self::DAILY_LIMIT;
            $user->daily_credits_refreshed_at = $today;
            $user->save();

            return;
        }

        if ($user->daily_credits > self::DAILY_LIMIT) {
            $user->daily_credits = self::DAILY_LIMIT;
            $user->save();
        }
    }
}
