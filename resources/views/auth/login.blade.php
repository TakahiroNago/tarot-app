<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('ログイン') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">WELCOME BACK</p>
                <h1>{{ __('ログイン') }}</h1>
                @if (session('status'))@include('partials.toast', ['message' => __(session('status'))])@endif
                <form class="question-form" method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <label class="question-label" for="email">{{ __('メールアドレス') }}</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                    @error('email')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                    <label class="question-label" for="password">{{ __('パスワード') }}</label>
                    <input class="question-input" id="password" name="password" type="password" autocomplete="current-password" required>
                    @error('password')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                    <label class="remember-option"><input name="remember" type="checkbox" value="1"> {{ __('ログイン状態を保持する') }}</label>
                    <button class="primary-action" type="submit">{{ __('ログイン') }} <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
                <p class="login-divider"><span>{{ __('または') }}</span></p>
                <a class="google-action" href="{{ route('login.google') }}">
                    <svg aria-hidden="true" viewBox="0 0 48 48">
                        <path fill="#4285F4" d="M43.6 24.5c0-1.4-.1-2.8-.4-4.1H24v7.8h11a9.4 9.4 0 0 1-4.1 6.2v5.1h6.6c3.9-3.6 6.1-8.9 6.1-15Z"/>
                        <path fill="#34A853" d="M24 44c5.5 0 10.1-1.8 13.5-4.9l-6.6-5.1c-1.8 1.2-4.1 2-6.9 2-5.3 0-9.8-3.6-11.4-8.5H5.8v5.3A20 20 0 0 0 24 44Z"/>
                        <path fill="#FBBC05" d="M12.6 27.5a12 12 0 0 1 0-7.1v-5.3H5.8a20 20 0 0 0 0 17.7l6.8-5.3Z"/>
                        <path fill="#EA4335" d="M24 11.9c3 0 5.7 1 7.8 3.1l5.8-5.8A19.4 19.4 0 0 0 24 4 20 20 0 0 0 5.8 15.1l6.8 5.3c1.6-4.9 6.1-8.5 11.4-8.5Z"/>
                    </svg>
                    {{ __('Googleでログイン') }}
                </a>
                <p><a class="text-link" href="{{ route('password.request') }}">→ {{ __('パスワードをお忘れですか？') }}</a></p>
                <p><a class="text-link" href="{{ route('register') }}">→ {{ __('新規登録') }}</a></p>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
