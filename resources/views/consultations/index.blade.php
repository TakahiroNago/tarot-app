<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>相談の記録 | 心の庵 禅のことば</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel">
                <p class="eyebrow">ZEN JOURNAL</p>
                <div class="history-heading">
                    <h1>相談の記録</h1>
                    <form class="history-filter" method="GET" action="{{ route('consultations.index') }}">
                        <select id="history-topic" name="topic" aria-label="表示するテーマ" data-history-topic-filter>
                            <option value="">すべて</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic }}" @selected($selectedTopic === $topic)>{{ $topic }}</option>
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

                @if ($sessionConsultations->isNotEmpty())
                    <section class="history-group">
                        <h2>今回のセッション</h2>
                        <div class="consultation-list">
                            @foreach ($sessionConsultations as $consultation)
                                <div class="consultation-row consultation-row-session">
                                    <a class="consultation-question" href="{{ route('consultations.show', $consultation['id']) }}">{{ $consultation['concern'] }}</a>
                                    <a class="consultation-open" href="{{ route('consultations.show', $consultation['id']) }}">詳細 <span aria-hidden="true">→</span></a>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($databaseConsultations?->isNotEmpty())
                    <section class="history-group">
                        <h2>これまでの相談</h2>
                        <div class="consultation-list">
                            @foreach ($databaseConsultations as $consultation)
                                <a class="consultation-row" href="{{ route('consultations.show', $consultation->id) }}">
                                    <span class="consultation-question">{{ $consultation->concern }}</span>
                                    <time class="consultation-date" datetime="{{ $consultation->created_at->toISOString() }}" data-local-time>{{ $consultation->created_at->format('Y/m/d H:i') }}</time>
                                    <span class="consultation-open">詳細 <span aria-hidden="true">→</span></span>
                                </a>
                            @endforeach
                        </div>
                        {{ $databaseConsultations->links() }}
                    </section>
                @endif

                @if ($sessionConsultations->isEmpty() && ! $databaseConsultations?->isNotEmpty())
                    @if ($selectedTopic !== null)
                        <p class="history-empty">「{{ $selectedTopic }}」テーマの相談記録はありません。</p>
                        <a class="history-filter-clear" href="{{ route('consultations.index') }}">すべての相談記録を表示</a>
                    @else
                        <p class="history-empty">まだ相談の記録がありません。</p>
                        <a class="primary-action" href="{{ route('zen') }}">禅師に相談する <span class="action-arrow" aria-hidden="true">→</span></a>
                    @endif
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
