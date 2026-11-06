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
                @switch ($order->payment_status)
                    @case ('paid')
                        <p class="form-notice" role="status">お支払いを確認しました。ご注文ありがとうございます。</p>
                        @break
                    @case ('payment_review')
                        <p class="form-notice" role="status">お支払いを確認しましたが、ご注文内容の確認が必要なため、発送手続きを保留しています。担当者より改めてご連絡します。</p>
                        @break
                    @case ('refund_pending')
                        <p class="form-notice" role="status">このご注文は返金の手続き中です。完了しましたらメールでお知らせします。</p>
                        @break
                    @case ('refund_review')
                        <p class="form-notice" role="status">このご注文の返金内容を確認しています。担当者より改めてご連絡します。</p>
                        @break
                    @case ('refunded')
                        <p class="form-notice" role="status">このご注文は返金済みです。</p>
                        @break
                    @case ('failed')
                    @case ('cancelled')
                        <p class="form-notice" role="status">このご注文はお支払いが完了しなかったため、キャンセルされました。</p>
                        @break
                    @default
                        <p class="form-notice" role="status">決済結果を確認しています。この画面はWebhookによる確定前の状態を表示しています。反映まで少しお待ちください。</p>
                @endswitch
                <dl class="user-details">
                    <div><dt>注文番号</dt><dd>{{ $order->order_number }}</dd></div>
                    <div><dt>注文状態</dt><dd>{{ \App\Support\OrderStatusLabel::order($order->status) }}</dd></div>
                    <div><dt>決済状態</dt><dd>{{ \App\Support\OrderStatusLabel::payment($order->payment_status) }}</dd></div>
                    <div><dt>合計</dt><dd>¥{{ number_format($order->total_minor) }}</dd></div>
                </dl>
                @if (is_string($guestOrderUrl))
                    <p class="settings-note">ゲスト注文の詳細は、下記の期限付き専用リンクから確認できます。リンクは他の方と共有しないでください。</p>
                    <a class="secondary-action" href="{{ $guestOrderUrl }}">注文詳細を確認</a>
                @endif
                @if (config('ecommerce.enabled'))
                    <a class="primary-action" href="{{ route('shop.products.index') }}">ショップに戻る <span class="action-arrow" aria-hidden="true">→</span></a>
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
