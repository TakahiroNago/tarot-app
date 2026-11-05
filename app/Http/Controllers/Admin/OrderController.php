<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\RefundService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                'pending_payment', 'paid', 'processing', 'shipped', 'completed',
                'cancelled', 'payment_review', 'refund_pending', 'refunded',
            ])],
            'payment_status' => ['nullable', Rule::in([
                'pending', 'paid', 'failed', 'cancelled', 'refund_pending', 'refund_review', 'payment_review', 'refunded',
            ])],
        ]);
        $search = trim($validated['search'] ?? '');
        $orders = Order::query()
            ->withCount('items')
            ->when($search !== '', fn ($query) => $query->where('order_number', 'like', '%'.$search.'%'))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['payment_status']), fn ($query) => $query->where('payment_status', $validated['payment_status']))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'search' => $search,
            'selectedStatus' => $validated['status'] ?? null,
            'selectedPaymentStatus' => $validated['payment_status'] ?? null,
        ]);
    }

    public function show(Order $order): View
    {
        $order->load(['items', 'payments', 'shipments', 'inventoryMovements']);
        $restockedQuantities = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', 'return_restock')
            ->select('variant_id', DB::raw('SUM(on_hand_delta) as quantity'))
            ->groupBy('variant_id')
            ->pluck('quantity', 'variant_id');

        return view('admin.orders.show', [
            'order' => $order,
            'restockedQuantities' => $restockedQuantities,
            'restockKeys' => $order->items
                ->mapWithKeys(fn (OrderItem $item): array => [$item->id => bin2hex(random_bytes(32))])
                ->all(),
        ]);
    }

    public function updateShipment(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['pending', 'shipped', 'delivered'])],
        ]);
        abort_unless($order->payment_status === 'paid', 422);

        DB::transaction(function () use ($order, $validated): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($lockedOrder->payment_status === 'paid', 422);
            $shipment = $lockedOrder->shipments()->latest()->lockForUpdate()->first();
            if ($shipment === null) {
                $shipment = $lockedOrder->shipments()->create(['status' => 'pending']);
            }
            $currentStatus = $shipment->status;
            $canTransition = match ($currentStatus) {
                'pending' => true,
                'shipped' => in_array($validated['status'], ['shipped', 'delivered'], true),
                'delivered' => $validated['status'] === 'delivered',
                default => false,
            };
            abort_unless($canTransition, 422);

            $shipment->fill([
                'carrier_name' => $validated['carrier_name'] ?? null,
                'tracking_number' => $validated['tracking_number'] ?? null,
                'status' => $validated['status'],
                'shipped_at' => in_array($validated['status'], ['shipped', 'delivered'], true)
                    ? ($shipment->shipped_at ?? now())
                    : null,
                'delivered_at' => $validated['status'] === 'delivered'
                    ? ($shipment->delivered_at ?? now())
                    : null,
            ])->save();

            $lockedOrder->status = match ($validated['status']) {
                'pending' => 'processing',
                'shipped' => 'shipped',
                'delivered' => 'completed',
            };
            $lockedOrder->save();
        });

        return to_route('admin.orders.show', $order)->with('status', '発送状況を更新しました。');
    }

    public function refund(Order $order, RefundService $refundService): RedirectResponse
    {
        $status = $refundService->refundFull($order);
        $message = match ($status) {
            'succeeded' => 'Stripeで全額返金しました。在庫は返品受領・検品後に別途戻してください。',
            'pending', 'requires_action' => '返金処理を開始しました。確定Webhookを待っています。在庫は自動で戻していません。',
            default => '返金は完了していません。Stripeの状態を確認してください。',
        };

        return to_route('admin.orders.show', $order)->with('status', $message);
    }

    public function restock(
        Request $request,
        Order $order,
        OrderItem $item,
        InventoryService $inventoryService,
    ): RedirectResponse {
        abort_unless($item->order_id === $order->id, 404);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:65535'],
            'reason' => ['required', 'string', 'max:500'],
            'restock_key' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $inventoryService->restockReturnedItem(
            $item,
            (int) $validated['quantity'],
            $actor,
            $validated['reason'],
            hash('sha256', $validated['restock_key']),
        );

        return to_route('admin.orders.show', $order)->with('status', '検品済み返品の在庫を台帳に記録しました。');
    }
}
