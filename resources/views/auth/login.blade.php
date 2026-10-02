<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ログイン | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">WELCOME BACK</p>
                <h1>ログイン</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                <form class="question-form" method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <label class="question-label" for="email">メールアドレス</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                    @error('email')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                    <label class="question-label" for="password">パスワード</label>
                    <input class="question-input" id="password" name="password" type="password" autocomplete="current-password" required>
                    @error('password')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                    <label class="remember-option"><input name="remember" type="checkbox" value="1"> ログイン状態を保持する</label>
                    <button class="primary-action" type="submit">ログイン <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
                <a class="text-link" href="{{ route('password.request') }}">パスワードをお忘れですか？</a>
                <a class="text-link" href="{{ route('register') }}">新規登録</a>
            </section>
        </main>
        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>