<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>購入履歴 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">ORDER HISTORY</p>
                <h1>購入履歴</h1>
                @if ($orders->isEmpty())
                    <p class="history-empty">購入履歴はありません。</p>
                    @if (config('ecommerce.enabled'))
                        <a class="primary-action" href="{{ route('shop.products.index') }}">商品を見る <span class="action-arrow" aria-hidden="true">→</span></a>
                    @endif
                @else
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>注文番号</th>
                                    <th>注文日</th>
                                    <th>注文状態</th>
                                    <th>合計</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    <tr>
                                        <td>{{ $order->order_number }}</td>
                                        <td>{{ \App\Support\DisplayTime::format($order->created_at, 'Y年m月d日') }}</td>
                                        <td>{{ \App\Support\OrderStatusLabel::order($order->status) }}</td>
                                        <td>¥{{ number_format($order->total_minor) }}</td>
                                        <td><a class="secondary-action" href="{{ route('account.orders.show', $order) }}">詳細</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $orders->links() }}
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
