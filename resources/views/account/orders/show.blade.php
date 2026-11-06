<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>注文詳細 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">ORDER DETAILS</p>
                <h1>注文詳細</h1>
                <dl class="user-details">
                    <div><dt>注文番号</dt><dd>{{ $order->order_number }}</dd></div>
                    <div><dt>注文日</dt><dd>{{ \App\Support\DisplayTime::format($order->created_at, 'Y年m月d日 H:i') }}</dd></div>
                    <div><dt>注文状態</dt><dd>{{ \App\Support\OrderStatusLabel::order($order->status) }}</dd></div>
                    <div><dt>決済状態</dt><dd>{{ \App\Support\OrderStatusLabel::payment($order->payment_status) }}</dd></div>
                </dl>

                <h2>注文商品</h2>
                <div class="cart-list">
                    @foreach ($order->items as $item)
                        <article class="cart-item">
                            <div>
                                <h2>{{ $item->product_name_snapshot }}</h2>
                                <p>{{ $item->variant_label_snapshot }} / SKU: {{ $item->sku_snapshot }}</p>
                                <p>¥{{ number_format($item->unit_price_minor) }} × {{ $item->quantity }}</p>
                            </div>
                            <strong>¥{{ number_format($item->line_total_minor) }}</strong>
                        </article>
                    @endforeach
                </div>
                <dl class="user-details">
                    <div><dt>小計</dt><dd>¥{{ number_format($order->subtotal_minor) }}</dd></div>
                    <div><dt>税額</dt><dd>¥{{ number_format($order->tax_minor) }}</dd></div>
                    <div><dt>送料</dt><dd>¥{{ number_format($order->shipping_minor) }}</dd></div>
                    <div><dt>合計</dt><dd><strong>¥{{ number_format($order->total_minor) }}</strong></dd></div>
                </dl>

                <h2>配送先</h2>
                <dl class="user-details">
                    <div><dt>宛名</dt><dd>{{ $order->shipping_recipient }}</dd></div>
                    <div><dt>住所</dt><dd>{{ $order->shipping_country_code }} {{ $order->shipping_postal_code }} {{ $order->shipping_region }} {{ $order->shipping_city }} {{ $order->shipping_address_line1 }} {{ $order->shipping_address_line2 }}</dd></div>
                    <div><dt>配送方法</dt><dd>{{ $order->shipping_method_name }}</dd></div>
                    @foreach ($order->shipments as $shipment)
                        <div><dt>発送状況</dt><dd>{{ \App\Support\OrderStatusLabel::shipment($shipment->status) }} @if ($shipment->carrier_name) / {{ $shipment->carrier_name }} @endif @if ($shipment->tracking_number) / {{ $shipment->tracking_number }} @endif</dd></div>
                    @endforeach
                </dl>
                @if ($order->user_id !== null)
                    <a class="text-link" href="{{ route('account.orders.index') }}">購入履歴に戻る</a>
                @else
                    <a class="text-link" href="{{ route('shop.products.index') }}">ショップに戻る</a>
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
