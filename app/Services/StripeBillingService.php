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
use Stripe\Stripe;
use Stripe\StripeClient;

class StripeBillingService
{
    /** @return array<string, array{price_id: ?string, amount: int, credits?: int, plan?: string}> */
    public function products(): array
    {
        return [
            'credits_10' => ['price_id' => config('services.stripe.prices.credits_10'), 'amount' => 100, 'credits' => 10],
            'credits_50' => ['price_id' => config('services.stripe.prices.credits_50'), 'amount' => 400, 'credits' => 50],
            'credits_100' => ['price_id' => config('services.stripe.prices.credits_100'), 'amount' => 500, 'credits' => 100],
            'lite' => ['price_id' => config('services.stripe.prices.lite'), 'amount' => 280, 'plan' => User::PLAN_LITE],
            'premium' => ['price_id' => config('services.stripe.prices.premium'), 'amount' => 980, 'plan' => User::PLAN_PREMIUM],
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
                        if ($lockedUser->stripe_pending_subscription_product !== $productKey) {
                            throw ValidationException::withMessages([
                                'billing' => '別のプランの決済手続きが進行中です。先にその手続きを完了するか、期限切れをお待ちください。',
                            ]);
                        }

                        if (is_string($pendingSession->url) && $pendingSession->url !== '') {
                            return $pendingSession->url;
                        }
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
            'return_url' => route('plans'),
        ]);

        if (! is_string($session->url) || $session->url === '') {
            throw new RuntimeException('Stripe did not return a billing portal URL.');
        }

        return $session->url;
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

        $user->stripe_subscription_id = $eventType === 'customer.subscription.deleted' ? null : $subscriptionId;
        $user->stripe_pending_subscription_checkout_id = null;
        $user->stripe_pending_subscription_product = null;
        $user->stripe_subscription_status = $status;
        $user->stripe_current_period_end = is_numeric($periodEnd) ? date('Y-m-d H:i:s', (int) $periodEnd) : null;
        $user->stripe_subscription_event_created_at = date('Y-m-d H:i:s', $eventCreatedAt);
        $user->stripe_cancel_at_period_end = (bool) ($subscription->cancel_at_period_end ?? false);
        $user->plan = $isEntitled ? $plan : User::PLAN_FREE;
        if ($eventType === 'customer.subscription.deleted') {
            $user->stripe_subscription_status = null;
            $user->stripe_current_period_end = null;
            $user->stripe_cancel_at_period_end = false;
        }
        $user->save();
    }

    private function planForPrice(mixed $priceId): ?string
    {
        if (! is_string($priceId)) {
            return null;
        }

        return match ($priceId) {
            config('services.stripe.prices.lite') => User::PLAN_LITE,
            config('services.stripe.prices.premium') => User::PLAN_PREMIUM,
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
