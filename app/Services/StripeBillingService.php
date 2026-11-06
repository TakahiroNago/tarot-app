<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\StripeClient;

/**
 * Reaches two separate Stripe accounts: the shop's, and the one holding tarot customers.
 *
 * Subscription plans have been removed.
 */
class StripeBillingService
{
    /**
     * Orders, sessions and refunds exist only in the shop's account, so a missing key
     * must stop the shop rather than fall back to the tarot account.
     */
    public function ecommerceClient(): StripeClient
    {
        return $this->clientFor(config('services.stripe.ecommerce_secret'), 'The ecommerce Stripe secret key is not configured.');
    }

    public function handleWebhook(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            if (DB::table('stripe_webhook_events')->where('stripe_event_id', $event->id)->exists()) {
                return;
            }

            // Event handlers for EC payments are added here.

            DB::table('stripe_webhook_events')->insert([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function customerId(User $user): string
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

    public function deleteCustomerForAccountDeletion(User $user): void
    {
        if ($user->stripe_customer_id === null) {
            return;
        }

        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            throw ValidationException::withMessages([
                'account' => '決済サービスに接続できないため、アカウントは削除されませんでした。サポートへお問い合わせください。',
            ]);
        }

        try {
            $this->stripe()->customers->delete($user->stripe_customer_id);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'account' => '決済サービスの情報を削除できなかったため、アカウントは削除されませんでした。時間をおいて再度お試しください。',
            ]);
        }
    }

    private function stripe(): StripeClient
    {
        return $this->clientFor(config('services.stripe.secret'), 'Stripe secret key is not configured.');
    }

    private function clientFor(mixed $secret, string $missingKeyMessage): StripeClient
    {
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException($missingKeyMessage);
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
