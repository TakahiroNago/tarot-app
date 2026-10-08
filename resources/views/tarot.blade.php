{{-- resoureces/veiws/tarot.blade.php --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タロット占い | 月詠 TAROT JOURNAL</title>
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
                @if (session('reading_rate_limited'))
                    <p class="form-notice credit-notice" role="alert">{{ session('reading_rate_limited') }}</p>
                @endif
                <p class="eyebrow">A MOMENT FOR YOU</p>
                <h1>心にある問いを、<br>聞かせてください。</h1>
                <p class="page-description">いま気になっていることを、自由に入力してください。</p>

                @auth
                    @if (! $creditSummary['unlimited'] && $creditSummary['total'] === 0)
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}" role="alert">
                            本日利用可能なクレジットを使い切りました。有料プランをご検討ください。 プランを見る
                        </a>
                    @elseif (session('credit_exhausted'))
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}" role="alert">
                            {{ session('credit_exhausted') }} クレジット・プランを見る
                        </a>
                    @endif
                @else
                    @if ($guestCredits === 0)
                        <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}" role="alert">
                            無料体験を使い切りました。会員登録すると毎日3回占えます。 会員登録はこちら
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

                <form class="question-form" method="POST" action="{{ route('tarot.draw') }}">
                    @csrf
                    <label class="question-label" for="question">あなたの質問</label>
                    <input
                        class="question-input"
                        id="question"
                        type="text"
                        name="question"
                        value="{{ old('question') }}"
                        placeholder="例：新しい一歩を踏み出すには？"
                        maxlength="200"
                        required
                    >
                    @error('question')
                        <p class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                    <button class="primary-action" type="submit" @disabled(auth()->check() ? (! $creditSummary['unlimited'] && $creditSummary['total'] === 0) : $guestCredits === 0)>
                        <span class="loading-spinner" aria-hidden="true"></span>
                        <span class="submit-label">カードを引く</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </button>
                </form>

                @if (! auth()->check() || auth()->user()->plan !== \App\Models\User::PLAN_PREMIUM)
                    <a class="text-link" href="{{ route('plans') }}">料金・プランを見る</a>
                @endif
                <a class="text-link tarot-home-link" href="{{ route('home') }}">トップページへ戻る</a>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>
