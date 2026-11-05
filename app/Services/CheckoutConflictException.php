<?php

namespace App\Services;

use RuntimeException;

/**
 * The submitted details differ from an order still awaiting payment, so the buyer must choose.
 */
class CheckoutConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The checkout details differ from the order awaiting payment.');
    }
}
