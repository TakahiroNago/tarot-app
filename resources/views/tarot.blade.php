{{-- resoureces/veiws/tarot.blade.php --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タロット占い</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="form-panel">
                <p class="eyebrow">A MOMENT FOR YOU</p>
                <h1>心にある問いを、<br>聞かせてください。</h1>
                <p class="page-description">いま気になっていることを、自由に入力してください。</p>

                <form class="question-form" method="POST" action="{{ route('tarot.draw') }}">
                    @csrf
                    <label class="question-label" for="question">あなたの質問</label>
                    <input
                        class="question-input"
                        id="question"
                        type="text"
                        name="question"
                        value="{{ old('question') }}"
                        placeholder="例：新しい一歩を踏み出すには？"
                        maxlength="200"
                        required
                    >
                    @error('question')
                        <p class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                    <button class="primary-action" type="submit">
                        <span class="loading-spinner" aria-hidden="true"></span>
                        <span class="submit-label">カードを引く</span>
                        <span class="action-arrow" aria-hidden="true">→</span>
                    </button>
                </form>

                <a class="text-link" href="{{ route('home') }}">トップページへ戻る</a>
            </section>
        </main>

        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>
