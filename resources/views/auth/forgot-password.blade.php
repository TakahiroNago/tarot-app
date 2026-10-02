<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>パスワード再設定 | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">PASSWORD RESET</p>
                <h1>パスワード再設定</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                <form class="question-form" method="POST" action="{{ route('password.email') }}">
                    @csrf
                    <label class="question-label" for="email">登録メールアドレス</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                    @error('email')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    <button class="primary-action" type="submit">再設定メールを送る <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
                <a class="text-link" href="{{ route('login') }}">ログインへ戻る</a>
            </section>
        </main>
        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>