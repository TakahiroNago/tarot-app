<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\StripeClient;

class StripeBillingService
{
    public function __construct(private readonly ConsultationCreditService $creditService) {}

    /** @return array<string, array{price_id: ?string, amount: int, credits?: int, plan?: string}> */
    public function products(): array
    {
        return [
            'credits_10' => ['price_id' => config('services.stripe.prices.credits_10'), 'amount' => 100, 'credits' => 10],
            'credits_50' => ['price_id' => config('services.stripe.prices.credits_50'), 'amount' => 400, 'credits' => 50],
            'credits_100' => ['price_id' => config('services.stripe.prices.credits_100'), 'amount' => 500, 'credits' => 100],
            'lite' => ['price_id' => config('services.stripe.prices.lite'), 'amount' => 220, 'plan' => User::PLAN_LITE],
            'lite_yearly' => ['price_id' => config('services.stripe.prices.lite_yearly'), 'amount' => 2200, 'plan' => User::PLAN_LITE],
            'premium' => ['price_id' => config('services.stripe.prices.premium'), 'amount' => 580, 'plan' => User::PLAN_PREMIUM],
            'premium_yearly' => ['price_id' => config('services.stripe.prices.premium_yearly'), 'amount' => 5800, 'plan' => User::PLAN_PREMIUM],
        ];
    }

    public function checkoutUrl(User $user, string $productKey): string
    {
        $product = $this->products()[$productKey] ?? throw new RuntimeException('Unknown Stripe product.');
        $priceId = $product['price_id'];
        if (! is_string($priceId) || $priceId === '') {
            throw new RuntimeException("Stripe Price ID is not configured for {$productKey}.");
        }

        if (isset($product['plan']) && ($user->plan !== User::PLAN_FREE || $user->stripe_subscription_id !== null)) {
            throw new RuntimeException('An existing subscription must be managed through the billing portal.');
        }

        $metadata = [
            'user_id' => (string) $user->id,
            'product_key' => $productKey,
            'price_id' => $priceId,
        ];
        $mode = isset($product['credits']) ? 'payment' : 'subscription';
        if ($mode === 'subscription') {
            return DB::transaction(function () use ($user, $productKey, $priceId, $metadata, $mode): string {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                if ($lockedUser->plan !== User::PLAN_FREE || $lockedUser->stripe_subscription_id !== null) {
                    throw ValidationException::withMessages([
                        'billing' => '有効な契約がある場合は、契約管理画面からプランを変更してください。',
                    ]);
                }

                if (is_string($lockedUser->stripe_pending_subscription_checkout_id)) {
                    $pendingSession = $this->stripe()->checkout->sessions->retrieve(
                        $lockedUser->stripe_pending_subscription_checkout_id,
                    );
                    if ($pendingSession->status === 'open') {
                        if (
                            $lockedUser->stripe_pending_subscription_product === $productKey
                            && is_string($pendingSession->url)
                            && $pendingSession->url !== ''
                        ) {
                            return $pendingSession->url;
                        }

                        $this->stripe()->checkout->sessions->expire($pendingSession->id);
                    } elseif ($pendingSession->status === 'complete') {
                        throw ValidationException::withMessages([
                            'billing' => '決済を確認中です。しばらくお待ちください。',
                        ]);
                    }

                    $lockedUser->stripe_pending_subscription_checkout_id = null;
                    $lockedUser->stripe_pending_subscription_product = null;
                }

                $session = $this->createCheckoutSession($lockedUser, $productKey, $priceId, $metadata, $mode);
                $lockedUser->stripe_pending_subscription_checkout_id = $session->id;
                $lockedUser->stripe_pending_subscription_product = $productKey;
                $lockedUser->save();

                return $this->sessionUrl($session);
            });
        }

        return $this->sessionUrl($this->createCheckoutSession($user, $productKey, $priceId, $metadata, $mode));
    }

    private function createCheckoutSession(
        User $user,
        string $productKey,
        string $priceId,
        array $metadata,
        string $mode,
    ): Session {
        $session = $this->stripe()->checkout->sessions->create([
            'mode' => $mode,
            'customer' => $this->customerId($user),
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'client_reference_id' => (string) $user->id,
            'metadata' => $metadata,
            ...($mode === 'subscription' ? ['subscription_data' => ['metadata' => $metadata]] : []),
            'success_url' => route('plans', ['checkout' => 'success']),
            'cancel_url' => route('plans', ['checkout' => 'cancelled']),
        ], [
            'idempotency_key' => 'checkout-'.$user->id.'-'.$productKey.'-'.Str::uuid(),
        ]);

        return $session;
    }

    private function sessionUrl(Session $session): string
    {
        if (! is_string($session->url) || $session->url === '') {
            throw new RuntimeException('Stripe did not return a Checkout URL.');
        }

        return $session->url;
    }

    public function portalUrl(User $user): string
    {
        if (! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            throw new RuntimeException('The user does not have a Stripe customer.');
        }

        $session = $this->stripe()->billingPortal->sessions->create([
            'customer' => $user->stripe_customer_id,
            'return_url' => route('account.settings.edit', ['billing' => 'returned']),
        ]);

        if (! is_string($session->url) || $session->url === '') {
            throw new RuntimeException('Stripe did not return a billing portal URL.');
        }

        return $session->url;
    }

    public function changePlan(User $user, string $targetProduct): string
    {
        $product = $this->products()[$targetProduct] ?? null;
        $targetPlan = $product['plan'] ?? null;
        if (
            ! in_array($targetProduct, ['lite', 'premium', 'premium_yearly'], true)
            || ! in_array($targetPlan, [User::PLAN_LITE, User::PLAN_PREMIUM], true)
        ) {
            throw ValidationException::withMessages([
                'billing' => '選択したプランは変更できません。',
            ]);
        }

        return DB::transaction(function () use ($user, $targetPlan, $targetProduct): string {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);

            if (
                ! is_string($account->stripe_customer_id)
                || $account->stripe_customer_id === ''
                || ! is_string($account->stripe_subscription_id)
                || $account->stripe_subscription_id === ''
            ) {
                throw ValidationException::withMessages([
                    'billing' => '変更できる有料プランの契約が見つかりません。',
                ]);
            }

            if ($account->stripe_scheduled_plan !== null || $account->stripe_cancel_at_period_end) {
                throw ValidationException::withMessages([
                    'billing' => '予約済みの変更または解約があります。先に現在の契約状態をご確認ください。',
                ]);
            }

            if (
                ($account->plan === User::PLAN_LITE && $targetPlan !== User::PLAN_PREMIUM)
                || ($account->plan === User::PLAN_PREMIUM && $targetPlan !== User::PLAN_LITE)
            ) {
                throw ValidationException::withMessages([
                    'billing' => '現在のプランから選択したプランへは変更できません。',
                ]);
            }

            $stripe = $this->stripe();
            $subscription = $stripe->subscriptions->retrieve($account->stripe_subscription_id);
            if (
                $subscription->customer !== $account->stripe_customer_id
                || ! in_array($subscription->status, ['active', 'trialing'], true)
            ) {
                throw ValidationException::withMessages([
                    'billing' => 'Stripe上の契約状態を確認できません。時間をおいて再度お試しください。',
                ]);
            }

            if (
                (isset($subscription->cancel_at_period_end) && $subscription->cancel_at_period_end)
                || isset($subscription->schedule)
            ) {
                throw ValidationException::withMessages([
                    'billing' => 'Stripe上に別の解約またはプラン変更の予約があります。契約状態をご確認ください。',
                ]);
            }

            if (isset($subscription->pending_update)) {
                throw ValidationException::withMessages([
                    'billing' => '前回のプラン変更の決済を確認中です。決済完了後に再度お試しください。',
                ]);
            }

            $items = $subscription->items->data ?? [];
            if (count($items) !== 1) {
                throw ValidationException::withMessages([
                    'billing' => 'この契約はオンラインで変更できません。サポートへお問い合わせください。',
                ]);
            }

            $subscriptionItem = $items[0];
            $currentPriceId = $subscriptionItem->price->id ?? null;
            if ($this->planForPrice($currentPriceId) !== $account->plan) {
                throw ValidationException::withMessages([
                    'billing' => 'アプリとStripeの契約情報が一致しません。時間をおいて再度お試しください。',
                ]);
            }

            $targetPriceId = $this->products()[$targetProduct]['price_id'];
            if (! is_string($targetPriceId) || $targetPriceId === '') {
                throw ValidationException::withMessages([
                    'billing' => '変更先プランの決済設定が完了していません。',
                ]);
            }

            if ($targetPlan === User::PLAN_PREMIUM) {
                try {
                    $updatedSubscription = $stripe->subscriptions->update($subscription->id, [
                        'items' => [[
                            'id' => $subscriptionItem->id,
                            'price' => $targetPriceId,
                        ]],
                        'proration_behavior' => 'always_invoice',
                        'payment_behavior' => 'pending_if_incomplete',
                    ]);
                } catch (ApiErrorException $exception) {
                    report($exception);

                    throw ValidationException::withMessages([
                        'billing' => 'プラン変更の決済を完了できませんでした。お支払い方法をご確認のうえ、再度お試しください。',
                    ]);
                }

                return isset($updatedSubscription->pending_update) ? 'upgrade_pending' : 'upgraded';
            }

            $periodEnd = $subscription->current_period_end
                ?? $subscriptionItem->current_period_end
                ?? null;
            if (! is_numeric($periodEnd) || (int) $periodEnd <= now()->timestamp) {
                throw ValidationException::withMessages([
                    'billing' => '契約期間の終了日を確認できません。時間をおいて再度お試しください。',
                ]);
            }

            $schedule = null;
            try {
                $schedule = $stripe->subscriptionSchedules->create([
                    'from_subscription' => $subscription->id,
                ]);
                if (! is_string($schedule->id ?? null) || $schedule->id === '') {
                    throw new RuntimeException('Stripe did not return a subscription schedule ID.');
                }

                $currentPhase = $schedule->phases[0] ?? null;
                if ($currentPhase === null) {
                    throw new RuntimeException('Stripe did not return the current subscription schedule phase.');
                }

                $currentPhaseParams = $this->schedulePhaseParameters($currentPhase);
                $currentPhaseParams['start_date'] = $currentPhase->start_date
                    ?? $subscription->current_period_start
                    ?? $subscriptionItem->current_period_start
                    ?? null;
                $currentPhaseParams['end_date'] = (int) $periodEnd;
                $currentPhaseParams['items'] = $this->schedulePhaseItems($currentPhase);
                $currentPhaseParams['proration_behavior'] = 'none';
                if (! is_numeric($currentPhaseParams['start_date'])) {
                    throw new RuntimeException('Stripe did not return the current subscription phase start date.');
                }

                $nextPhaseParams = $this->schedulePhaseParameters($currentPhase);
                $nextPhaseParams['start_date'] = (int) $periodEnd;
                $nextPhaseParams['iterations'] = 1;
                $nextPhaseParams['items'] = [[
                    'price' => $targetPriceId,
                    'quantity' => $subscriptionItem->quantity ?? 1,
                    ...$this->scheduleItemTaxRates($currentPhase),
                ]];
                $nextPhaseParams['proration_behavior'] = 'none';

                $stripe->subscriptionSchedules->update($schedule->id, [
                    'end_behavior' => 'release',
                    'phases' => [$currentPhaseParams, $nextPhaseParams],
                ]);
            } catch (ApiErrorException $exception) {
                report($exception);
                $this->releaseIncompleteSchedule($stripe, $schedule);

                throw ValidationException::withMessages([
                    'billing' => 'プラン変更を予約できませんでした。時間をおいて再度お試しください。',
                ]);
            } catch (RuntimeException $exception) {
                $this->releaseIncompleteSchedule($stripe, $schedule);

                throw $exception;
            }

            $account->stripe_scheduled_plan = $targetPlan;
            $account->stripe_subscription_schedule_id = $schedule->id;
            $account->stripe_current_period_end = date('Y-m-d H:i:s', (int) $periodEnd);
            $account->save();

            return 'downgrade_scheduled';
        });
    }

    public function cancelScheduledPlanChange(User $user): void
    {
        $account = User::query()->findOrFail($user->id);
        if (
            ! is_string($account->stripe_subscription_schedule_id)
            || $account->stripe_subscription_schedule_id === ''
            || $account->stripe_scheduled_plan === null
        ) {
            throw ValidationException::withMessages([
                'billing' => '取り消せるプラン変更の予約がありません。',
            ]);
        }

        try {
            $this->stripe()->subscriptionSchedules->release($account->stripe_subscription_schedule_id);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'billing' => 'プラン変更の予約を取り消せませんでした。時間をおいて再度お試しください。',
            ]);
        }

        $account->stripe_scheduled_plan = null;
        $account->stripe_subscription_schedule_id = null;
        $account->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePhaseParameters(object $phase): array
    {
        $phaseData = $phase->toArray();
        $parameterKeys = [
            'application_fee_percent',
            'automatic_tax',
            'billing_cycle_anchor',
            'collection_method',
            'default_payment_method',
            'default_tax_rates',
            'description',
            'discounts',
            'invoice_settings',
            'metadata',
            'on_behalf_of',
            'transfer_data',
        ];

        $parameters = array_filter(
            array_intersect_key($phaseData, array_flip($parameterKeys)),
            static fn (mixed $value): bool => $value !== null,
        );

        if (is_array($parameters['automatic_tax'] ?? null)) {
            $parameters['automatic_tax'] = array_intersect_key(
                $parameters['automatic_tax'],
                array_flip(['enabled', 'liability']),
            );
        }

        return $parameters;
    }

    /**
     * @return array<int, array{price: string, quantity?: int, tax_rates?: array<int, string>}>
     */
    private function schedulePhaseItems(object $phase): array
    {
        $items = [];
        foreach ($phase->items as $item) {
            $price = $item->price ?? null;
            $priceId = is_string($price) ? $price : ($price->id ?? null);
            if (! is_string($priceId) || $priceId === '') {
                throw new RuntimeException('Stripe subscription phase is missing a price ID.');
            }

            $items[] = [
                'price' => $priceId,
                ...((is_int($item->quantity ?? null)) ? ['quantity' => $item->quantity] : []),
                ...$this->scheduleItemTaxRates($item),
            ];
        }

        return $items;
    }

    /**
     * @return array{tax_rates?: array<int, string>}
     */
    private function scheduleItemTaxRates(object $item): array
    {
        $taxRates = [];
        foreach ($item->tax_rates ?? [] as $taxRate) {
            $taxRateId = is_string($taxRate) ? $taxRate : ($taxRate->id ?? null);
            if (is_string($taxRateId) && $taxRateId !== '') {
                $taxRates[] = $taxRateId;
            }
        }

        return $taxRates === [] ? [] : ['tax_rates' => $taxRates];
    }

    private function releaseIncompleteSchedule(StripeClient $stripe, ?object $schedule): void
    {
        if ($schedule === null || ! is_string($schedule->id ?? null) || $schedule->id === '') {
            return;
        }

        try {
            $stripe->subscriptionSchedules->release($schedule->id);
        } catch (ApiErrorException $exception) {
            report($exception);
        }
    }

    public function cancelForAccountDeletion(User $user): void
    {
        if (
            $user->stripe_pending_subscription_checkout_id === null
            && $user->stripe_subscription_id === null
            && $user->stripe_customer_id === null
        ) {
            return;
        }

        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            throw ValidationException::withMessages([
                'account' => '決済サービスに接続できないため、アカウントは削除されませんでした。サポートへお問い合わせください。',
            ]);
        }

        try {
            $stripe = $this->stripe();
            $subscriptionIds = array_filter([$user->stripe_subscription_id], 'is_string');

            if (is_string($user->stripe_pending_subscription_checkout_id)) {
                $checkout = $stripe->checkout->sessions->retrieve($user->stripe_pending_subscription_checkout_id);

                if ($checkout->status === 'open') {
                    $stripe->checkout->sessions->expire($checkout->id);
                } elseif ($checkout->status === 'complete') {
                    $subscription = $checkout->subscription ?? null;
                    $subscriptionId = is_string($subscription)
                        ? $subscription
                        : (is_string($subscription->id ?? null) ? $subscription->id : null);

                    if ($subscriptionId !== null) {
                        $subscriptionIds[] = $subscriptionId;
                    }
                }
            }

            foreach (array_unique($subscriptionIds) as $subscriptionId) {
                $subscription = $stripe->subscriptions->retrieve($subscriptionId);

                if (! in_array($subscription->status, ['canceled', 'incomplete_expired'], true)) {
                    $stripe->subscriptions->cancel($subscriptionId);
                }
            }

            if (is_string($user->stripe_customer_id)) {
                $stripe->customers->delete($user->stripe_customer_id);
            }
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'account' => '決済サービスの契約を停止できなかったため、アカウントは削除されませんでした。時間をおいて再度お試しください。',
            ]);
        }
    }

    public function handleWebhook(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            if (DB::table('stripe_webhook_events')->where('stripe_event_id', $event->id)->exists()) {
                return;
            }

            match ($event->type) {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->handleCheckoutSession($event->data->object),
                'checkout.session.expired' => $this->handleExpiredCheckoutSession($event->data->object),
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'customer.subscription.paused',
                'customer.subscription.resumed' => $this->handleSubscription($event->data->object, $event->type, $event->created),
                'subscription_schedule.canceled',
                'subscription_schedule.released',
                'subscription_schedule.completed' => $this->handleSubscriptionSchedule($event->data->object),
                default => null,
            };

            DB::table('stripe_webhook_events')->insert([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function customerId(User $user): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe()->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string) $user->id],
        ], ['idempotency_key' => 'customer-'.$user->id]);

        $lockedUser = User::query()->findOrFail($user->id);
        if ($lockedUser->stripe_customer_id === null) {
            $lockedUser->stripe_customer_id = $customer->id;
            $lockedUser->save();
        }

        return $lockedUser->stripe_customer_id;
    }

    private function handleCheckoutSession(Session $session): void
    {
        if ($session->mode !== 'payment' || $session->payment_status !== 'paid') {
            return;
        }

        $metadata = $session->metadata;
        $productKey = is_string($metadata->product_key ?? null) ? $metadata->product_key : '';
        $userId = filter_var($metadata->user_id ?? null, FILTER_VALIDATE_INT);
        $product = $this->products()[$productKey] ?? null;
        $priceId = $product['price_id'] ?? null;
        $sessionPriceId = is_string($metadata->price_id ?? null) ? $metadata->price_id : null;

        if (
            $product === null
            || ! isset($product['credits'])
            || ! is_int($userId)
            || ! is_string($priceId)
            || $priceId === ''
            || $sessionPriceId !== $priceId
            || $session->currency !== 'jpy'
            || $session->amount_total !== $product['amount']
        ) {
            throw new RuntimeException('Stripe Checkout session does not match a configured credit product.');
        }

        $user = User::query()->lockForUpdate()->findOrFail($userId);
        $inserted = DB::table('stripe_credit_purchases')->insertOrIgnore([
            'user_id' => $user->id,
            'checkout_session_id' => $session->id,
            'product_key' => $productKey,
            'price_id' => $priceId,
            'credits' => $product['credits'],
            'amount_total' => $session->amount_total,
            'currency' => $session->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            $user->increment('purchased_credits', $product['credits']);
        }
    }

    private function handleExpiredCheckoutSession(Session $session): void
    {
        if ($session->mode !== 'subscription') {
            return;
        }

        $userId = filter_var($session->metadata->user_id ?? null, FILTER_VALIDATE_INT);
        if (! is_int($userId)) {
            return;
        }

        $user = User::query()->lockForUpdate()->find($userId);
        if ($user !== null && $user->stripe_pending_subscription_checkout_id === $session->id) {
            $user->stripe_pending_subscription_checkout_id = null;
            $user->stripe_pending_subscription_product = null;
            $user->save();
        }
    }

    private function handleSubscription(object $subscription, string $eventType, int $eventCreatedAt): void
    {
        $customerId = $subscription->customer ?? null;
        if (! is_string($customerId) || $customerId === '') {
            throw new RuntimeException('Stripe subscription event is missing a customer ID.');
        }

        $user = User::query()->where('stripe_customer_id', $customerId)->lockForUpdate()->first();
        if ($user === null) {
            Log::warning('Stripe subscription event references an unknown customer.', [
                'stripe_customer_id' => $customerId,
                'stripe_subscription_id' => $subscription->id ?? null,
            ]);

            return;
        }

        $subscriptionId = is_string($subscription->id ?? null) ? $subscription->id : null;
        $status = is_string($subscription->status ?? null) ? $subscription->status : null;
        if ($subscriptionId === null || $status === null) {
            throw new RuntimeException('Stripe subscription event is missing required fields.');
        }

        if (
            $user->stripe_subscription_id !== null
            && $user->stripe_subscription_id !== $subscriptionId
        ) {
            return;
        }

        if (
            $user->stripe_subscription_event_created_at !== null
            && $eventCreatedAt < $user->stripe_subscription_event_created_at->timestamp
        ) {
            return;
        }

        $priceId = $subscription->items->data[0]->price->id ?? null;
        $plan = $this->planForPrice($priceId);
        $isEntitled = in_array($status, ['active', 'trialing'], true) && $plan !== null;
        $periodEnd = $subscription->current_period_end
            ?? $subscription->items->data[0]->current_period_end
            ?? null;

        $this->creditService->changePlan($user, $isEntitled ? $plan : User::PLAN_FREE);
        $user->refresh();
        $user->stripe_subscription_id = $eventType === 'customer.subscription.deleted' ? null : $subscriptionId;
        $user->stripe_pending_subscription_checkout_id = null;
        $user->stripe_pending_subscription_product = null;
        $user->stripe_subscription_status = $status;
        $user->stripe_current_period_end = is_numeric($periodEnd) ? date('Y-m-d H:i:s', (int) $periodEnd) : null;
        $user->stripe_subscription_event_created_at = date('Y-m-d H:i:s', $eventCreatedAt);
        $user->stripe_cancel_at_period_end = (bool) ($subscription->cancel_at_period_end ?? false);
        if (
            $eventType === 'customer.subscription.deleted'
            || ($user->stripe_scheduled_plan !== null && $user->stripe_scheduled_plan === $plan)
        ) {
            $user->stripe_scheduled_plan = null;
            $user->stripe_subscription_schedule_id = null;
        }
        if ($eventType === 'customer.subscription.deleted') {
            $user->stripe_subscription_status = null;
            $user->stripe_current_period_end = null;
            $user->stripe_cancel_at_period_end = false;
        }
        $user->save();
    }

    private function handleSubscriptionSchedule(object $schedule): void
    {
        $scheduleId = is_string($schedule->id ?? null) ? $schedule->id : null;
        if ($scheduleId === null) {
            throw new RuntimeException('Stripe subscription schedule event is missing an ID.');
        }

        $user = User::query()
            ->where('stripe_subscription_schedule_id', $scheduleId)
            ->lockForUpdate()
            ->first();
        if ($user === null) {
            return;
        }

        $user->stripe_scheduled_plan = null;
        $user->stripe_subscription_schedule_id = null;
        $user->save();
    }

    private function planForPrice(mixed $priceId): ?string
    {
        if (! is_string($priceId)) {
            return null;
        }

        return match ($priceId) {
            config('services.stripe.prices.lite') => User::PLAN_LITE,
            config('services.stripe.prices.lite_yearly') => User::PLAN_LITE,
            config('services.stripe.prices.premium') => User::PLAN_PREMIUM,
            config('services.stripe.prices.premium_yearly') => User::PLAN_PREMIUM,
            default => null,
        };
    }

    private function stripe(): StripeClient
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $caBundle = config('services.stripe.ca_bundle');
        if (is_string($caBundle) && $caBundle !== '') {
            if (! is_file($caBundle)) {
                throw new RuntimeException('Configured Stripe CA bundle does not exist.');
            }

            Stripe::setCABundlePath($caBundle);
        }

        return new StripeClient($secret);
    }
}
