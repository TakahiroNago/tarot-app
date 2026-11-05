<?php

namespace Tests\Support\Concurrency;

use App\Models\Payment;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Makes the last write of the order transaction fail with a deadlock a given number of times, so
 * every earlier write in that attempt must be rolled back by the database.
 */
final class InjectedDeadlock
{
    public static function onPaymentCreation(int $times): void
    {
        $remaining = $times;

        Payment::creating(function () use (&$remaining): void {
            if ($remaining > 0) {
                $remaining--;

                throw self::exception();
            }
        });
    }

    public static function exception(): QueryException
    {
        $driverMessage = 'Deadlock found when trying to get lock; try restarting transaction';
        $previous = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 '.$driverMessage);
        $previous->errorInfo = ['40001', 1213, $driverMessage];

        return new QueryException('injected', 'insert into `ec_payments`', [], $previous);
    }
}
