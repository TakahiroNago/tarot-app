<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>料金・プラン | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel plans-panel">
                <p class="eyebrow">MEMBERSHIP</p>
                <h1>料金・プラン</h1>
                <p class="page-description">Stripeの安全な決済画面から、クレジット購入や有料プランへの登録ができます。決済情報はStripeが管理します。</p>

                @if ($errors->has('billing'))
                    <p class="form-notice credit-notice" role="alert">{{ $errors->first('billing') }}</p>
                @endif
                @if (request('checkout') === 'success')
                    <p class="form-notice credit-notice" role="status">決済を受け付けました。反映まで少し時間がかかる場合があります。</p>
                @elseif (request('checkout') === 'cancelled')
                    <p class="form-notice credit-notice" role="status">決済は完了していません。必要であれば、いつでも再度お申し込みいただけます。</p>
                @endif

                @auth
                    @if (auth()->user()->stripe_subscription_id !== null)
                        <p class="form-notice credit-notice" role="status">
                            現在のプラン：{{ auth()->user()->plan === \App\Models\User::PLAN_PREMIUM ? 'プレミアム' : (auth()->user()->plan === \App\Models\User::PLAN_LITE ? 'ライト' : '無料') }}
                            @if (auth()->user()->stripe_cancel_at_period_end && auth()->user()->stripe_current_period_end)
                                （{{ auth()->user()->stripe_current_period_end->format('Y年n月j日') }}に終了予定）
                            @endif
                        </p>
                        <form method="POST" action="{{ route('billing.portal') }}">
                            @csrf
                            <button class="primary-action" type="submit">契約・お支払いを管理</button>
                        </form>
                    @elseif (auth()->user()->stripe_customer_id !== null)
                        <form method="POST" action="{{ route('billing.portal') }}">
                            @csrf
                            <button class="text-link" type="submit">お支払い情報を管理</button>
                        </form>
                    @endif
                @endauth

                <div class="plan-grid">
                    <section class="plan-card">
                        <h2>クレジット購入</h2>
                        <p class="plan-price">10回 <strong>100円</strong></p>
                        <p class="plan-price">50回 <strong>400円</strong></p>
                        <p class="plan-price">100回 <strong>500円</strong></p>
                        @foreach (['credits_10' => '10回分を購入', 'credits_50' => '50回分を購入', 'credits_100' => '100回分を購入'] as $product => $label)
                            @if (auth()->check() && config('services.stripe.secret') && config("services.stripe.prices.{$product}"))
                                <form method="POST" action="{{ route('billing.checkout') }}">
                                    @csrf
                                    <input type="hidden" name="product" value="{{ $product }}">
                                    <button class="text-link" type="submit">{{ $label }}</button>
                                </form>
                            @elseif (auth()->guest())
                                <p><a class="text-link" href="{{ route('login') }}">ログインして{{ $label }}</a></p>
                            @else
                                <p>決済準備中</p>
                            @endif
                        @endforeach
                    </section>
                    <section class="plan-card">
                        <h2>ライトプラン</h2>
                        <p class="plan-price"><strong>月額280円</strong></p>
                        <p>・1日5回（最大150回まで引継ぎ）</p>
                        <p>・過去3か月のリーディング履歴</p>
                        @if (auth()->check() && auth()->user()->stripe_subscription_id === null && auth()->user()->plan === \App\Models\User::PLAN_FREE && config('services.stripe.secret') && config('services.stripe.prices.lite'))
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="product" value="lite">
                                <button class="primary-action" type="submit">ライトに申し込む</button>
                            </form>
                        @elseif (auth()->guest())
                            <p><a class="text-link" href="{{ route('login') }}">ログインしてライトに申し込む</a></p>
                        @elseif (! auth()->check() || ! config('services.stripe.secret') || ! config('services.stripe.prices.lite'))
                            <p>決済準備中</p>
                        @endif
                    </section>
                    <section class="plan-card">
                        <h2>プレミアムプラン</h2>
                        <p class="plan-price"><strong>月額980円</strong></p>
                        <p>・1日10回（最大300回まで引継ぎ）</p>
                        <p>・すべてのリーディング履歴</p>
                        <p>・過去のリーディング結果をもとにした、さらに深いリーディング結果</p>
                        @if (auth()->check() && auth()->user()->stripe_subscription_id === null && auth()->user()->plan === \App\Models\User::PLAN_FREE && config('services.stripe.secret') && config('services.stripe.prices.premium'))
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="product" value="premium">
                                <button class="primary-action" type="submit">プレミアムに申し込む</button>
                            </form>
                        @elseif (auth()->guest())
                            <p><a class="text-link" href="{{ route('login') }}">ログインしてプレミアムに申し込む</a></p>
                        @elseif (! auth()->check() || ! config('services.stripe.secret') || ! config('services.stripe.prices.premium'))
                            <p>決済準備中</p>
                        @endif
                    </section>
                </div>

                @guest
                    <p class="form-notice credit-notice">
                        無料会員は毎日3回、直近3か月分の履歴を最大50件まで利用できます。
                        <a href="{{ route('register') }}">無料会員登録</a>後にログインすると購入できます。
                    </p>
                @endguest
                <p class="page-description">プランの解約・変更は契約管理画面から手続きできます。解約後も、お支払い済み期間の終了までは有料機能をご利用いただけます。</p>
                <a class="text-link" href="{{ route('tarot') }}">占いに戻る</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
