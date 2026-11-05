<?php

namespace Tests\Support\Stripe;

use LogicException;
use Stripe\StripeClient;

/**
 * A StripeClient whose every SDK route to the HTTP layer resolves to a fake or fails.
 *
 * FakeStripeTest fails when an SDK update adds a public method this class has not reviewed.
 */
class FakeStripeClient extends StripeClient
{
    public function __construct(public FakeStripeCheckoutSessions $fakeCheckoutSessions)
    {
        parent::__construct('sk_test_fake_never_sent');
    }

    public function __get($name): object
    {
        if ($name === 'checkout') {
            return (object) ['sessions' => $this->fakeCheckoutSessions];
        }

        throw new LogicException("The fake Stripe client does not implement the [{$name}] service.");
    }

    public function getService($name): never
    {
        $this->refuseNetworkAccess(__FUNCTION__);
    }

    public function request($method, $path, $params, $opts): never
    {
        $this->refuseNetworkAccess(__FUNCTION__);
    }

    public function rawRequest($method, $path, $params = null, $opts = [], $maxNetworkRetries = null, $usage = null): never
    {
        $this->refuseNetworkAccess(__FUNCTION__);
    }

    public function requestStream($method, $path, $readBodyChunkCallable, $params, $opts): never
    {
        $this->refuseNetworkAccess(__FUNCTION__);
    }

    public function requestCollection($method, $path, $params, $opts): never
    {
        $this->refuseNetworkAccess(__FUNCTION__);
    }

    public function requestSearchResult($method, $path, $params, $opts): never
    {
        $this->refuseNetworkAccess(__FUNCTION__);
    }

    private function refuseNetworkAccess(string $method): never
    {
        throw new LogicException("The fake Stripe client refuses [{$method}] because it could reach the network.");
    }
}
