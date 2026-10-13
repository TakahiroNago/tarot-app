{{-- resources/views/welcome.blade.php --}}

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
            <section class="welcome-panel">
                <div class="welcome-copy">
                    @if (session('status') === 'email-verified')
                        <p class="form-notice" data-auto-dismiss role="status">メールアドレスを確認しました。</p>
                    @endif
                    @if (session('daily_reading_rate_limited'))
                        <p class="form-notice" role="status">{{ session('daily_reading_rate_limited') }}</p>
                    @endif
                    <p class="eyebrow">今日のあなたへ</p>
                    <h1 class="welcome-title">迷いの中に<br>小さな一歩を</h1>
                    <p class="welcome-description">悩みを聞かせてください。AIが禅の視点を参考に、慈悲と智慧をもって心を見つめることばをお届けします。</p>
                    <a class="primary-action" href="{{ route('tarot') }}">
                        <span>禅師に相談する</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </a>
                    @auth
                        <form class="daily-reading-form" method="POST" action="{{ route('tarot.daily') }}">
                            @csrf
                            <button class="primary-action" type="submit">
                                <span>今日のメッセージ</span>
                                <span class="action-arrow" aria-hidden="true">→</span>
                            </button>
                        </form>
                    @endauth
                </div>
                <div class="art-stage" aria-hidden="true">
                    <p class="zen-quote">静けさの中に、<br>答えの芽がある。</p>
                </div>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>
