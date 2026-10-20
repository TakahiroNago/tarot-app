<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="history-panel legal-panel">
                <p class="eyebrow">@yield('eyebrow')</p>
                <h1>@yield('title')</h1>
                <p class="legal-updated">{{ __('更新日') }}: {{ app()->isLocale('ja') ? '2026年10月13日' : 'October 13, 2026' }}</p>
                @if (app()->isLocale('en'))
                    <p class="legal-translation-notice">{{ __('これは日本語版を正本とする参考訳です。内容に相違がある場合は日本語版が優先します。') }}</p>
                @endif
                @yield('legal-content')
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
