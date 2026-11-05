<?php

namespace Tests\Support\Stripe;

use Closure;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Throwable;

/**
 * In-memory stand-in for the Stripe Checkout Sessions API.
 *
 * Idempotency follows Stripe's documented rules: the same key with the same parameters replays
 * the first result, and the same key with different parameters raises an idempotency error.
 * Behaviour not yet verified against Stripe test mode is opt-in through the failure helpers.
 */
class FakeStripeCheckoutSessions
{
    /**
     * @var array<int, array{params: array<string, mixed>, idempotency_key: string|null}>
     */
    public array $createCalls = [];

    /**
     * @var array<int, array{received: bool, exception: Throwable}>
     */
    private array $queuedFailures = [];

    /**
     * @var array<string, array{params: array<string, mixed>, result: Session|Throwable}>
     */
    private array $idempotencyRecords = [];

    /**
     * @var array<string, Session>
     */
    private array $sessions = [];

    private int $nextSessionNumber = 1;

    /**
     * @var array<int, string>
     */
    private array $paymentMethods = ['card'];

    private ?Throwable $nextListFailure = null;

    private ?Closure $duringNextCreate = null;

    /**
     * The request never reached Stripe, so no idempotency record or session exists.
     */
    public function failBeforeStripeReceivesRequest(): self
    {
        $this->queuedFailures[] = [
            'received' => false,
            'exception' => new ApiConnectionException('Fake: could not connect to Stripe.'),
        ];

        return $this;
    }

    /**
     * Stripe created the session but its response was lost, so a replay returns that session.
     */
    public function loseResponseAfterStripeCreatesSession(): self
    {
        $this->queuedFailures[] = [
            'received' => true,
            'exception' => new ApiConnectionException('Fake: connection lost awaiting the Stripe response.'),
        ];

        return $this;
    }

    /**
     * Stripe stored a server error for the key, so a replay returns the same error.
     */
    public function failWithStoredServerError(): self
    {
        $exception = UnknownApiErrorException::factory('Fake: Stripe internal error.', 500);
        $this->queuedFailures[] = ['received' => true, 'exception' => $exception];

        return $this;
    }

    /**
     * Stripe rejected the request during validation, so the key is not stored.
     */
    public function rejectInvalidRequest(string $message, ?string $param = null): self
    {
        $this->queuedFailures[] = [
            'received' => false,
            'exception' => InvalidRequestException::factory($message, 400, null, null, null, null, $param),
        ];

        return $this;
    }

    /**
     * Another request with this key is still running, as Stripe reports with a 409.
     */
    public function failWithKeyInUse(): self
    {
        $this->queuedFailures[] = [
            'received' => false,
            'exception' => UnknownApiErrorException::factory('Fake: key in use.', 409, null, null, null, 'idempotency_key_in_use'),
        ];

        return $this;
    }

    public function failWith(Throwable $exception): self
    {
        $this->queuedFailures[] = ['received' => false, 'exception' => $exception];

        return $this;
    }

    /**
     * @param  array<int, string>  $paymentMethods
     */
    public function offering(array $paymentMethods): self
    {
        $this->paymentMethods = $paymentMethods;

        return $this;
    }

    public function failNextList(Throwable $exception): self
    {
        $this->nextListFailure = $exception;

        return $this;
    }

    /**
     * Run a callback while the next create request is in flight.
     */
    public function duringNextCreate(Closure $callback): self
    {
        $this->duringNextCreate = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): Session
    {
        $idempotencyKey = isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : null;
        $this->createCalls[] = ['params' => $params, 'idempotency_key' => $idempotencyKey];

        if ($this->duringNextCreate !== null) {
            ($this->duringNextCreate)();
            $this->duringNextCreate = null;
        }

        if ($idempotencyKey !== null && isset($this->idempotencyRecords[$idempotencyKey])) {
            $record = $this->idempotencyRecords[$idempotencyKey];
            if ($record['params'] !== $params) {
                throw IdempotencyException::factory('Fake: idempotency key reused with different parameters.', 400);
            }
            if ($record['result'] instanceof Throwable) {
                throw $record['result'];
            }

            return $record['result'];
        }

        $failure = array_shift($this->queuedFailures);
        if ($failure !== null && ! $failure['received']) {
            throw $failure['exception'];
        }

        $isStoredServerError = $failure !== null && $failure['exception'] instanceof UnknownApiErrorException;
        $result = $isStoredServerError ? $failure['exception'] : $this->storeNewSession($params);
        if ($idempotencyKey !== null) {
            $this->idempotencyRecords[$idempotencyKey] = ['params' => $params, 'result' => $result];
        }

        if ($failure !== null) {
            throw $failure['exception'];
        }

        return $result;
    }

    public function retrieve(string $sessionId): Session
    {
        return $this->sessions[$sessionId]
            ?? throw InvalidRequestException::factory('Fake: no such checkout session.', 404);
    }

    public function expire(string $sessionId): Session
    {
        $session = $this->retrieve($sessionId);
        if ($session->status !== 'open') {
            throw InvalidRequestException::factory('Fake: only open sessions can be expired.', 400);
        }

        return $this->replaceSession($sessionId, ['status' => 'expired', 'url' => null]);
    }

    /**
     * @param  array{created?: array{gte?: int, lte?: int}, status?: string}  $params
     * @return object{data: array<int, Session>, has_more: bool}
     */
    public function all(array $params = []): object
    {
        if ($this->nextListFailure !== null) {
            $failure = $this->nextListFailure;
            $this->nextListFailure = null;

            throw $failure;
        }

        $matching = array_filter($this->sessions, function (Session $session) use ($params): bool {
            $created = $params['created'] ?? [];

            return (! isset($params['status']) || $session->status === $params['status'])
                && (! isset($created['gte']) || $session->created >= $created['gte'])
                && (! isset($created['lte']) || $session->created <= $created['lte']);
        });

        return (object) ['data' => array_values($matching), 'has_more' => false];
    }

    /**
     * Mark a session as paid, as Stripe does when the customer completes the hosted page.
     */
    public function markPaid(string $sessionId, string $paymentIntentId): Session
    {
        return $this->replaceSession($sessionId, [
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => $paymentIntentId,
            'url' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function replaceForTest(string $sessionId, array $attributes): Session
    {
        return $this->replaceSession($sessionId, $attributes);
    }

    public function createCallCount(): int
    {
        return count($this->createCalls);
    }

    public function sessionCount(): int
    {
        return count($this->sessions);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function storeNewSession(array $params): Session
    {
        if (! isset($params['expires_at'])) {
            throw new RuntimeException('The fake requires expires_at, which the application always sends.');
        }

        $sessionId = 'cs_test_fake_'.$this->nextSessionNumber++;
        $total = array_sum(array_map(
            fn (array $item): int => $item['price_data']['unit_amount'] * $item['quantity'],
            $params['line_items'] ?? [],
        ));

        $session = Session::constructFrom([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'payment_method_types' => $this->paymentMethods,
            'url' => 'https://checkout.stripe.test/pay/'.$sessionId,
            'created' => now()->timestamp,
            'expires_at' => $params['expires_at'],
            'amount_total' => $total,
            'currency' => $params['line_items'][0]['price_data']['currency'] ?? 'jpy',
            'client_reference_id' => $params['client_reference_id'] ?? null,
            'metadata' => $params['metadata'] ?? [],
        ]);
        $this->sessions[$sessionId] = $session;

        return $session;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function replaceSession(string $sessionId, array $attributes): Session
    {
        $session = Session::constructFrom([
            ...$this->retrieve($sessionId)->toArray(),
            ...$attributes,
        ]);
        $this->sessions[$sessionId] = $session;

        return $session;
    }
}
