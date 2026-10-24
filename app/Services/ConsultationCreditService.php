<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ConsultationCreditService
{
    /** @return array{daily: int, purchased: int, total: int|null, plan: string, unlimited: bool} */
    public function balance(User $user): array
    {
        $this->refreshDailyCredits($user);
        $user->refresh();
        $isUnlimited = $user->plan === User::PLAN_PREMIUM;

        return [
            'daily' => $user->daily_credits,
            'purchased' => $user->purchased_credits,
            'total' => $isUnlimited ? null : $user->daily_credits + $user->purchased_credits,
            'plan' => $user->plan ?? User::PLAN_FREE,
            'unlimited' => $isUnlimited,
        ];
    }

    public function consume(User $user): ?string
    {
        return DB::transaction(function () use ($user): ?string {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->refreshLockedUser($lockedUser);

            if ($lockedUser->plan === User::PLAN_PREMIUM) {
                return 'unlimited';
            }

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
        if (! in_array($bucket, ['daily', 'purchased', 'unlimited'], true)) {
            throw new \InvalidArgumentException('Unknown consultation credit bucket.');
        }

        if ($bucket === 'unlimited') {
            return;
        }

        DB::transaction(function () use ($user, $bucket): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($bucket === 'daily') {
                $limit = match ($lockedUser->plan) {
                    User::PLAN_LITE => 50,
                    User::PLAN_PREMIUM => 0,
                    default => 1,
                };
                $lockedUser->daily_credits = min($limit, $lockedUser->daily_credits + 1);
                $lockedUser->save();

                return;
            }

            $lockedUser->increment('purchased_credits');
        });
    }

    public function changePlan(User $user, string $targetPlan): void
    {
        if (! in_array($targetPlan, [User::PLAN_FREE, User::PLAN_LITE, User::PLAN_PREMIUM], true)) {
            throw new \InvalidArgumentException('Unknown consultation credit plan.');
        }

        DB::transaction(function () use ($user, $targetPlan): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentPlan = $lockedUser->plan ?? User::PLAN_FREE;
            $this->refreshLockedUser($lockedUser);

            if ($currentPlan === $targetPlan) {
                return;
            }

            if ($currentPlan === User::PLAN_LITE) {
                $lockedUser->lite_daily_credits = min(50, $lockedUser->daily_credits);
            }

            $lockedUser->daily_credits = match ($targetPlan) {
                User::PLAN_LITE => min(50, $currentPlan === User::PLAN_FREE
                    ? max(10, $lockedUser->lite_daily_credits) + $lockedUser->daily_credits
                    : max(10, $lockedUser->lite_daily_credits)),
                User::PLAN_PREMIUM => 0,
                default => 1,
            };
            $lockedUser->plan = $targetPlan;
            $lockedUser->daily_credits_plan = $targetPlan;
            $lockedUser->daily_credits_refreshed_at = CarbonImmutable::today();
            $lockedUser->save();
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
            $previousPlan = $user->daily_credits_plan ?? User::PLAN_FREE;
            if ($previousPlan === User::PLAN_LITE) {
                $user->lite_daily_credits = min(50, $user->daily_credits);
            }

            $user->daily_credits = match ($plan) {
                User::PLAN_LITE => min(50, $previousPlan === User::PLAN_FREE
                    ? max($this->dailyCreditLimit($plan), $user->lite_daily_credits) + $user->daily_credits
                    : max($this->dailyCreditLimit($plan), $user->lite_daily_credits)),
                User::PLAN_PREMIUM => 0,
                default => $this->dailyCreditLimit($plan),
            };
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
                User::PLAN_LITE => min(50, $user->daily_credits + (10 * $elapsedDays)),
                User::PLAN_PREMIUM => 0,
                default => $dailyLimit,
            };
            $user->daily_credits_refreshed_at = $today;
            $user->save();

            return;
        }

        if ($plan === User::PLAN_FREE && $user->daily_credits > 1) {
            $user->daily_credits = 1;
            $user->save();
        }
    }

    private function dailyCreditLimit(?string $plan): int
    {
        return match ($plan ?? User::PLAN_FREE) {
            User::PLAN_LITE => 10,
            User::PLAN_PREMIUM => 0,
            default => 1,
        };
    }
}
