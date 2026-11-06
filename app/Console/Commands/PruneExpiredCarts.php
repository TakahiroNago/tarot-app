<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('ecommerce:prune-carts')]
#[Description('Delete expired guest carts that no order is waiting on')]
class PruneExpiredCarts extends Command
{
    public function handle(): int
    {
        $deleted = 0;

        Cart::query()
            ->whereNull('user_id')
            ->where('expires_at', '<=', now())
            ->whereDoesntHave('activeOrder')
            ->chunkById(500, function (Collection $carts) use (&$deleted): void {
                $deleted += Cart::query()->whereKey($carts->modelKeys())->delete();
            });

        $this->info("Deleted {$deleted} expired carts.");

        return self::SUCCESS;
    }
}
