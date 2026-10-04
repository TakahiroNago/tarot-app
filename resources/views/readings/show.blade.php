<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>リーディング結果 | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="reading-detail">
                <p class="eyebrow">YOUR READING</p>
                <h1>カードからのメッセージ</h1>
                @if (isset($reading['created_at']))
                    <time class="reading-detail-date" datetime="{{ $reading['created_at']->toISOString() }}">{{ $reading['created_at']->format('Y/m/d H:i') }}</time>
                @else
                    <p class="reading-detail-date">このセッションの結果</p>
                @endif
                <section class="result-section">
                    <h2 class="section-label">あなたの質問</h2>
                    <p class="question-quote">{{ $reading['question'] }}</p>
                </section>
                <section class="result-section">
                    <h2 class="section-label">引いたカード</h2>
                    <p class="drawn-card-name">{{ $reading['card'] }}</p>
                </section>
                <section class="result-section">
                    <h2 class="section-label">リーディング結果</h2>
                    <p class="reading-copy">{{ $reading['interpretation'] }}</p>
                </section>
                <div class="history-actions">
                    <a class="text-link" href="{{ route('readings.index') }}">履歴一覧へ戻る</a>
                    <a class="text-link" href="{{ route('tarot') }}">心の問いを占う</a>
                </div>
            </article>
        </main>
        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>
