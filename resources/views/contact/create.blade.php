<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('お問い合わせ') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">CONTACT</p>
                <h1>{{ __('お問い合わせ') }}</h1>
                <p class="page-description">{{ __('ご質問やご相談はこちらのフォームからお送りください。内容を確認のうえ、必要に応じてご入力いただいたメールアドレス宛に返信します。') }}</p>

                @if (session('status'))
                    @include('partials.toast', ['message' => __(session('status'))])
                @endif

                <form class="question-form" method="POST" action="{{ route('contact.store') }}">
                    @csrf
                    <label class="question-label" for="name">{{ __('お名前') }}</label>
                    <input class="question-input" id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" required maxlength="100">
                    @error('name')<p class="form-error">{{ __($message) }}</p>@enderror

                    <label class="question-label" for="email">{{ __('返信先メールアドレス') }}</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="254">
                    @error('email')<p class="form-error">{{ __($message) }}</p>@enderror

                    <label class="question-label" for="subject">{{ __('件名') }}</label>
                    <input class="question-input" id="subject" name="subject" type="text" value="{{ old('subject') }}" required maxlength="150">
                    @error('subject')<p class="form-error">{{ __($message) }}</p>@enderror

                    <label class="question-label" for="message">{{ __('お問い合わせ内容') }}</label>
                    <textarea class="question-input question-textarea" id="message" name="message" rows="8" required maxlength="5000">{{ old('message') }}</textarea>
                    @error('message')<p class="form-error">{{ __($message) }}</p>@enderror

                    <p class="contact-privacy-note">{{ __('ご入力いただいた情報は、お問い合わせへの対応にのみ使用します。詳しくは') }}<a href="{{ route('legal.privacy') }}">{{ __('プライバシーポリシー') }}</a>{{ __('をご確認ください。') }}</p>
                    <button class="primary-action" type="submit">{{ __('送信する') }} <span class="action-arrow" aria-hidden="true">→</span></button>
                </form>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
