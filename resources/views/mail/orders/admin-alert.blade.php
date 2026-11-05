<p>注文の確認が必要です。</p>
<p>注文番号: {{ $order->order_number }}</p>
<p>確認の種類: {{ $alertType }}</p>
@if ($order->review_reason)
    <p>理由: {{ $order->review_reason }}</p>
@endif
<p><a href="{{ route('admin.orders.show', $order) }}">管理画面で注文を確認</a></p>
