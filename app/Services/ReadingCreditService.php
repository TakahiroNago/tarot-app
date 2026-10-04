<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReadingCreditService
{
    /** @return array{daily: int, purchased: int, total: int, plan: string} */
    public function balance(User $user): array
    {
        $this->refreshDailyCredits($user);
        $user->refresh();

        return [
            'daily' => $user->daily_credits,
            'purchased' => $user->purchased_credits,
            'total' => $user->daily_credits + $user->purchased_credits,
            'plan' => $user->plan ?? User::PLAN_FREE,
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
                $limit = match ($lockedUser->plan) {
                    User::PLAN_LITE => 150,
                    User::PLAN_PREMIUM => 300,
                    default => 3,
                };
                $lockedUser->daily_credits = min($limit, $lockedUser->daily_credits + 1);
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
        $plan = $user->plan ?? User::PLAN_FREE;
        if ($user->plan === null) {
            $user->plan = $plan;
            $user->save();
        }

        $today = CarbonImmutable::today();
        $refreshedAt = $user->daily_credits_refreshed_at;

        if ($user->daily_credits_plan !== $plan) {
            $maximumBalance = match ($plan) {
                User::PLAN_LITE => 150,
                User::PLAN_PREMIUM => 300,
                default => 3,
            };
            $user->daily_credits = min(
                $maximumBalance,
                max($user->daily_credits, $this->dailyCreditLimit($plan)),
            );
            $user->daily_credits_plan = $plan;
            $user->daily_credits_refreshed_at = $today;
            $user->save();

            return;
        }

        if ($refreshedAt === null) {
            $user->daily_credits = $this->dailyCreditLimit($plan);
            $user->daily_credits_refreshed_at = $today;
            $user->save();

            return;
        }

        if ($refreshedAt->isBefore($today)) {
            $elapsedDays = (int) $refreshedAt->diffInDays($today);
            $dailyLimit = $this->dailyCreditLimit($plan);

            $user->daily_credits = match ($plan) {
                User::PLAN_LITE => min(150, $user->daily_credits + (5 * $elapsedDays)),
                User::PLAN_PREMIUM => min(300, $user->daily_credits + (10 * $elapsedDays)),
                default => $dailyLimit,
            };
            $user->daily_credits_refreshed_at = $today;
            $user->save();

            return;
        }

        if ($plan === User::PLAN_FREE && $user->daily_credits > 3) {
            $user->daily_credits = 3;
            $user->save();
        }
    }

    private function dailyCreditLimit(?string $plan): int
    {
        return match ($plan ?? User::PLAN_FREE) {
            User::PLAN_LITE => 5,
            User::PLAN_PREMIUM => 10,
            default => 3,
        };
    }
}
