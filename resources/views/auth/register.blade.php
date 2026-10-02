<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>新規登録 | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">CREATE ACCOUNT</p>
                <h1>新規登録</h1>
                <form class="question-form" method="POST" action="{{ route('register.store') }}">
                    @csrf
                    <label class="question-label" for="name">お名前</label>
                    <input class="question-input" id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" required maxlength="255">
                    @error('name')<p class="form-error">{{ $message }}</p>@enderror

                    <label class="question-label" for="email">メールアドレス</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255">
                    @error('email')<p class="form-error">{{ $message }}</p>@enderror

                    <label class="question-label" for="password">パスワード</label>
                    <input class="question-input" id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
                    @error('password')<p class="form-error">{{ $message }}</p>@enderror

                    <label class="question-label" for="password_confirmation">パスワード（確認）</label>
                    <input class="question-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12">

                    <button class="primary-action" type="submit">登録する <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
                <a class="text-link" href="{{ route('login') }}">すでにアカウントをお持ちの方</a>
            </section>
        </main>
        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>