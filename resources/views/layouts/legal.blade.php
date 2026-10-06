<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="history-panel legal-panel">
                <p class="eyebrow">@yield('eyebrow')</p>
                <h1>@yield('title')</h1>
                <p class="legal-updated">更新日：2026年10月4日</p>
                @yield('legal-content')
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
