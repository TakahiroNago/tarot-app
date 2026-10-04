<header class="site-header">
    @php
        $hasSessionReadings = collect(session('tarot_readings', []))->contains(
            fn ($reading) => is_array($reading)
                && isset($reading['question'], $reading['card'], $reading['interpretation'])
                && is_string($reading['question'])
                && is_string($reading['card'])
                && is_string($reading['interpretation']),
        );
    @endphp
    <a class="brand" href="{{ route('home') }}">
        <span class="brand-mark" aria-hidden="true">✦</span>
        <span class="brand-name">月詠</span>
        <span class="brand-caption">TAROT JOURNAL</span>
    </a>
    <nav class="account-navigation" aria-label="アカウント">
        @guest
            @if ($hasSessionReadings)
                <a href="{{ route('readings.index') }}">占い履歴</a>
            @endif
        @endguest
        @auth
            @if (isset($creditSummary))
                <a
                    class="credit-balance-link"
                    href="{{ route('plans') }}"
                    aria-label="利用可能なクレジット合計 {{ $creditSummary['total'] }}回。料金・プランを見る"
                >
                    残り <strong>{{ $creditSummary['total'] }}</strong> 回
                </a>
            @else
                <a href="{{ route('plans') }}">料金・プラン</a>
            @endif
        @else
            <a href="{{ route('plans') }}">料金・プラン</a>
        @endauth
        @auth
            <div class="account-menu{{ request()->routeIs('plans') && request()->query('account_menu') === 'open' ? ' is-open' : '' }}">
                <a
                    class="account-menu-trigger"
                    href="{{ route('plans', ['account_menu' => 'open']) }}"
                    aria-label="アカウントメニューを開いて料金・プランを表示"
                    aria-haspopup="true"
                >
                    <svg aria-hidden="true" viewBox="0 0 48 48">
                        <circle cx="24" cy="24" r="23" fill="#315c49"/>
                        <circle cx="24" cy="18" r="8" fill="#fbfcf7"/>
                        <path d="M9 41c1.8-8 7.4-12 15-12s13.2 4 15 12" fill="#fbfcf7"/>
                    </svg>
                </a>
                <section class="account-popover" aria-label="アカウントメニュー">
                    <div class="account-profile">
                        <svg class="account-avatar" aria-hidden="true" viewBox="0 0 48 48">
                            <circle cx="24" cy="24" r="23" fill="#315c49"/>
                            <circle cx="24" cy="18" r="8" fill="#fbfcf7"/>
                            <path d="M9 41c1.8-8 7.4-12 15-12s13.2 4 15 12" fill="#fbfcf7"/>
                        </svg>
                        <div class="account-profile-details">
                            <span class="account-profile-name">{{ auth()->user()->name }}</span>
                            <span class="account-profile-email">{{ auth()->user()->email }}</span>
                        </div>
                    </div>
                    <a class="account-popover-link" href="{{ route('readings.index') }}">占い履歴</a>
                    @if (auth()->user()->isAdmin())
                        <a class="account-popover-link" href="{{ route('admin.users.index') }}">ユーザー管理</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="account-popover-link" type="submit">ログアウト</button>
                    </form>
                    <a class="account-popover-close" href="{{ route('plans') }}">閉じる</a>
                </section>
            </div>
        @else
            <a href="{{ route('login') }}">ログイン</a>
            <a class="account-register" href="{{ route('register') }}">新規登録</a>
        @endauth
    </nav>
</header>