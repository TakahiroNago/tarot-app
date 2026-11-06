<?php

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Replaces the SDK's HTTP layer, so the key actually sent can be read without reaching Stripe.
 */
function recordStripeRequests(string $responseBody): ArrayObject
{
    $sentHeaders = new ArrayObject;

    ApiRequestor::setHttpClient(new class($sentHeaders, $responseBody) implements ClientInterface
    {
        public function __construct(private ArrayObject $sentHeaders, private string $responseBody) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->sentHeaders->append($headers);

            return [$this->responseBody, 200, []];
        }
    });

    return $sentHeaders;
}

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_tarot_account_key',
        'services.stripe.ecommerce_secret' => 'sk_test_shop_account_key',
        'services.stripe.ca_bundle' => null,
    ]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('shop calls authenticate with the shop account key', function () {
    $sentHeaders = recordStripeRequests('{"id":"re_1","object":"refund"}');

    app(StripeBillingService::class)->ecommerceClient()->refunds->create(['payment_intent' => 'pi_1']);

    expect($sentHeaders[0])->toContain('Authorization: Bearer sk_test_shop_account_key');
});

test('deleting a customer authenticates with the tarot account key', function () {
    $sentHeaders = recordStripeRequests('{"id":"cus_1","object":"customer","deleted":true}');
    $user = User::factory()->make(['stripe_customer_id' => 'cus_1']);

    app(StripeBillingService::class)->deleteCustomerForAccountDeletion($user);

    expect($sentHeaders[0])->toContain('Authorization: Bearer sk_test_tarot_account_key');
});

test('the shop refuses to fall back to the tarot account key', function () {
    config(['services.stripe.ecommerce_secret' => null]);

    expect(fn () => app(StripeBillingService::class)->ecommerceClient())
        ->toThrow(RuntimeException::class, 'The ecommerce Stripe secret key is not configured.');
});

test('the shop key cannot delete a customer from the tarot account', function () {
    config(['services.stripe.secret' => null]);
    $sentHeaders = recordStripeRequests('{}');
    $user = User::factory()->make(['stripe_customer_id' => 'cus_1']);

    expect(fn () => app(StripeBillingService::class)->deleteCustomerForAccountDeletion($user))
        ->toThrow(ValidationException::class)
        ->and($sentHeaders)->toHaveCount(0);
});
