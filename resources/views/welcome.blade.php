{{-- resources/views/welcome.blade.php --}}

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
            <section class="welcome-panel">
                <div class="welcome-copy">
                    @if (session('status') === 'email-verified')
                        <p class="form-notice" data-auto-dismiss role="status">メールアドレスを確認しました。</p>
                    @endif
                    @if (session('daily_reading_rate_limited'))
                        <p class="form-notice" role="status">{{ session('daily_reading_rate_limited') }}</p>
                    @endif
                    <p class="eyebrow">今日のあなたへ</p>
                    <h1 class="welcome-title">心の声に、<br>カードが光をあてる。</h1>
                    <p class="welcome-description">迷いや期待を、ひとつの問いに。カードが映す小さなヒントを、あなたの毎日に届けます。</p>
                    <a class="primary-action" href="{{ route('tarot') }}">
                        <span>占いを始める</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </a>
                    @auth
                        <form class="daily-reading-form" method="POST" action="{{ route('tarot.daily') }}">
                            @csrf
                            <button class="primary-action" type="submit">
                                <span>今日の1枚</span>
                                <span class="action-arrow" aria-hidden="true">→</span>
                            </button>
                        </form>
                    @endauth
                </div>
                <div class="art-stage" aria-hidden="true">
                    <div class="tarot-card-art">
                        <span class="card-number">XVII</span>
                        <span class="card-sun">✷</span>
                        <span class="card-label">THE STAR</span>
                    </div>
                </div>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>
