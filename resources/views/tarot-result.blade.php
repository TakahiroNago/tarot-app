<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>禅師からのことば | 月詠 禅のことば</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')

        <main class="page-content">
            <section class="result-panel result-panel-no-card">
                <div class="result-copy">
                    <p class="eyebrow result-eyebrow">WORDS FOR YOU</p>
                    <h1 class="result-heading result-title">禅師からのことば</h1>

                    <section class="result-section">
                        <h2 class="section-label">あなたのお悩み</h2>
                        <p class="question-quote">{{ $question }}</p>
                    </section>

                    <section class="result-section">
                        <h2 class="section-label">お答え</h2>
                        <p class="reading-copy">{{ $interpretation }}</p>
                    </section>

                    <section class="reading-share" aria-labelledby="reading-share-heading">
                        <h2 class="section-label" id="reading-share-heading">ことばをシェア</h2>
                        <p class="reading-share-note">公開用URLにはメールアドレスやユーザーIDは含まれません。URLを知っている人は結果を閲覧できます（有効期限30日）。</p>

                        <div class="reading-share-links" aria-label="シェア先">
                            <a class="reading-share-link reading-share-link-x" href="https://twitter.com/intent/tweet?url={{ urlencode($shareUrl) }}" target="_blank" rel="noopener noreferrer" aria-label="Xでシェア">
                                <svg aria-hidden="true" viewBox="0 0 16 16" fill="currentColor"><path d="M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z"/></svg>
                            </a>
                            <a class="reading-share-link reading-share-link-line" href="https://social-plugins.line.me/lineit/share?url={{ urlencode($shareUrl) }}" target="_blank" rel="noopener noreferrer" aria-label="LINEでシェア">
                                <svg aria-hidden="true" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0c4.411 0 8 2.912 8 6.492 0 1.433-.555 2.723-1.715 3.994-1.678 1.932-5.431 4.285-6.285 4.645-.83.35-.734-.197-.696-.413l.003-.018.114-.685c.027-.204.055-.521-.026-.723-.09-.223-.444-.339-.704-.395C2.846 12.39 0 9.701 0 6.492 0 2.912 3.59 0 8 0M5.022 7.686H3.497V4.918a.156.156 0 0 0-.155-.156H2.78a.156.156 0 0 0-.156.156v3.486c0 .041.017.08.044.107v.001l.002.002.002.002a.15.15 0 0 0 .108.043h2.242c.086 0 .155-.07.155-.156v-.56a.156.156 0 0 0-.155-.157m.791-2.924a.156.156 0 0 0-.156.156v3.486c0 .086.07.155.156.155h.562c.086 0 .155-.07.155-.155V4.918a.156.156 0 0 0-.155-.156zm3.863 0a.156.156 0 0 0-.156.156v2.07L7.923 4.832l-.013-.015v-.001l-.01-.01-.003-.003-.011-.009h-.001L7.88 4.79l-.003-.002-.005-.003-.008-.005h-.002l-.003-.002-.01-.004-.004-.002-.01-.003h-.002l-.003-.001-.009-.002h-.006l-.003-.001h-.004l-.002-.001h-.574a.156.156 0 0 0-.156.155v3.486c0 .086.07.155.156.155h.56c.087 0 .157-.07.157-.155v-2.07l1.6 2.16a.2.2 0 0 0 .039.038l.001.001.01.006.004.002.008.004.007.003.005.002.01.003h.003a.2.2 0 0 0 .04.006h.56c.087 0 .157-.07.157-.155V4.918a.156.156 0 0 0-.156-.156zm3.815.717v-.56a.156.156 0 0 0-.155-.157h-2.242a.16.16 0 0 0-.108.044h-.001l-.001.002-.002.003a.16.16 0 0 0-.044.107v3.486c0 .041.017.08.044.107l.002.003.002.002a.16.16 0 0 0 .108.043h2.242c.086 0 .155-.07.155-.156v-.56a.156.156 0 0 0-.155-.157H11.81v-.589h1.525c.086 0 .155-.07.155-.156v-.56a.156.156 0 0 0-.155-.157H11.81v-.589h1.525c.086 0 .155-.07.155-.156Z"/></svg>
                            </a>
                            <button class="reading-share-button reading-share-link-instagram" type="button" data-share-to-instagram aria-label="Instagramでシェア">
                                <svg aria-hidden="true" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.9 3.9 0 0 0-1.417.923A3.9 3.9 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.851-.04 1.434-.174 1.943-.372a3.9 3.9 0 0 0 1.416-.923c.445-.445.718-.891.923-1.417.197-.509.332-1.09.372-1.942C15.99 10.445 16 10.173 16 8s-.01-2.445-.048-3.299c-.04-.851-.175-1.433-.372-1.941a3.9 3.9 0 0 0-.923-1.417A3.9 3.9 0 0 0 13.24.42c-.51-.198-1.092-.333-1.943-.372C10.443.01 10.172 0 7.998 0zm-.717 1.442h.718c2.136 0 2.389.007 3.232.046.78.035 1.204.166 1.486.275.373.145.64.319.92.599s.453.546.598.92c.11.281.24.705.275 1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.5 2.5 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-2.388-.046-3.233s.008-2.388.046-3.231c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92s.546-.453.92-.598c.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92m-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217m0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334"/></svg>
                            </button>
                            <a class="reading-share-link reading-share-link-facebook" href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}" target="_blank" rel="noopener noreferrer" aria-label="Facebookでシェア">
                                <svg aria-hidden="true" viewBox="0 0 16 16" fill="currentColor"><path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58-.001-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951"/></svg>
                            </a>
                        </div>
                        <div class="reading-share-url">
                            <input
                                class="reading-share-url-input"
                                id="reading-share-url"
                                type="url"
                                value="{{ $shareUrl }}"
                                readonly
                                aria-label="公開用リーディングURL"
                            >
                            <button class="reading-share-button" type="button" data-copy-reading-url>URLをコピー</button>
                        </div>
                        <p class="reading-share-status" role="status" aria-live="polite" data-reading-share-status></p>
                    </section>

                    @if ($creditsRemaining === null)
                        <p class="credit-summary">相談回数は無制限です</p>
                    @elseif ($creditsRemaining === 0)
                        @if ($isGuest)
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('register') }}">
                                無料体験を使い切りました。無料会員登録すると毎日3回相談できます。→ 会員登録はこちら
                            </a>
                        @else
                            <a class="form-notice credit-notice credit-notice-link" href="{{ route('plans') }}">
                                本日利用可能なクレジットを使い切りました。→ 有料プランを見る
                            </a>
                        @endif
                    @else
                        <p class="credit-summary">相談残り {{ $creditsRemaining }}回</p>
                    @endif

                    <a class="text-link" href="{{ route('tarot') }}">もう一度相談する</a>
                </div>
            </section>
        </main>

        @include('partials.site-footer')
    </div>
    <script>
        const shareUrlInput = document.querySelector('#reading-share-url');
        const shareStatus = document.querySelector('[data-reading-share-status]');

        async function copyReadingUrl() {
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

        document.querySelector('[data-copy-reading-url]').addEventListener('click', async () => {
            try {
                await copyReadingUrl();
            } catch {
                shareStatus.textContent = 'コピーに失敗しました。上記URLを手動でコピーしてください。';
            }
        });

        document.querySelector('[data-share-to-instagram]').addEventListener('click', async () => {
            try {
                if (navigator.share) {
                    await navigator.share({ url: shareUrlInput.value });
                    return;
                }

                const copied = await copyReadingUrl();
                shareStatus.textContent = copied
                    ? '公開用URLをコピーしました。Instagramの投稿やストーリーズに貼り付けてください。'
                    : 'URLを選択しました。コピーしてInstagramの投稿やストーリーズに貼り付けてください。';
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
