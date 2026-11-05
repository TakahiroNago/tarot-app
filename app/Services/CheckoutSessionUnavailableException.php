<?php

namespace App\Services;

use RuntimeException;

class CheckoutSessionUnavailableException extends RuntimeException
{
    /**
     * @param  bool  $orderStillOpen  Whether resubmitting the same checkout key can still succeed.
     */
    public function __construct(public bool $orderStillOpen)
    {
        parent::__construct('A Stripe Checkout session is not available for this order.');
    }
}
