<?php

namespace Tests\Support\Stripe;

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Contracts\Foundation\Application;
use LogicException;
use Stripe\StripeClient;

/**
 * Swaps the application's Stripe client for an in-memory fake.
 *
 * The parent's customer methods build a real client through a private method, so they are
 * refused here rather than inherited.
 */
class FakeStripeBillingService extends StripeBillingService
{
    public function __construct(private FakeStripeClient $fakeClient) {}

    public static function install(Application $app, ?FakeStripeCheckoutSessions $sessions = null): FakeStripeCheckoutSessions
    {
        $sessions ??= new FakeStripeCheckoutSessions;
        $app->instance(StripeBillingService::class, new self(new FakeStripeClient($sessions)));
        $app['config']->set('services.stripe.ecommerce_secret', 'sk_test_fake_never_sent');

        return $sessions;
    }

    public function ecommerceClient(): StripeClient
    {
        return $this->fakeClient;
    }

    public function customerId(User $user): string
    {
        throw new LogicException('The fake billing service refuses customerId because it would reach Stripe.');
    }

    public function deleteCustomerForAccountDeletion(User $user): void
    {
        throw new LogicException('The fake billing service refuses customer deletion because it would reach Stripe.');
    }
}
