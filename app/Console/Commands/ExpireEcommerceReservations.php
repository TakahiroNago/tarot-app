<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Services\CheckoutService;

#[Signature('ecommerce:expire-reservations')]
#[Description('Release inventory reserved for expired ecommerce checkouts')]
class ExpireEcommerceReservations extends Command
{
    public function handle(CheckoutService $checkoutService): int
    {
        Order::query()
            ->where('status', 'pending_payment')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($checkoutService): void {
                foreach ($orders as $order) {
                    $checkoutService->releaseReservation($order, '決済期限を過ぎたため在庫予約を解除しました。');
                }
            });

        return self::SUCCESS;
    }
}
