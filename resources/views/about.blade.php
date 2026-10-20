<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('心の庵について') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="history-panel">
                <p class="eyebrow">ABOUT KOKORO NO IORI</p>
                <h1>{{ __('心の庵について') }}</h1>

                @if (app()->isLocale('en'))
                    <p class="page-description">Kokoro no Iori is a quiet place to pause amid the busyness of everyday life and reflect on your thoughts and feelings.</p>
                    <section class="legal-section">
                        <h2>A space to reflect</h2>
                        <p>Put your worries and questions into words, then explore them through AI responses inspired by Zen and Buddhist philosophy. We hope each exchange offers a small opportunity to find perspective and organize your thoughts.</p>
                    </section>
                    <section class="legal-section">
                        <h2>A gentle companion, not an expert</h2>
                        <p>The responses are intended as prompts for reflection, not as definitive answers, professional advice, or a diagnosis. We invite you to take what feels useful at your own pace.</p>
                    </section>
                @else
                    <p class="page-description">心の庵は、日々の忙しさのなかで立ち止まり、自分の気持ちや考えを見つめ直すための場所です。</p>
                    <section class="legal-section">
                        <h2>心を見つめ直すひとときを</h2>
                        <p>悩みや迷いを言葉にし、禅や仏教の考え方に着想を得たAIの応答を通じて、一緒に考えを整理していきます。やりとりが、新しい視点に気づく小さなきっかけになればと願っています。</p>
                    </section>
                    <section class="legal-section">
                        <h2>答えを押しつけない、心の伴走者として</h2>
                        <p>お届けする応答は、考えを整理するための参考情報であり、正解を示したり、専門的な助言や診断を行ったりするものではありません。ご自身のペースで、心に留まった言葉を受け取ってください。</p>
                    </section>
                @endif
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
