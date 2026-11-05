<?php

namespace App\Services;

use RuntimeException;

/**
 * What the buyer confirmed no longer matches what the order would be, so nothing was created.
 */
class CheckoutQuoteChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The confirmed checkout details no longer match the current quote.');
    }
}
