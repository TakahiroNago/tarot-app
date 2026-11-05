<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function setOnHand(
        ProductVariant $variant,
        int $quantity,
        User $actor,
        string $reason,
    ): InventoryBalance {
        if ($quantity < 0 || trim($reason) === '') {
            throw ValidationException::withMessages([
                'stock' => '在庫数と変更理由を確認してください。',
            ]);
        }

        return DB::transaction(function () use ($variant, $quantity, $actor, $reason): InventoryBalance {
            $balance = InventoryBalance::query()
                ->lockForUpdate()
                ->findOrFail($variant->id);
            $delta = $quantity - $balance->on_hand;

            if ($quantity < $balance->reserved) {
                throw ValidationException::withMessages([
                    'stock' => '予約済み在庫を下回る数量には変更できません。',
                ]);
            }

            if ($delta === 0) {
                return $balance;
            }

            $balance->on_hand = $quantity;
            $balance->save();

            InventoryMovement::query()->create([
                'variant_id' => $variant->id,
                'actor_user_id' => $actor->id,
                'movement_type' => 'admin_adjustment',
                'on_hand_delta' => $delta,
                'reserved_delta' => 0,
                'idempotency_key' => bin2hex(random_bytes(32)),
                'note' => trim($reason),
            ]);

            return $balance->refresh();
        });
    }

    public function restockReturnedItem(
        OrderItem $item,
        int $quantity,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): InventoryBalance {
        if ($quantity < 1 || trim($reason) === '' || preg_match('/\A[a-f0-9]{64}\z/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => '返品数量、検品理由、または処理キーを確認してください。',
            ]);
        }

        return DB::transaction(function () use ($item, $quantity, $actor, $reason, $idempotencyKey): InventoryBalance {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            abort_unless($order->payment_status === 'refunded', 422);
            abort_unless($item->variant_id !== null, 422);

            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $existingMovement = InventoryMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingMovement instanceof InventoryMovement) {
                if (
                    $existingMovement->order_id !== $order->id
                    || $existingMovement->variant_id !== $item->variant_id
                    || $existingMovement->on_hand_delta !== $quantity
                ) {
                    throw ValidationException::withMessages([
                        'restock_key' => '同じ処理キーが別の在庫変更に使用されています。',
                    ]);
                }

                return InventoryBalance::query()->findOrFail($item->variant_id);
            }

            $alreadyRestocked = InventoryMovement::query()
                ->where('order_id', $order->id)
                ->where('variant_id', $item->variant_id)
                ->where('movement_type', 'return_restock')
                ->sum('on_hand_delta');
            if ($alreadyRestocked + $quantity > $item->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => '返品済みとして登録できる数量が注文数量を超えています。',
                ]);
            }

            $soldQuantity = -(int) InventoryMovement::query()
                ->where('order_id', $order->id)
                ->where('variant_id', $item->variant_id)
                ->where('movement_type', 'sale')
                ->sum('on_hand_delta');
            if ($alreadyRestocked + $quantity > $soldQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => '販売確定の記録を超える数量は在庫に戻せません。',
                ]);
            }

            $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($item->variant_id);
            $balance->on_hand += $quantity;
            $balance->save();
            InventoryMovement::query()->create([
                'variant_id' => $item->variant_id,
                'order_id' => $order->id,
                'actor_user_id' => $actor->id,
                'movement_type' => 'return_restock',
                'on_hand_delta' => $quantity,
                'reserved_delta' => 0,
                'idempotency_key' => $idempotencyKey,
                'note' => trim($reason),
            ]);

            return $balance->refresh();
        });
    }
}
