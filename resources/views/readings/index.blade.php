<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>占い履歴 | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel">
                <p class="eyebrow">READING JOURNAL</p>
                <h1>占い履歴</h1>

                @if ($sessionReadings->isNotEmpty())
                    <section class="history-group">
                        <h2>今回のセッション</h2>
                        <div class="reading-list">
                            @foreach ($sessionReadings as $reading)
                                <div class="reading-row reading-row-session">
                                    <a class="reading-question" href="{{ route('readings.show', $reading['id']) }}">{{ $reading['question'] }}</a>
                                    <span class="reading-card">{{ $reading['card'] }}</span>
                                    <a class="reading-open" href="{{ route('readings.show', $reading['id']) }}">詳細 <span aria-hidden="true">→</span></a>
                                    @auth
                                        <form class="reading-save-form" method="POST" action="{{ route('readings.save', $reading['id']) }}">
                                            @csrf
                                            <button class="reading-save-button" type="submit">タロット履歴に保存</button>
                                        </form>
                                    @endauth
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($databaseReadings?->isNotEmpty())
                    <section class="history-group">
                        <h2>タロット履歴</h2>
                        <div class="reading-list">
                            @foreach ($databaseReadings as $reading)
                                <a class="reading-row" href="{{ route('readings.show', $reading->id) }}">
                                    <span class="reading-question">{{ $reading->question }}</span>
                                    <span class="reading-card">{{ $reading->card }}</span>
                                    <time class="reading-date" datetime="{{ $reading->created_at->toISOString() }}">{{ $reading->created_at->format('Y/m/d H:i') }}</time>
                                    <span class="reading-open">詳細 <span aria-hidden="true">→</span></span>
                                </a>
                            @endforeach
                        </div>
                        {{ $databaseReadings->links() }}
                    </section>
                @endif

                @if ($sessionReadings->isEmpty() && ! $databaseReadings?->isNotEmpty())
                    <p class="history-empty">まだリーディング履歴がありません。</p>
                    <a class="primary-action" href="{{ route('tarot') }}">占いを始める <span class="action-arrow" aria-hidden="true">→</span></a>
                @endif
            </section>
        </main>
        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>
