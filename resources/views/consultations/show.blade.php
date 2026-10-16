<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>相談の記録 | 心の庵 禅のことば</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <article class="consultation-detail consultation-detail">
                <div class="consultation-detail-copy">
                    <p class="eyebrow consultation-detail-eyebrow">YOUR REFLECTION</p>
                    <h1 class="consultation-detail-title">禅師からのことば</h1>
                    <section class="result-section">
                        <h2 class="section-label">あなたのお悩み</h2>
                        <p class="question-quote">{{ $consultation['concern'] }}</p>
                    </section>
                    <section class="result-section">
                        <h2 class="section-label">お答え</h2>
                        <div class="consultation-copy">{!! app(App\Services\ConsultationResponseFormatter::class)->toHtml($consultation['response']) !!}</div>
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
                    <div class="history-actions">
                        <a class="text-link" href="{{ route('consultations.index') }}">履歴一覧へ戻る</a>
                        <a class="text-link" href="{{ route('zen') }}">もう一度相談する</a>
                    </div>
                </div>
            </article>
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
