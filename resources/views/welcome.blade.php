{{-- resources/views/welcome.blade.php --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>心の庵 ZEN REFLECTIONS</title>
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
                    <p class="eyebrow">今日のあなたへ</p>
                    <h1 class="welcome-title">迷いの中に<br>小さな一歩を</h1>
                    <p class="welcome-description">お悩みを聞かせてください。AIが禅の視点で、心を見つめることばをお届けします。</p>
                    <a class="primary-action" href="{{ route('zen') }}">
                        <span>AI禅師に相談する</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </a>
                </div>
                <div class="art-stage">
                    <img class="welcome-logo" src="{{ asset('images/logo.png') }}" alt="心の庵 ZEN REFLECTIONS">
                </div>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>
