<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>相談の記録 | 月詠 禅のことば</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="reading-detail{{ $reading['card'] === null ? ' reading-detail-no-card' : '' }}">
                @if ($reading['card'] !== null)
                    <div class="result-visual">
                        <div class="tarot-card-art" aria-hidden="true">
                            <span class="card-number">{{ $cardNumber }}</span>
                            <span class="card-sun">{{ $cardSymbol }}</span>
                            <span class="card-label">{{ $cardEnglishName }}</span>
                        </div>
                    </div>
                @endif
                <div class="reading-detail-copy">
                    <p class="eyebrow reading-detail-eyebrow">YOUR REFLECTION</p>
                    <h1 class="reading-detail-title">禅師からのことば</h1>
                    <section class="result-section">
                        <h2 class="section-label">あなたのお悩み</h2>
                        <p class="question-quote">{{ $reading['question'] }}</p>
                    </section>
                    @if ($reading['card'] !== null)
                        <section class="result-section reading-detail-card-section">
                            <h2 class="section-label">当時のカード</h2>
                            <p class="drawn-card-name">{{ $reading['card'] }}</p>
                        </section>
                    @endif
                    <section class="result-section">
                        <h2 class="section-label">お答え</h2>
                        <p class="reading-copy">{{ $reading['interpretation'] }}</p>
                    </section>
                    <div class="history-actions">
                        <a class="text-link" href="{{ route('readings.index') }}">履歴一覧へ戻る</a>
                        <a class="text-link" href="{{ route('tarot') }}">もう一度相談する</a>
                    </div>
                </div>
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
