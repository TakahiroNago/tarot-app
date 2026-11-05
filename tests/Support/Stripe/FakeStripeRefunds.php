<?php

namespace Tests\Support\Stripe;

use RuntimeException;
use Stripe\Refund;
use Throwable;

/**
 * In-memory stand-in for the Stripe Refunds API. A refund must be scripted before it is requested.
 */
class FakeStripeRefunds
{
    /**
     * @var array<int, array{params: array<string, mixed>, idempotency_key: string|null}>
     */
    public array $createCalls = [];

    /**
     * @var array<int, Refund>
     */
    private array $listed = [];

    private ?Refund $nextRefund = null;

    private ?Throwable $nextFailure = null;

    public function willReturn(string $status, int $amount): self
    {
        $this->nextRefund = Refund::constructFrom([
            'id' => 're_test_fake',
            'object' => 'refund',
            'status' => $status,
            'amount' => $amount,
            'currency' => 'jpy',
        ]);

        return $this;
    }

    /**
     * @param  array<int, array{status: string, amount: int}>  $refunds
     */
    public function willList(array $refunds): self
    {
        $this->listed = array_map(fn (array $refund): Refund => Refund::constructFrom([
            'id' => 're_test_listed',
            'object' => 'refund',
            'currency' => 'jpy',
            ...$refund,
        ]), $refunds);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return object{data: array<int, Refund>, has_more: bool}
     */
    public function all(array $params = []): object
    {
        if ($this->nextFailure !== null) {
            throw $this->nextFailure;
        }

        return (object) ['data' => $this->listed, 'has_more' => false];
    }

    public function willFail(Throwable $exception): self
    {
        $this->nextFailure = $exception;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): Refund
    {
        $this->createCalls[] = [
            'params' => $params,
            'idempotency_key' => isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : null,
        ];

        if ($this->nextFailure !== null) {
            throw $this->nextFailure;
        }

        return $this->nextRefund ?? throw new RuntimeException('The fake refund was not scripted.');
    }
}
