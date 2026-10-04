<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タロット結果</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="result-panel">
                <div class="result-visual" aria-hidden="true">
                    <div class="tarot-card-art">
                        <span class="card-number">{{ $cardNumber }}</span>
                        <span class="card-sun">{{ $cardSymbol }}</span>
                        <span class="card-label">{{ $cardEnglishName }}</span>
                    </div>
                </div>
                <div class="result-copy">
                    <p class="eyebrow">YOUR READING</p>
                    <h1 class="result-heading">カードからのメッセージ</h1>

                    <section class="result-section">
                        <h2 class="section-label">あなたの質問</h2>
                        <p class="question-quote">{{ $question }}</p>
                    </section>

                    <section class="result-section">
                        <h2 class="section-label">引いたカード</h2>
                        <p class="drawn-card-name">{{ $card }}</p>
                    </section>

                    <section class="result-section">
                        <h2 class="section-label">リーディング結果</h2>
                        <p class="reading-copy">{{ $interpretation }}</p>
                    </section>

                    @if ($creditsRemaining === 0)
                        @if ($isGuest)
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}">
                                無料体験を使い切りました。会員登録すると毎日3回占えます。 会員登録はこちら
                            </a>
                        @else
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}">
                                本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。 プランを見る
                            </a>
                        @endif
                    @else
                        <p class="credit-summary">占い残り {{ $creditsRemaining }}回</p>
                    @endif

                    <a class="text-link" href="{{ route('tarot') }}">もう一度占う</a>
                </div>
            </section>
        </main>

        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>
