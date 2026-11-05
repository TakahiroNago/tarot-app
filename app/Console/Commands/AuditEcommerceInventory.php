<?php

namespace App\Console\Commands;

use App\Mail\AdminInventoryAuditMail;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Reports differences only. Stock is never corrected here, because the cause must be understood
 * first and any correction must be a recorded admin adjustment.
 */
#[Signature('ecommerce:audit-inventory')]
#[Description('Compare inventory balances with the ledger and alert the contact address on differences')]
class AuditEcommerceInventory extends Command
{
    public function handle(): int
    {
        $differences = [];

        InventoryBalance::query()->chunkById(200, function ($balances) use (&$differences): void {
            foreach ($balances as $balance) {
                $ledger = InventoryMovement::query()
                    ->where('variant_id', $balance->variant_id)
                    ->selectRaw('COALESCE(SUM(on_hand_delta), 0) AS on_hand, COALESCE(SUM(reserved_delta), 0) AS reserved')
                    ->first();

                if (
                    (int) $ledger->on_hand !== $balance->on_hand
                    || (int) $ledger->reserved !== $balance->reserved
                    || $balance->reserved > $balance->on_hand
                ) {
                    $differences[] = [
                        'variant_id' => $balance->variant_id,
                        'on_hand' => $balance->on_hand,
                        'reserved' => $balance->reserved,
                        'ledger_on_hand' => (int) $ledger->on_hand,
                        'ledger_reserved' => (int) $ledger->reserved,
                    ];
                }
            }
        }, 'variant_id');

        if ($differences === []) {
            $this->info('Inventory balances match the ledger.');

            return self::SUCCESS;
        }

        $this->error(count($differences).' inventory balances differ from the ledger.');

        $recipient = config('services.contact.email');
        if (is_string($recipient) && $recipient !== '') {
            Mail::to($recipient)->send(new AdminInventoryAuditMail($differences));
        }

        return self::FAILURE;
    }
}
