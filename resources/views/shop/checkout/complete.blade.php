<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ご注文状況 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">ORDER STATUS</p>
                <h1>ご注文状況</h1>
                @if ($order->payment_status === 'paid')
                    <p class="form-notice" role="status">お支払いを確認しました。ご注文ありがとうございます。</p>
                @elseif ($order->payment_status === 'payment_review')
                    <p class="form-notice" role="status">お支払いを確認しましたが、ご注文内容の確認が必要なため、発送手続きを保留しています。担当者より改めてご連絡します。</p>
                @else
                    <p class="form-notice" role="status">決済結果を確認しています。この画面はWebhookによる確定前の状態を表示しています。反映まで少しお待ちください。</p>
                @endif
                <dl class="user-details">
                    <div><dt>注文番号</dt><dd>{{ $order->order_number }}</dd></div>
                    <div><dt>注文状態</dt><dd>{{ $order->status }}</dd></div>
                    <div><dt>決済状態</dt><dd>{{ $order->payment_status }}</dd></div>
                    <div><dt>合計</dt><dd>¥{{ number_format($order->total_minor) }}</dd></div>
                </dl>
                @if (is_string($guestOrderUrl))
                    <p class="settings-note">ゲスト注文の詳細は、下記の期限付き専用リンクから確認できます。リンクは他の方と共有しないでください。</p>
                    <a class="secondary-action" href="{{ $guestOrderUrl }}">注文詳細を確認</a>
                @endif
                <a class="primary-action" href="{{ route('shop.products.index') }}">ショップに戻る <span class="action-arrow" aria-hidden="true">→</span></a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
