<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\Stripe\FakeStripeCheckoutSessions;

/**
 * A Stripe fake shared by worker processes through the disposable database, so idempotency and
 * the number of sessions created for an order can be observed across processes.
 */
class SharedDatabaseStripeSessions extends FakeStripeCheckoutSessions
{
    public const TABLE = 'concurrency_harness_stripe_sessions';

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): Session
    {
        $idempotencyKey = (string) $options['idempotency_key'];
        $paramsHash = hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));

        DB::table(self::TABLE)->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'session_id' => 'cs_test_shared_'.substr(hash('sha256', $idempotencyKey), 0, 24),
            'params_hash' => $paramsHash,
            'expires_at' => $params['expires_at'],
        ]);

        $record = DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();
        if ($record->params_hash !== $paramsHash) {
            throw IdempotencyException::factory('Fake: idempotency key reused with different parameters.', 400);
        }

        return $this->sessionFrom($record);
    }

    public function retrieve(string $sessionId): Session
    {
        $record = DB::table(self::TABLE)->where('session_id', $sessionId)->first()
            ?? throw InvalidRequestException::factory('Fake: no such checkout session.', 404);

        return $this->sessionFrom($record);
    }

    private function sessionFrom(object $record): Session
    {
        return Session::constructFrom([
            'id' => $record->session_id,
            'object' => 'checkout.session',
            'status' => 'open',
            'url' => 'https://checkout.stripe.test/pay/'.$record->session_id,
            'expires_at' => (int) $record->expires_at,
        ]);
    }
}
