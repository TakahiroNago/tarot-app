{{-- Zen consultation form. --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="form-panel">
                @if (session('status') === 'email-verified')
                    <p class="form-notice" data-auto-dismiss role="status">{{ __('メールアドレスを確認しました。') }}</p>
                @endif
                @if (session('consultation_rate_limited'))
                    <p class="form-notice credit-notice" role="alert">{{ session('consultation_rate_limited') }}</p>
                @endif
                <p class="eyebrow">A MOMENT FOR YOU</p>
                <h1>{{ __('心にある悩みを') }}<br>{{ __('聞かせてください') }}</h1>
                <p class="page-description">{{ __('AIが禅の考え方を基にして、あなたの悩みに向き合います。') }}</p>

                @auth
                    @if (! $creditSummary['unlimited'] && $creditSummary['total'] === 0)
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}" role="alert">
                            {{ __('本日利用可能なクレジットを使い切りました。') }}{{ app()->isLocale('ja') ? '→' : ' → ' }}{{ __('有料プランを見る') }}
                        </a>
                    @elseif (session('credit_exhausted'))
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}" role="alert">
                            {{ session('credit_exhausted') }} {{ __('クレジット・プランを見る') }}
                        </a>
                    @endif
                @else
                    @if ($guestCredits === 0)
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}" role="alert">
                            {{ __('無料体験を使い切りました。無料会員登録すると毎日1回相談できます。') }}{{ app()->isLocale('ja') ? '→ ' : ' → ' }}{{ __('会員登録はこちら') }}
                        </a>
                    @else
                        <p class="credit-summary">{{ app()->isLocale('ja') ? __('無料体験の残り').' '.$guestCredits.'回' : __('無料体験の残り: :count回', ['count' => $guestCredits]) }}</p>
                        @if (session('credit_exhausted'))
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}" role="alert">
                                {{ session('credit_exhausted') }} {{ __('会員登録はこちら') }}
                            </a>
                        @endif
                    @endif
                @endauth

                <form class="question-form" method="POST" action="{{ route('zen.respond') }}" data-loading-label="{{ __('お悩みを読み解いています...') }}">
                    @csrf
                    <label class="question-label" for="concern">{{ __('あなたのお悩み') }}</label>
                    <input
                        class="question-input"
                        id="concern"
                        type="text"
                        name="concern"
                        value="{{ old('concern') }}"
                        placeholder="{{ __('例：新しい一歩を踏み出すには？') }}"
                        maxlength="200"
                        required
                    >
                    <p class="page-description">{{ __('氏名・住所・勤務先・電話番号・メールアドレスなどの個人情報は、ご自身や他の方のものも含めて書かないでください。') }}</p>
                    @error('concern')
                        <p class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                    @if (session('personal_info_detected') || old('confirm_personal_info'))
                        <label class="form-notice">
                            <input type="checkbox" name="confirm_personal_info" value="1" @checked(old('confirm_personal_info'))>
                            {{ __('個人情報が含まれていることを理解したうえで、このまま送信する（共有URLでは相談文が非表示になります）') }}
                        </label>
                    @endif
                    <button class="primary-action" type="submit" @disabled(auth()->check() ? (! $creditSummary['unlimited'] && $creditSummary['total'] === 0) : $guestCredits === 0)>
                        <span class="loading-spinner" aria-hidden="true"></span>
                        <span class="submit-label">{{ __('相談する') }}</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </button>
                </form>
                @if (session('anonymized_concern'))
                    <form class="question-form" method="POST" action="{{ route('zen.respond') }}" data-loading-label="{{ __('お悩みを読み解いています...') }}">
                        @csrf
                        <p class="form-notice">{{ __('個人情報と思われる部分を伏せた内容で相談できます。内容をご確認ください。') }}</p>
                        <p class="question-quote">{{ session('anonymized_concern') }}</p>
                        <input type="hidden" name="concern" value="{{ session('anonymized_concern') }}">
                        <button class="primary-action" type="submit">
                            <span class="loading-spinner" aria-hidden="true"></span>
                            <span class="submit-label">{{ __('この内容で相談する') }}</span>
                            <span class="action-arrow" aria-hidden="true">→</span>
                        </button>
                    </form>
                @endif
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>
