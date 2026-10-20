<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('共有されたことば') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        <header class="site-header">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark" aria-hidden="true">✦</span>
                <span class="brand-name">{{ __('心の庵') }}</span>
                <span class="brand-caption">ZEN REFLECTIONS</span>
            </a>
        </header>
        <main class="page-content history-content">
            <article class="consultation-detail consultation-detail">
                <div class="consultation-detail-copy">
                    <p class="eyebrow consultation-detail-eyebrow">WORDS FOR YOU</p>
                    <h1 class="consultation-detail-title">{{ __('禅師からのことば') }}</h1>
                    <section class="result-section">
                        <h2 class="section-label">{{ __('あなたのお悩み') }}</h2>
                        @if ($consultation['concern_hidden'])
                            <p class="question-quote">{{ __('プライバシー保護のため、相談文は表示されません。') }}</p>
                        @else
                            <p class="question-quote">{{ $consultation['concern'] }}</p>
                        @endif
                    </section>
                    <section class="result-section">
                        <h2 class="section-label">{{ __('お答え') }}</h2>
                        <div class="consultation-copy">{!! app(App\Services\ConsultationResponseFormatter::class)->toHtml($consultation['response']) !!}</div>
                    </section>
                    <a class="text-link" href="{{ route('zen') }}">{{ __('もう一度相談する') }}</a>
                </div>
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
