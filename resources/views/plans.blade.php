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
                <p class="eyebrow">{{ __('MEMBERSHIP') }}</p>
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
                        @if (auth()->check() && auth()->user()->stripe_subscription_id !== null && auth()->user()->plan === \App\Models\User::PLAN_LITE && ! auth()->user()->stripe_cancel_at_period_end && auth()->user()->stripe_scheduled_plan === null && config('services.stripe.secret') && (config('services.stripe.prices.premium') || config('services.stripe.prices.premium_yearly')))
                            <form method="POST" action="{{ route('billing.plan.change') }}" data-plan-change-form data-confirmation-note="{{ __('プレミアムへの変更は即時適用され、現在の期間の未使用分は日割り計算で今回の請求に充当されます。') }}">
                                @csrf
                                <fieldset class="plan-billing-options">
                                    <legend>{{ __('お支払い方法') }}</legend>
                                    @if (config('services.stripe.prices.premium'))
                                        <label class="plan-billing-option">
                                            <input type="radio" name="plan" value="premium" checked required>
                                            <span>{{ app()->isLocale('ja') ? '580円' : '¥580' }}/{{ __('月') }}</span>
                                        </label>
                                    @endif
                                    @if (config('services.stripe.prices.premium_yearly'))
                                        <label class="plan-billing-option">
                                            <input type="radio" name="plan" value="premium_yearly" @checked(! config('services.stripe.prices.premium')) required>
                                            <span>{{ app()->isLocale('ja') ? '5,800円' : '¥5,800' }}/{{ __('年') }}</span>
                                        </label>
                                    @endif
                                </fieldset>
                                <button class="primary-action" type="submit">{{ __('プレミアムに申し込む') }}</button>
                            </form>
                        @elseif (auth()->check() && auth()->user()->stripe_subscription_id === null && auth()->user()->plan === \App\Models\User::PLAN_FREE && config('services.stripe.secret') && (config('services.stripe.prices.premium') || config('services.stripe.prices.premium_yearly')))
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <fieldset class="plan-billing-options">
                                    <legend>{{ __('お支払い方法') }}</legend>
                                    @if (config('services.stripe.prices.premium'))
                                        <label class="plan-billing-option">
                                            <input type="radio" name="product" value="premium" checked required>
                                            <span>{{ app()->isLocale('ja') ? '580円' : '¥580' }}/{{ __('月') }}</span>
                                        </label>
                                    @endif
                                    @if (config('services.stripe.prices.premium_yearly'))
                                        <label class="plan-billing-option">
                                            <input type="radio" name="product" value="premium_yearly" @checked(! config('services.stripe.prices.premium')) required>
                                            <span>{{ app()->isLocale('ja') ? '5,800円' : '¥5,800' }}/{{ __('年') }}</span>
                                        </label>
                                    @endif
                                </fieldset>
                                <button class="primary-action" type="submit">{{ __('プレミアムに申し込む') }}</button>
                            </form>
                        @elseif (auth()->guest())
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '580円' : '¥580' }}/<span class="plan-price-period">{{ __('月') }}</span></strong></p>
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '5,800円' : '¥5,800' }}/<span class="plan-price-period">{{ __('年') }}</span></strong></p>
                            <p><a class="primary-action" href="{{ route('login') }}">→ {{ __('ログインして') }}<br>{{ __('プレミアムに申し込む') }}</a></p>
                        @elseif (! auth()->check() || ! config('services.stripe.secret') || (! config('services.stripe.prices.premium') && ! config('services.stripe.prices.premium_yearly')))
                            <p>{{ __('決済準備中') }}</p>
                        @else
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '580円' : '¥580' }}/<span class="plan-price-period">{{ __('月') }}</span></strong></p>
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '5,800円' : '¥5,800' }}/<span class="plan-price-period">{{ __('年') }}</span></strong></p>
                        @endif
                        <p>{{ app()->isLocale('ja') ? '※ ' : '* ' }}{{ __('短時間でのボットなどによる過重アクセスは一時的に制限される場合があります。') }}</p>
                    </section>
                    <section class="plan-card">
                        <h2>{{ __('ライトプラン') }}</h2>
                        <p>&bull; {{ __('1日10回（最大50回まで引継ぎ）') }}</p>
                        <p>&bull; {{ __('過去3か月の相談の記録') }}</p>
                        @if (auth()->check() && auth()->user()->stripe_subscription_id === null && auth()->user()->plan === \App\Models\User::PLAN_FREE && config('services.stripe.secret') && (config('services.stripe.prices.lite') || config('services.stripe.prices.lite_yearly')))
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <fieldset class="plan-billing-options">
                                    <legend>{{ __('お支払い方法') }}</legend>
                                    @if (config('services.stripe.prices.lite'))
                                        <label class="plan-billing-option">
                                            <input type="radio" name="product" value="lite" checked required>
                                            <span>{{ app()->isLocale('ja') ? '220円' : '¥220' }}/{{ __('月') }}</span>
                                        </label>
                                    @endif
                                    @if (config('services.stripe.prices.lite_yearly'))
                                        <label class="plan-billing-option">
                                            <input type="radio" name="product" value="lite_yearly" @checked(! config('services.stripe.prices.lite')) required>
                                            <span>{{ app()->isLocale('ja') ? '2,200円' : '¥2,200' }}/{{ __('年') }}</span>
                                        </label>
                                    @endif
                                </fieldset>
                                <button class="primary-action" type="submit">{{ __('ライトに申し込む') }}</button>
                            </form>
                        @elseif (auth()->guest())
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '220円' : '¥220' }}/<span class="plan-price-period">{{ __('月') }}</span></strong></p>
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '2,200円' : '¥2,200' }}/<span class="plan-price-period">{{ __('年') }}</span></strong></p>
                            <p><a class="primary-action" href="{{ route('login') }}">→ {{ __('ログインして') }}<br>{{ __('ライトに申し込む') }}</a></p>
                        @elseif (! auth()->check() || ! config('services.stripe.secret') || (! config('services.stripe.prices.lite') && ! config('services.stripe.prices.lite_yearly')))
                            <p>{{ __('決済準備中') }}</p>
                        @else
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '220円' : '¥220' }}/<span class="plan-price-period">{{ __('月') }}</span></strong></p>
                            <p class="plan-price"><strong>{{ app()->isLocale('ja') ? '2,200円' : '¥2,200' }}/<span class="plan-price-period">{{ __('年') }}</span></strong></p>
                        @endif
                    </section>
                    <section class="plan-card">
                        <h2>{{ __('無料会員') }}</h2>
                        <p>&bull; {{ __('1日1回（回数引継ぎなし）') }}</p>
                        <p>&bull; {{ __('過去3か月（最大50件）までの相談の記録') }}</p>
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
    @auth
        @include('partials.plan-change-dialog')
    @endauth
</body>
</html>
