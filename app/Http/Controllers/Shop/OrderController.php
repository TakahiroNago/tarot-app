<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $orders = $user->orders()
            ->with('items')
            ->latest()
            ->paginate(20);

        return view('account.orders.index', compact('orders'));
    }

    public function show(Request $request, Order $order): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $order = $user->orders()
            ->with(['items', 'shipments'])
            ->findOrFail($order->id);

        return view('account.orders.show', ['order' => $order, 'showsFullAddress' => true]);
    }

    public function guestShow(Request $request, string $orderNumber): View
    {
        $signature = $request->query('signature');
        abort_unless(is_string($signature), 404);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->whereNull('user_id')
            ->firstOrFail();
        abort_unless(
            $order->guest_access_expires_at?->isFuture()
                && is_string($order->guest_access_token_hash)
                && hash_equals($order->guest_access_token_hash, hash('sha256', $signature)),
            404,
        );

        $order->load(['items', 'shipments']);

        // The link travels by mail and can be forwarded, so it shows no name or exact address.
        return view('account.orders.show', ['order' => $order, 'showsFullAddress' => false]);
    }
}
