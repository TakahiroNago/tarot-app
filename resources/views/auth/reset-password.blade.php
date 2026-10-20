<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('新しいパスワード') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">NEW PASSWORD</p>
                <h1>{{ __('新しいパスワード') }}</h1>
                <form class="question-form" method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <label class="question-label" for="email">{{ __('メールアドレス') }}</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
                    @error('email')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                    <label class="question-label" for="password">{{ __('新しいパスワード') }}</label>
                    <input class="question-input" id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
                    @error('password')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                    <label class="question-label" for="password_confirmation">{{ __('パスワード（確認）') }}</label>
                    <input class="question-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12">
                    <button class="primary-action" type="submit">{{ __('パスワードを更新') }} <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
