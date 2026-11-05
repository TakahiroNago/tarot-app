<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>ご注文内容の確認 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">CONFIRM YOUR ORDER</p>
                <h1>ご注文内容の確認</h1>

                @if ($changedNotice)
                    <p class="form-notice" role="alert">{{ $changedNotice }}</p>
                    @if ($previousTotal !== null && $previousTotal !== $quote['total'])
                        <p class="form-notice" role="status">合計金額：¥{{ number_format($previousTotal) }} → ¥{{ number_format($quote['total']) }}</p>
                    @endif
                @endif

                <div class="checkout-summary">
                    <h2>ご注文商品</h2>
                    @foreach ($quote['lines'] as $line)
                        <p>{{ $line['name'] }} / {{ $line['label'] }} — ¥{{ number_format($line['unit_price']) }} × {{ $line['quantity'] }} = ¥{{ number_format($line['line_total']) }}</p>
                    @endforeach
                </div>

                <dl class="user-details">
                    <div><dt>商品小計（税込）</dt><dd>¥{{ number_format($quote['subtotal']) }}</dd></div>
                    <div><dt>送料（{{ $quote['shipping_method'] }}）</dt><dd>¥{{ number_format($quote['shipping']) }}</dd></div>
                    <div><dt>うち税額</dt><dd>¥{{ number_format($quote['tax']) }}</dd></div>
                    <div><dt>お支払い合計</dt><dd><strong>¥{{ number_format($quote['total']) }}</strong></dd></div>
                </dl>

                <h2>お支払い</h2>
                <dl class="user-details">
                    <div><dt>お支払い方法</dt><dd>クレジットカード（Stripeの決済画面で入力します）</dd></div>
                    <div><dt>お支払い時期</dt><dd>次の画面でカード情報を入力し、決済が完了した時点</dd></div>
                </dl>

                <h2>ご連絡先</h2>
                <dl class="user-details">
                    <div><dt>お名前</dt><dd>{{ $details['customer_name'] }}</dd></div>
                    <div><dt>メールアドレス</dt><dd>{{ $details['customer_email'] }}</dd></div>
                    @if (! empty($details['customer_phone']))
                        <div><dt>電話番号</dt><dd>{{ $details['customer_phone'] }}</dd></div>
                    @endif
                </dl>

                <h2>配送先</h2>
                <dl class="user-details">
                    <div><dt>お届け先のお名前</dt><dd>{{ $details['shipping_recipient'] }}</dd></div>
                    <div><dt>住所</dt><dd>{{ $details['shipping_country_code'] }} {{ $details['shipping_postal_code'] }} {{ $details['shipping_region'] ?? '' }} {{ $details['shipping_city'] }} {{ $details['shipping_address_line1'] }} {{ $details['shipping_address_line2'] ?? '' }}</dd></div>
                </dl>

                <p class="settings-note">発送の時期、返品・キャンセルの条件は<a class="text-link" href="{{ route('legal.commercial-transactions') }}" target="_blank" rel="noopener">特定商取引法に基づく表記</a>をご確認ください。</p>

                <form method="POST" action="{{ route('shop.checkout.store') }}" data-single-submit>
                    @csrf
                    @foreach ($details as $field => $value)
                        @if ($value !== null)
                            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <input type="hidden" name="quote_hash" value="{{ $quote['hash'] }}">
                    <input type="hidden" name="quoted_total" value="{{ $quote['total'] }}">

                    <label class="question-label">
                        <input type="checkbox" name="terms_accepted" value="1" required>
                        <a class="text-link" href="{{ route('legal.terms') }}" target="_blank" rel="noopener">利用規約</a>、<a class="text-link" href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">プライバシーポリシー</a>、および上記の内容に同意します。
                    </label>
                    @if ($termsError)
                        <p class="form-error" role="alert">{{ $termsError }}</p>
                    @endif

                    <button class="primary-action" type="submit">注文を確定して決済へ <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>

                <form method="POST" action="{{ route('shop.checkout.edit') }}">
                    @csrf
                    @foreach ($details as $field => $value)
                        @if ($value !== null)
                            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button class="secondary-action" type="submit">入力内容を修正する</button>
                </form>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
