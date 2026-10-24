<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('禅師からのことば') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="result-panel result-panel-consultation">
                <div class="result-copy">
                    <p class="eyebrow result-eyebrow">WORDS FOR YOU</p>
                    <h1 class="result-heading result-title">{{ __('禅師からのことば') }}</h1>

                    <section class="result-section">
                        <h2 class="section-label">{{ __('あなたのお悩み') }}</h2>
                        <p class="question-quote">{{ $concern }}</p>
                    </section>

                    <section class="result-section">
                        <h2 class="section-label">{{ __('お答え') }}</h2>
                        <div class="consultation-copy">{!! app(App\Services\ConsultationResponseFormatter::class)->toHtml($response) !!}</div>
                    </section>

                    <section class="consultation-share" aria-labelledby="consultation-share-heading">
                        <h2 class="section-label" id="consultation-share-heading">{{ __('ことばをシェア') }}</h2>
                        <p class="consultation-share-note">{{ __('公開用URLにはメールアドレスやユーザーIDは含まれません。URLを知っている人は結果を閲覧できます（有効期限30日）。') }}</p>

                        <div class="consultation-share-actions">
                            <button class="consultation-share-button" type="button" data-share-link aria-label="{{ __('リンクでシェア') }}">{{ __('シェア') }}</button>
                        </div>
                        <div class="consultation-share-url">
                            <input
                                class="consultation-share-url-input"
                                id="consultation-share-url"
                                type="url"
                                value="{{ $shareUrl }}"
                                readonly
                                aria-label="{{ __('公開用相談URL') }}"
                            >
                        </div>
                    </section>

                    @if ($creditsRemaining === null)
                        <p class="credit-summary">{{ __('相談回数は無制限です') }}</p>
                    @elseif ($creditsRemaining === 0)
                        @if ($isGuest)
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}">
                                {{ __('無料体験を使い切りました。無料会員登録すると毎日1回相談できます。') }}{{ app()->isLocale('ja') ? '→ ' : ' → ' }}{{ __('会員登録はこちら') }}
                            </a>
                        @else
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}">
                                {{ __('本日利用可能なクレジットを使い切りました。') }}{{ app()->isLocale('ja') ? '→ ' : ' → ' }}{{ __('有料プランを見る') }}
                            </a>
                        @endif
                    @else
                        <p class="credit-summary">{{ app()->isLocale('ja') ? __('相談残り').' '.$creditsRemaining.__('回') : __('相談残り: :count', ['count' => $creditsRemaining]) }}</p>
                    @endif

                    <a class="text-link" href="{{ route('zen') }}">{{ __('もう一度相談する') }}</a>
                </div>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
    <script>
        const shareUrlInput = document.querySelector('#consultation-share-url');
        const showShareStatus = (message, isError = false) => window.showToast(message, isError);

        async function copyConsultationUrl() {
            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(shareUrlInput.value);
                    showShareStatus(@json(__('公開用URLをコピーしました。')));
                    return true;
                } catch {
                }
            }

            shareUrlInput.focus();
            shareUrlInput.select();
            const copied = document.execCommand('copy');
            showShareStatus(copied
                ? @json(__('公開用URLをコピーしました。'))
                : @json(__('URLを選択しました。コピーしてご利用ください。')));

            return copied;
        }

        shareUrlInput.addEventListener('click', async () => {
            try {
                await copyConsultationUrl();
            } catch (error) {
                showShareStatus(@json(__('コピーできませんでした。上記URLを手動でコピーしてください。')), true);
            }
        });

        document.querySelector('[data-share-link]').addEventListener('click', async () => {
            try {
                if (navigator.share) {
                    await navigator.share({ url: shareUrlInput.value });
                    return;
                }

                const copied = await copyConsultationUrl();
                showShareStatus(copied
                    ? @json(__('公開用URLをコピーしました。共有先に貼り付けてください。'))
                    : @json(__('URLを選択しました。コピーして共有先に貼り付けてください。')));
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                showShareStatus(@json(__('共有できませんでした。上記URLを手動でコピーしてください。')), true);
            }
        });
    </script>
</body>
</html>
