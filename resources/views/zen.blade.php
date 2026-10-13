{{-- Zen consultation form. --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>禅師に相談 | 月詠 禅のことば</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="form-panel">
                @if (session('status') === 'email-verified')
                    <p class="form-notice" data-auto-dismiss role="status">メールアドレスを確認しました。</p>
                @endif
                @if (session('consultation_rate_limited'))
                    <p class="form-notice credit-notice" role="alert">{{ session('consultation_rate_limited') }}</p>
                @endif
                <p class="eyebrow">A MOMENT FOR YOU</p>
                <h1>心にある悩みを<br>聞かせてください</h1>
                <p class="page-description">AIが仏教・禅の考え方を参考に、慈悲と智慧をもってあなたの悩みに向き合います。</p>

                @auth
                    @if (! $creditSummary['unlimited'] && $creditSummary['total'] === 0)
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}" role="alert">
                            本日利用可能なクレジットを使い切りました。→有料プランを見る
                        </a>
                    @elseif (session('credit_exhausted'))
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}" role="alert">
                            {{ session('credit_exhausted') }} クレジット・プランを見る
                        </a>
                    @endif
                @else
                    @if ($guestCredits === 0)
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}" role="alert">
                            無料体験を使い切りました。無料会員登録すると毎日3回相談できます。→ 会員登録はこちら
                        </a>
                    @else
                        <p class="credit-summary">無料体験の残り {{ $guestCredits }}回（全3回）</p>
                        @if (session('credit_exhausted'))
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}" role="alert">
                                {{ session('credit_exhausted') }} 会員登録はこちら
                            </a>
                        @endif
                    @endif
                @endauth

                <form class="question-form" method="POST" action="{{ route('zen.respond') }}">
                    @csrf
                    <label class="question-label" for="concern">あなたのお悩み</label>
                    <input
                        class="question-input"
                        id="concern"
                        type="text"
                        name="concern"
                        value="{{ old('concern') }}"
                        placeholder="例：新しい一歩を踏み出すには、どうしたらいいですか？"
                        maxlength="200"
                        required
                    >
                    @error('concern')
                        <p class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                    <button class="primary-action" type="submit" @disabled(auth()->check() ? (! $creditSummary['unlimited'] && $creditSummary['total'] === 0) : $guestCredits === 0)>
                        <span class="loading-spinner" aria-hidden="true"></span>
                        <span class="submit-label">相談する</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </button>
                </form>

                @if (! auth()->check() || auth()->user()->plan !== \App\Models\User::PLAN_PREMIUM)
                    <a class="text-link" href="{{ route('plans') }}">料金・プランを見る</a>
                @endif
                <a class="text-link zen-home-link" href="{{ route('home') }}">トップページへ戻る</a>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>
