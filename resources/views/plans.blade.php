<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>料金・プラン | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel plans-panel">
                <p class="eyebrow">MEMBERSHIP</p>
                <h1>料金・プラン</h1>

                @if ($errors->has('billing'))
                    <p class="form-notice credit-notice" role="alert">{{ $errors->first('billing') }}</p>
                @endif
                @if (request('checkout') === 'success')
                    <p class="form-notice credit-notice" role="status">決済を受け付けました。反映まで少し時間がかかる場合があります。</p>
                @elseif (request('checkout') === 'cancelled')
                    <p class="form-notice credit-notice" role="status">決済は完了していません。必要であれば、いつでも再度お申し込みいただけます。</p>
                @endif

                @auth
                    <p class="form-notice credit-notice" role="status">
                        現在のプラン：{{ auth()->user()->plan === \App\Models\User::PLAN_PREMIUM ? 'プレミアムプラン' : (auth()->user()->plan === \App\Models\User::PLAN_LITE ? 'ライトプラン' : '無料会員') }}
                        @if (auth()->user()->stripe_subscription_id !== null && auth()->user()->stripe_cancel_at_period_end && auth()->user()->stripe_current_period_end)
                            （{{ auth()->user()->stripe_current_period_end->format('Y年n月j日') }}に終了予定）
                        @endif
                    </p>
                @endauth

                <div class="plan-grid">
                    <section class="plan-card">
                        <h2>プレミアムプラン</h2>
                        <p>・占い回数無制限</p>
                        <p>・すべてのリーディング履歴</p>
                        <p>・「今日の1枚」</p>
                        <p>・過去のリーディング結果をもとにした、さらに深いリーディング</p>
                        <p class="plan-price"><strong>580円/<span class="plan-price-period">月</span></strong></p>
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
                        <p>※ 短時間でのボットなどによる過重アクセスは一時的に制限される場合があります。</p>
                    </section>
                    <section class="plan-card">
                        <h2>ライトプラン</h2>
                        <p>・1日10回（最大50回まで引継ぎ）</p>
                        <p>・過去3か月のリーディング履歴</p>
                        <p>・「今日の1枚」（利用回数減なし）</p>
                        <p class="plan-price"><strong>180円/<span class="plan-price-period">月</span></strong></p>
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
                        <h2>無料会員</h2>
                        <p>・1日3回（回数引継ぎなし）</p>
                        <p>・50件までのリーディング履歴</p>
                        <p>・「今日の1枚」（利用回数減なし）</p>
                    </section>
                </div>

                <p class="page-description">Stripeの安全な決済画面から有料プランへの登録ができます。決済情報はStripeが管理します。プランの解約・変更は<a href="{{ route('account.settings.edit') }}"><strong>アカウント設定</strong></a>から手続きできます。解約後も、お支払い済み期間の終了までは有料機能をご利用いただけます。</p>
                <a class="text-link" href="{{ route('tarot') }}">占いに戻る</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
