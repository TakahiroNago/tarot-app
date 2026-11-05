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
        <main class="page-content admin-content">
            <section class="admin-panel">
                <p class="eyebrow">ORDER DETAILS</p>
                <h1>{{ $order->order_number }}</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                @if ($errors->any())
                    <div class="admin-errors" role="alert">
                        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif

                <dl class="user-details">
                    <div><dt>注文日時</dt><dd>{{ $order->created_at->format('Y-m-d H:i') }}</dd></div>
                    <div><dt>注文状態</dt><dd>{{ $order->status }}</dd></div>
                    <div><dt>決済状態</dt><dd>{{ $order->payment_status }}</dd></div>
                    <div><dt>購入者</dt><dd>{{ $order->customer_name }} / {{ $order->customer_email }} @if ($order->customer_phone) / {{ $order->customer_phone }} @endif</dd></div>
                    <div><dt>配送先</dt><dd>{{ $order->shipping_recipient }}<br>{{ $order->shipping_country_code }} {{ $order->shipping_postal_code }} {{ $order->shipping_region }} {{ $order->shipping_city }} {{ $order->shipping_address_line1 }} {{ $order->shipping_address_line2 }}</dd></div>
                    <div><dt>配送方法</dt><dd>{{ $order->shipping_method_name }}</dd></div>
                </dl>

                <h2>注文明細</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>商品スナップショット</th><th>SKU</th><th>単価</th><th>数量</th><th>税額</th><th>明細合計</th></tr></thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr>
                                    <td>{{ $item->product_name_snapshot }} / {{ $item->variant_label_snapshot }}</td>
                                    <td>{{ $item->sku_snapshot }}</td>
                                    <td>¥{{ number_format($item->unit_price_minor) }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td>¥{{ number_format($item->tax_minor) }}</td>
                                    <td>¥{{ number_format($item->line_total_minor) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="user-details">
                    <div><dt>商品小計（税込）</dt><dd>¥{{ number_format($order->subtotal_minor) }}</dd></div>
                    <div><dt>税額内訳</dt><dd>¥{{ number_format($order->tax_minor) }}</dd></div>
                    <div><dt>送料</dt><dd>¥{{ number_format($order->shipping_minor) }}</dd></div>
                    <div><dt>注文合計</dt><dd><strong>¥{{ number_format($order->total_minor) }}</strong></dd></div>
                </dl>

                <h2>決済参照</h2>
                @foreach ($order->payments as $payment)
                    <dl class="user-details">
                        <div><dt>決済状態</dt><dd>{{ $payment->status }} / {{ $payment->provider_status }}</dd></div>
                        <div><dt>Checkout Session</dt><dd>{{ $payment->provider_checkout_id ?? '—' }}</dd></div>
                        <div><dt>Payment Intent</dt><dd>{{ $payment->provider_payment_id ?? '—' }}</dd></div>
                        <div><dt>決済金額</dt><dd>¥{{ number_format($payment->amount_minor) }}</dd></div>
                    </dl>
                @endforeach

                @if (in_array($order->payment_status, ['paid', 'refund_pending'], true) && $order->payments->contains(fn ($payment) => is_string($payment->provider_payment_id)))
                    <form class="admin-refund-form" method="POST" action="{{ route('admin.orders.refund', $order) }}">
                        @csrf
                        <p class="settings-note">全額返金のみ。返金後も在庫は自動で戻りません。返品受領・検品後に下の在庫復元操作を行ってください。</p>
                        <button class="danger-action" type="submit">Stripeで全額返金</button>
                    </form>
                @endif

                <h2>発送・追跡</h2>
                @foreach ($order->shipments as $shipment)
                    <p class="settings-note">発送 {{ $shipment->status }} / {{ $shipment->carrier_name }} / {{ $shipment->tracking_number }}</p>
                @endforeach
                @if ($order->payment_status === 'paid')
                    @php($shipment = $order->shipments->last())
                    <form class="question-form admin-shipment-form" method="POST" action="{{ route('admin.orders.shipment.update', $order) }}">
                        @csrf
                        @method('PATCH')
                        <label class="question-label" for="carrier-name">配送会社</label>
                        <input class="question-input" id="carrier-name" name="carrier_name" type="text" maxlength="120" value="{{ old('carrier_name', $shipment?->carrier_name) }}">
                        <label class="question-label" for="tracking-number">追跡番号</label>
                        <input class="question-input" id="tracking-number" name="tracking_number" type="text" maxlength="120" value="{{ old('tracking_number', $shipment?->tracking_number) }}">
                        <label class="question-label" for="shipment-status">発送状態</label>
                        <select class="question-input" id="shipment-status" name="status" required>
                            @foreach (['pending', 'shipped', 'delivered'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $shipment?->status ?? 'pending') === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                        <button class="secondary-action" type="submit">発送情報を更新</button>
                    </form>
                @endif

                @if ($order->payment_status === 'refunded')
                    <h2>返品受領・検品後の在庫復元</h2>
                    @foreach ($order->items as $item)
                        @if ($item->variant_id !== null)
                            @php($restocked = (int) ($restockedQuantities[$item->variant_id] ?? 0))
                            @php($remaining = max(0, $item->quantity - $restocked))
                            <div class="return-restock-row">
                                <p>{{ $item->product_name_snapshot }} / {{ $item->variant_label_snapshot }} — 注文数量 {{ $item->quantity }}、復元済 {{ $restocked }}</p>
                                @if ($remaining > 0)
                                    <form class="return-restock-form" method="POST" action="{{ route('admin.orders.items.restock', [$order, $item]) }}">
                                        @csrf
                                        <input type="hidden" name="restock_key" value="{{ $restockKeys[$item->id] ?? '' }}">
                                        <label class="question-label" for="restock-quantity-{{ $item->id }}">検品済み返品数量</label>
                                        <input class="question-input" id="restock-quantity-{{ $item->id }}" name="quantity" type="number" min="1" max="{{ $remaining }}" value="{{ $remaining }}" required>
                                        <label class="question-label" for="restock-reason-{{ $item->id }}">検品記録</label>
                                        <input class="question-input" id="restock-reason-{{ $item->id }}" name="reason" type="text" maxlength="500" value="返品受領・検品済み" required>
                                        <button class="secondary-action" type="submit">検品済み在庫を復元</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    @endforeach
                @endif

                <a class="text-link" href="{{ route('admin.orders.index') }}">注文一覧に戻る</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
