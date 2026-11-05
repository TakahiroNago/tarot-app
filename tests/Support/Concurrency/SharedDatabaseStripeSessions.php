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
        $encodedParams = json_encode($params, JSON_THROW_ON_ERROR);

        DB::table(self::TABLE)->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'session_id' => 'cs_test_shared_'.substr(hash('sha256', $idempotencyKey), 0, 24),
            'params_hash' => hash('sha256', $encodedParams),
            'params' => $encodedParams,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'expires_at' => $params['expires_at'],
        ]);

        $record = DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();
        if ($record->params_hash !== hash('sha256', $encodedParams)) {
            throw IdempotencyException::factory('Fake: idempotency key reused with different parameters.', 400);
        }

        return $this->sessionFrom($record);
    }

    public function retrieve(string $sessionId): Session
    {
        return $this->sessionFrom($this->record($sessionId));
    }

    public function expire(string $sessionId): Session
    {
        $updated = DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->where('status', 'open')
            ->update(['status' => 'expired']);

        if ($updated === 0) {
            throw InvalidRequestException::factory('Fake: only open sessions can be expired.', 400);
        }

        return $this->retrieve($sessionId);
    }

    /**
     * @param  array{created?: array{gte?: int}, status?: string}  $params
     * @return object{data: array<int, Session>, has_more: bool}
     */
    public function all(array $params = []): object
    {
        return (object) [
            'data' => DB::table(self::TABLE)->get()->map(fn (object $record): Session => $this->sessionFrom($record))->all(),
            'has_more' => false,
        ];
    }

    private function record(string $sessionId): object
    {
        return DB::table(self::TABLE)->where('session_id', $sessionId)->first()
            ?? throw InvalidRequestException::factory('Fake: no such checkout session.', 404);
    }

    private function sessionFrom(object $record): Session
    {
        $params = json_decode($record->params, true, 512, JSON_THROW_ON_ERROR);
        $amountTotal = array_sum(array_map(
            fn (array $item): int => $item['price_data']['unit_amount'] * $item['quantity'],
            $params['line_items'] ?? [],
        ));

        return Session::constructFrom([
            'id' => $record->session_id,
            'object' => 'checkout.session',
            'status' => $record->status,
            'payment_status' => $record->payment_status,
            'payment_method_types' => ['card'],
            'payment_intent' => $record->payment_status === 'paid' ? 'pi_'.$record->session_id : null,
            'url' => $record->status === 'open' ? 'https://checkout.stripe.test/pay/'.$record->session_id : null,
            'expires_at' => (int) $record->expires_at,
            'amount_total' => $amountTotal,
            'currency' => 'jpy',
            'client_reference_id' => $params['client_reference_id'] ?? null,
            'metadata' => $params['metadata'] ?? [],
        ]);
    }
}
