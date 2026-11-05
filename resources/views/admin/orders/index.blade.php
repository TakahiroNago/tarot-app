<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>注文管理 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel">
                <p class="eyebrow">ORDERS</p>
                <h1>注文管理</h1>
                <form class="admin-order-filters" method="GET" action="{{ route('admin.orders.index') }}">
                    <label class="sr-only" for="order-search">注文番号</label>
                    <input class="question-input" id="order-search" name="search" type="search" value="{{ $search }}" placeholder="注文番号">
                    <select class="question-input" name="status" aria-label="注文状態">
                        <option value="">すべての注文状態</option>
                        @foreach (['pending_payment', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'payment_review', 'refund_pending', 'refunded'] as $status)
                            <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <select class="question-input" name="payment_status" aria-label="決済状態">
                        <option value="">すべての決済状態</option>
                        @foreach (['pending', 'paid', 'failed', 'cancelled', 'refund_pending', 'refunded'] as $status)
                            <option value="{{ $status }}" @selected($selectedPaymentStatus === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="secondary-action" type="submit">絞り込み</button>
                </form>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>注文番号</th><th>注文日</th><th>購入者</th><th>注文状態</th><th>決済状態</th><th>商品数</th><th>合計</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($orders as $order)
                                <tr>
                                    <td>{{ $order->order_number }}</td>
                                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                                    <td>{{ $order->user_id === null ? 'ゲスト' : '会員' }}</td>
                                    <td>{{ $order->status }}</td>
                                    <td>{{ $order->payment_status }}</td>
                                    <td>{{ $order->items_count }}</td>
                                    <td>¥{{ number_format($order->total_minor) }}</td>
                                    <td><a class="table-link" href="{{ route('admin.orders.show', $order) }}">詳細</a></td>
                                </tr>
                            @empty
                                <tr><td class="empty-state" colspan="8">注文はありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $orders->links() }}
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
