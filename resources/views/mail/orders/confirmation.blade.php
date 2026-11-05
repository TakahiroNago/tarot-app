<p>{{ $order->customer_name }} 様</p>
<p>ご注文ありがとうございます。お支払いを確認し、注文を受け付けました。</p>
<p>注文番号: {{ $order->order_number }}</p>
<ul>
    @foreach ($order->items as $item)
        <li>{{ $item->product_name_snapshot }} / {{ $item->variant_label_snapshot }} × {{ $item->quantity }} — ¥{{ number_format($item->line_total_minor) }}</li>
    @endforeach
</ul>
<p>商品小計（税込）: ¥{{ number_format($order->subtotal_minor) }}</p>
<p>税額内訳: ¥{{ number_format($order->tax_minor) }}</p>
<p>送料: ¥{{ number_format($order->shipping_minor) }}</p>
<p>合計: ¥{{ number_format($order->total_minor) }}</p>
@if (is_string($guestOrderUrl))
    <p>ゲスト注文の詳細は、下記の期限付き専用リンクから確認できます。リンクは他の方と共有しないでください。</p>
    <p><a href="{{ $guestOrderUrl }}">注文詳細を確認</a></p>
@else
    <p>注文状況はアカウントの購入履歴から確認できます。</p>
@endif
<p>本メールに心当たりがない場合は、本メールへ返信せずサイトのお問い合わせ窓口からご連絡ください。</p>
