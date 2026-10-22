<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('料金・プラン') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel plans-panel">
                <p class="eyebrow">MEMBERSHIP</p>
                <h1>{{ __('料金・プラン') }}</h1>

                @if ($errors->has('billing'))
                    @include('partials.toast', ['message' => __($errors->first('billing')), 'type' => 'error'])
                @endif
                @if (request('checkout') === 'success')
                    @include('partials.toast', ['message' => __('決済を受け付けました。反映まで少し時間がかかる場合があります。')])
                @elseif (request('checkout') === 'cancelled')
                    @include('partials.toast', ['message' => __('決済は完了していません。必要であれば、いつでも再度お申し込みいただけます。')])
                @endif

                @auth
                    <p class="form-notice credit-notice" role="status">
                        {{ __('現在のプラン') }}{{ app()->isLocale('ja') ? '：' : ': ' }}{{ auth()->user()->plan === \App\Models\User::PLAN_PREMIUM ? __('プレミアムプラン') : (auth()->user()->plan === \App\Models\User::PLAN_LITE ? __('ライトプラン') : __('無料会員')) }}
                        @if (auth()->user()->stripe_subscription_id !== null && auth()->user()->stripe_cancel_at_period_end && auth()->user()->stripe_current_period_end)
                            ({{ app()->isLocale('ja') ? auth()->user()->stripe_current_period_end->format('Y年n月j日').'に終了予定' : __('契約終了予定: :date', ['date' => auth()->user()->stripe_current_period_end->format('M j, Y')]) }})
                        @endif
                    </p>
                @endauth

                <div class="plan-grid">
                    <section class="plan-card">
                        <h2>{{ __('プレミアムプラン') }}</h2>
                        <p>&bull; {{ __('相談回数無制限') }}</p>
                        <p>&bull; {{ __('すべての相談の記録') }}</p>
                        <p>&bull; {{ __('過去の相談を踏まえた回答') }}</p>
                        <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '580円' : '¥580' }}/<span class="plan-price-period">{{ __('月') }}</span></strong></p>
                        @if (auth()->check() && auth()->user()->stripe_subscription_id === null && auth()->user()->plan === \App\Models\User::PLAN_FREE && config('services.stripe.secret') && config('services.stripe.prices.premium'))
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="product" value="premium">
                                <button class="primary-action" type="submit">{{ __('プレミアムに申し込む') }}</button>
                            </form>
                        @elseif (auth()->guest())
                            <p><a class="primary-action" href="{{ route('login') }}">→ {{ __('ログインして') }}<br>{{ __('プレミアムに申し込む') }}</a></p>
                        @elseif (! auth()->check() || ! config('services.stripe.secret') || ! config('services.stripe.prices.premium'))
                            <p>{{ __('決済準備中') }}</p>
                        @endif
                        <p>{{ app()->isLocale('ja') ? '※ ' : '* ' }}{{ __('短時間でのボットなどによる過重アクセスは一時的に制限される場合があります。') }}</p>
                    </section>
                    <section class="plan-card">
                        <h2>{{ __('ライトプラン') }}</h2>
                        <p>&bull; {{ __('1日10回（最大50回まで引継ぎ）') }}</p>
                        <p>&bull; {{ __('過去3か月の相談の記録') }}</p>
                        <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '220円' : '¥220' }}/<span class="plan-price-period">{{ __('月') }}</span></strong></p>
                        @if (auth()->check() && auth()->user()->stripe_subscription_id === null && auth()->user()->plan === \App\Models\User::PLAN_FREE && config('services.stripe.secret') && config('services.stripe.prices.lite'))
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="product" value="lite">
                                <button class="primary-action" type="submit">{{ __('ライトに申し込む') }}</button>
                            </form>
                        @elseif (auth()->guest())
                            <p><a class="primary-action" href="{{ route('login') }}">→ {{ __('ログインして') }}<br>{{ __('ライトに申し込む') }}</a></p>
                        @elseif (! auth()->check() || ! config('services.stripe.secret') || ! config('services.stripe.prices.lite'))
                            <p>{{ __('決済準備中') }}</p>
                        @endif
                    </section>
                    <section class="plan-card">
                        <h2>{{ __('無料会員') }}</h2>
                        <p>&bull; {{ __('1日1回（回数引継ぎなし）') }}</p>
                        <p>&bull; {{ __('過去3か月（最大50件）までの相談の記録') }}</p>
                        <p>&bull; {{ __('未ログインでの無料体験は1回まで') }}</p>
                        @if (auth()->guest())
                            <p><a class="primary-action" href="{{ route('register') }}">→ {{ __('新規登録') }}</a></p>
                        @endif
                    </section>
                </div>

                @auth
                    <p class="page-description">
                        {{ __('プランの解約・変更は') }}<a href="{{ route('account.settings.edit') }}"><strong>{{ __('アカウント設定') }}</strong></a>{{ __('から手続きできます。解約後も、お支払い済み期間の終了までは有料機能をご利用いただけます。') }}
                    </p>
                @endauth
                <a class="text-link" href="{{ route('zen') }}">→ {{ __('相談に戻る') }}</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
