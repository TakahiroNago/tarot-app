<p>{{ $order->customer_name }} 様</p>
<p>ご注文の返金手続きが完了しました。</p>
<p>注文番号: {{ $order->order_number }}</p>
<p>返金額: ¥{{ number_format($refundedAmount) }}</p>
<p>カード明細への反映には、カード会社により日数がかかる場合があります。</p>
@if (is_string($guestOrderUrl))
    <p>ゲスト注文の詳細は、下記の期限付き専用リンクから確認できます。リンクは他の方と共有しないでください。</p>
    <p><a href="{{ $guestOrderUrl }}">注文詳細を確認</a></p>
@endif
<p>本メールに心当たりがない場合は、本メールへ返信せずサイトのお問い合わせ窓口からご連絡ください。</p>
