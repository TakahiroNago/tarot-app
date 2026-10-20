<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>禅師からのことば | 心の庵 ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="result-panel result-panel-consultation">
                <div class="result-copy">
                    <p class="eyebrow result-eyebrow">WORDS FOR YOU</p>
                    <h1 class="result-heading result-title">禅師からのことば</h1>

                    <section class="result-section">
                        <h2 class="section-label">あなたのお悩み</h2>
                        <p class="question-quote">{{ $concern }}</p>
                    </section>

                    <section class="result-section">
                        <h2 class="section-label">お答え</h2>
                        <div class="consultation-copy">{!! app(App\Services\ConsultationResponseFormatter::class)->toHtml($response) !!}</div>
                    </section>

                    <section class="consultation-share" aria-labelledby="consultation-share-heading">
                        <h2 class="section-label" id="consultation-share-heading">ことばをシェア</h2>
                        <p class="consultation-share-note">公開用URLにはメールアドレスやユーザーIDは含まれません。URLを知っている人は結果を閲覧できます（有効期限30日）。</p>

                        <div class="consultation-share-actions">
                            <button class="consultation-share-button" type="button" data-share-link aria-label="Share Linkでシェア">シェア</button>
                        </div>
                        <div class="consultation-share-url">
                            <input
                                class="consultation-share-url-input"
                                id="consultation-share-url"
                                type="url"
                                value="{{ $shareUrl }}"
                                readonly
                                aria-label="公開用相談URL"
                            >
                        </div>
                        <p class="consultation-share-status" role="status" aria-live="polite" data-consultation-share-status></p>
                    </section>

                    @if ($creditsRemaining === null)
                        <p class="credit-summary">相談回数は無制限です</p>
                    @elseif ($creditsRemaining === 0)
                        @if ($isGuest)
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}">
                                無料体験を使い切りました。無料会員登録すると毎日1回相談できます。→ 会員登録はこちら
                            </a>
                        @else
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}">
                                本日利用可能なクレジットを使い切りました。→ 有料プランを見る
                            </a>
                        @endif
                    @else
                        <p class="credit-summary">相談残り {{ $creditsRemaining }}回</p>
                    @endif

                    <a class="text-link" href="{{ route('zen') }}">もう一度相談する</a>
                </div>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
    <script>
        const shareUrlInput = document.querySelector('#consultation-share-url');
        const shareStatus = document.querySelector('[data-consultation-share-status]');

        async function copyConsultationUrl() {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(shareUrlInput.value);
                shareStatus.textContent = '公開用URLをコピーしました。';
                return true;
            }

            shareUrlInput.focus();
            shareUrlInput.select();
            shareStatus.textContent = 'URLを選択しました。コピーしてご利用ください。';
            return false;
        }

        document.querySelector('[data-share-link]').addEventListener('click', async () => {
            try {
                if (navigator.share) {
                    await navigator.share({ url: shareUrlInput.value });
                    return;
                }

                const copied = await copyConsultationUrl();
                shareStatus.textContent = copied
                    ? '公開用URLをコピーしました。共有先に貼り付けてください。'
                    : 'URLを選択しました。コピーして共有先に貼り付けてください。';
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                shareStatus.textContent = '共有できませんでした。上記URLを手動でコピーしてください。';
            }
        });
    </script>
</body>
</html>
