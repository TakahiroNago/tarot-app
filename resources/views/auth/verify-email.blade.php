<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>メールアドレスの確認 | 心の庵 ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">VERIFY YOUR EMAIL</p>
                <h1>メールアドレスの確認</h1>
                <p class="page-description">登録を完了するため、メールに記載された確認リンクを開いてください。</p>
                @if (session('status') === 'verification-link-sent')
                    <p class="form-notice" role="status">確認メールを再送しました。受信トレイをご確認ください。</p>
                @endif
                <form class="question-form" method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <button class="primary-action" type="submit">確認メールを再送する <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
