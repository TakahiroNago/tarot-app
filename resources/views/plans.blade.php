<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>料金・プラン | 月詠</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content history-content">
            <section class="history-panel plans-panel">
                <p class="eyebrow">MEMBERSHIP</p>
                <h1>料金・プラン</h1>
                <p class="page-description">決済はStripeでの提供を予定しています。現在はクレジットの購入・プラン登録を受け付けていません。</p>

                <div class="plan-grid">
                    <section class="plan-card">
                        <h2>クレジット購入</h2>
                        <p class="plan-price">10回 <strong>100円</strong></p>
                        <p class="plan-price">50回 <strong>400円</strong></p>
                        <p class="plan-price">100回 <strong>500円</strong></p>
                    </section>
                    <section class="plan-card">
                        <h2>ライトプラン</h2>
                        <p class="plan-price"><strong>月額280円</strong></p>
                        <p>・1日5回（最大150回まで引継ぎ）</p>
                        <p>・過去3か月のリーディング履歴</p>
                    </section>
                    <section class="plan-card">
                        <h2>プレミアムプラン</h2>
                        <p class="plan-price"><strong>月額1,980円</strong></p>
                        <p>・1日10回（最大300回まで引継ぎ）</p>
                        <p>・すべてのリーディング履歴</p>
                        <p>・過去のリーディング結果をもとにした、さらに深いリーディング結果</p>
                    </section>
                </div>

                @guest
                    <p class="form-notice credit-notice">
                        無料会員は毎日3回、直近3か月分の履歴を最大50件まで利用できます。
                        <a href="{{ route('register') }}">無料会員登録</a>
                    </p>
                @endguest
                <a class="text-link" href="{{ route('tarot') }}">占いに戻る</a>
            </section>
        </main>
        <footer class="site-footer">YOUR MOMENT, YOUR MEANING</footer>
    </div>
</body>
</html>
