<?php

namespace App\Console\Commands;

use App\Services\PaymentReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ecommerce:expire-reservations')]
#[Description('Reconcile orders with Stripe and release inventory for expired checkouts')]
class ExpireEcommerceReservations extends Command
{
    public function handle(PaymentReconciliationService $reconciliationService): int
    {
        $reconciliationService->run();

        return self::SUCCESS;
    }
}
