<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>リーディング結果 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="reading-detail">
                <div class="result-visual">
                    <div class="tarot-card-art" aria-hidden="true">
                        <span class="card-number">{{ $cardNumber }}</span>
                        <span class="card-sun">{{ $cardSymbol }}</span>
                        <span class="card-label">{{ $cardEnglishName }}</span>
                    </div>
                </div>
                <div class="reading-detail-copy">
                    <section class="result-section reading-detail-card-caption">
                        <h2 class="section-label">引いたカード</h2>
                        <p class="drawn-card-name">{{ $reading['card'] }}</p>
                    </section>
                    <p class="eyebrow reading-detail-eyebrow">YOUR READING</p>
                    <h1 class="reading-detail-title">カードからのメッセージ</h1>
                    <section class="result-section">
                        <h2 class="section-label">あなたの質問</h2>
                        <p class="question-quote">{{ $reading['question'] }}</p>
                    </section>
                    <section class="result-section reading-detail-card-section">
                        <h2 class="section-label">引いたカード</h2>
                        <p class="drawn-card-name">{{ $reading['card'] }}</p>
                    </section>
                    <section class="result-section">
                        <h2 class="section-label">
                            <span class="reading-result-label-desktop">リーディング結果</span>
                            <span class="reading-result-label-mobile">カードからのメッセージ</span>
                        </h2>
                        <p class="reading-copy">{{ $reading['interpretation'] }}</p>
                    </section>
                    <div class="history-actions">
                        <a class="text-link" href="{{ route('readings.index') }}">履歴一覧へ戻る</a>
                        <a class="text-link" href="{{ route('tarot') }}">心の問いを占う</a>
                    </div>
                </div>
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
