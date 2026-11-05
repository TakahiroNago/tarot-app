<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ご注文手続き | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">CHECKOUT</p>
                <h1>ご注文手続き</h1>
                @if (session('status'))
                    <p class="form-notice" role="status">{{ session('status') }}</p>
                @endif
                @if ($cart->items->isEmpty())
                    <p>カートに商品がありません。</p>
                    <a class="primary-action" href="{{ route('shop.products.index') }}">商品を見る <span class="action-arrow" aria-hidden="true">→</span></a>
                @elseif ($shippingRates->isEmpty())
                    <p class="form-notice" role="status">配送先・送料・税ルールが未設定のため、現在チェックアウトを利用できません。設定が整うまで注文の確定を停止しています。</p>
                    <a class="text-link" href="{{ route('shop.cart.index') }}">カートに戻る</a>
                @else
                    <div class="checkout-summary">
                        <h2>注文内容</h2>
                        @foreach ($cart->items as $item)
                            <p>{{ $item->variant->product?->name ?? '販売終了商品' }} / {{ $item->variant->label }} × {{ $item->quantity }} — ¥{{ number_format($item->variant->price_minor * $item->quantity) }}</p>
                        @endforeach
                        <p class="product-price">商品小計（税込）: ¥{{ number_format($subtotal) }}</p>
                        <p class="settings-note">送料と適用税額は配送先に基づいて確定します。カード情報はStripeの決済画面で入力し、本サイトでは保存しません。</p>
                    </div>
                    <form class="question-form checkout-form" method="POST" action="{{ route('shop.checkout.store') }}">
                        @csrf
                        <input type="hidden" name="checkout_key" value="{{ $checkoutKey }}">
                        <h2>ご連絡先</h2>
                        <label class="question-label" for="customer-name">お名前</label>
                        <input class="question-input" id="customer-name" name="customer_name" type="text" maxlength="255" value="{{ old('customer_name') }}" autocomplete="name" required>
                        @error('customer_name')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="customer-email">メールアドレス</label>
                        <input class="question-input" id="customer-email" name="customer_email" type="email" maxlength="255" value="{{ old('customer_email') }}" autocomplete="email" required>
                        @error('customer_email')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="customer-phone">電話番号（任意）</label>
                        <input class="question-input" id="customer-phone" name="customer_phone" type="tel" maxlength="40" value="{{ old('customer_phone') }}" autocomplete="tel">
                        @error('customer_phone')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <h2>配送先</h2>
                        <label class="question-label" for="shipping-recipient">お届け先のお名前</label>
                        <input class="question-input" id="shipping-recipient" name="shipping_recipient" type="text" maxlength="255" value="{{ old('shipping_recipient') }}" autocomplete="shipping name" required>
                        @error('shipping_recipient')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-country">配送先の国・地域</label>
                        <select class="question-input" id="shipping-country" name="shipping_country_code" required>
                            <option value="">選択してください</option>
                            @foreach ($shippingRates->pluck('country_code')->unique() as $countryCode)
                                <option value="{{ $countryCode }}" @selected(old('shipping_country_code') === $countryCode)>{{ $countryCode }}</option>
                            @endforeach
                        </select>
                        @error('shipping_country_code')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-postal-code">郵便番号</label>
                        <input class="question-input" id="shipping-postal-code" name="shipping_postal_code" type="text" maxlength="40" value="{{ old('shipping_postal_code') }}" autocomplete="shipping postal-code" required>
                        @error('shipping_postal_code')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-region">都道府県・州（任意）</label>
                        <input class="question-input" id="shipping-region" name="shipping_region" type="text" maxlength="255" value="{{ old('shipping_region') }}" autocomplete="shipping address-level1">
                        @error('shipping_region')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-city">市区町村</label>
                        <input class="question-input" id="shipping-city" name="shipping_city" type="text" maxlength="255" value="{{ old('shipping_city') }}" autocomplete="shipping address-level2" required>
                        @error('shipping_city')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-address-line1">住所</label>
                        <input class="question-input" id="shipping-address-line1" name="shipping_address_line1" type="text" maxlength="255" value="{{ old('shipping_address_line1') }}" autocomplete="shipping address-line1" required>
                        @error('shipping_address_line1')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-address-line2">建物名・部屋番号（任意）</label>
                        <input class="question-input" id="shipping-address-line2" name="shipping_address_line2" type="text" maxlength="255" value="{{ old('shipping_address_line2') }}" autocomplete="shipping address-line2">
                        @error('shipping_address_line2')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="shipping-rate">配送方法・送料</label>
                        <select class="question-input" id="shipping-rate" name="shipping_rate_id" required>
                            <option value="">選択してください</option>
                            @foreach ($shippingRates as $shippingRate)
                                <option value="{{ $shippingRate->id }}" @selected((string) old('shipping_rate_id') === (string) $shippingRate->id)>
                                    {{ $shippingRate->country_code }} — {{ $shippingRate->shippingMethod->name }} — ¥{{ number_format($shippingRate->fee_minor) }}
                                </option>
                            @endforeach
                        </select>
                        @error('shipping_rate_id')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        @error('cart')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        @error('checkout_key')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        <button class="primary-action" type="submit">Stripeでお支払いへ <span class="action-arrow" aria-hidden="true">→</span></button>
                    </form>
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
