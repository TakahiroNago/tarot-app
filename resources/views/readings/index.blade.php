<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>相談の記録 | 月詠 禅のことば</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel">
                <p class="eyebrow">READING JOURNAL</p>
                <div class="history-heading">
                    <h1>相談の記録</h1>
                    <form class="history-filter" method="GET" action="{{ route('readings.index') }}">
                        <select id="history-category" name="category" aria-label="表示するカテゴリ" data-history-category-filter>
                            <option value="">すべて</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                @if ($historyUpgradePrompt)
                    <p class="form-notice credit-notice">
                        無料会員の履歴表示は直近3か月の50件までです。有料プランで、より多くの履歴を確認できます。
                        <a href="{{ route('plans') }}">有料プランを見る</a>
                    </p>
                @endif

                @if ($sessionReadings->isNotEmpty())
                    <section class="history-group">
                        <h2>今回のセッション</h2>
                        <div class="reading-list">
                            @foreach ($sessionReadings as $reading)
                                <div class="reading-row reading-row-session">
                                    <a class="reading-question" href="{{ route('readings.show', $reading['id']) }}">{{ $reading['question'] }}</a>
                                    <span class="reading-card">{{ $reading['card'] ?? '禅師のことば' }}</span>
                                    <a class="reading-open" href="{{ route('readings.show', $reading['id']) }}">詳細 <span aria-hidden="true">→</span></a>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($databaseReadings?->isNotEmpty())
                    <section class="history-group">
                        <h2>これまでの相談</h2>
                        <div class="reading-list">
                            @foreach ($databaseReadings as $reading)
                                <a class="reading-row" href="{{ route('readings.show', $reading->id) }}">
                                    <span class="reading-question">{{ $reading->question }}</span>
                                    <span class="reading-card">{{ $reading->card ?? '禅師のことば' }}</span>
                                    <time class="reading-date" datetime="{{ $reading->created_at->toISOString() }}" data-local-time>{{ $reading->created_at->format('Y/m/d H:i') }}</time>
                                    <span class="reading-open">詳細 <span aria-hidden="true">→</span></span>
                                </a>
                            @endforeach
                        </div>
                        {{ $databaseReadings->links() }}
                    </section>
                @endif

                @if ($sessionReadings->isEmpty() && ! $databaseReadings?->isNotEmpty())
                    @if ($selectedCategory !== null)
                        <p class="history-empty">「{{ $selectedCategory }}」カテゴリの履歴はありません。</p>
                        <a class="history-filter-clear" href="{{ route('readings.index') }}">すべての履歴を表示</a>
                    @else
                        <p class="history-empty">まだ相談の記録がありません。</p>
                        <a class="primary-action" href="{{ route('tarot') }}">禅師に相談する <span class="action-arrow" aria-hidden="true">→</span></a>
                    @endif
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
