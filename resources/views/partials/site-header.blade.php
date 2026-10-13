<header class="site-header">
    <a class="brand" href="{{ route('home') }}">
        <span class="brand-mark" aria-hidden="true">✦</span>
        <span class="brand-name">月詠</span>
        <span class="brand-caption">ZEN REFLECTIONS</span>
    </a>
    <nav class="account-navigation" aria-label="アカウント">
        @auth
            @if (isset($creditSummary))
                <a
                    class="credit-balance-link"
                    href="{{ route('plans') }}"
                    aria-label="利用可能な相談回数 {{ $creditSummary['unlimited'] ? '無制限' : $creditSummary['total'].'回' }}。料金・プランを見る"
                >
                    <strong>{{ $creditSummary['unlimited'] ? '無制限' : $creditSummary['total'] }}</strong>{{ $creditSummary['unlimited'] ? '' : ' 回' }}
                </a>
            @else
                <a href="{{ route('plans') }}">料金・プラン</a>
            @endif
        @endauth
        @auth
            <div class="account-menu{{ request()->routeIs('plans') && request()->query('account_menu') === 'open' ? ' is-open' : '' }}">
                <button
                    type="button"
                    class="account-menu-trigger"
                    aria-label="アカウントメニューを開く"
                    aria-haspopup="true"
                >
                    <svg class="account-menu-icon" aria-hidden="true" viewBox="0 0 16 16">
                        <path fill="currentColor" d="M3 9.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
                    </svg>
                </button>
                <section class="account-popover" aria-label="アカウントメニュー">
                    <div class="account-profile">
                        <svg class="account-avatar" aria-hidden="true" viewBox="0 0 48 48">
                            <circle cx="24" cy="24" r="23" fill="#78496f"/>
                            <circle cx="24" cy="18" r="8" fill="#fdfafe"/>
                            <path d="M9 41c1.8-8 7.4-12 15-12s13.2 4 15 12" fill="#fdfafe"/>
                        </svg>
                        <div class="account-profile-details">
                            <span class="account-profile-name">{{ auth()->user()->name }}</span>
                            <span class="account-profile-email">{{ auth()->user()->email }}</span>
                        </div>
                    </div>
                    <a class="account-popover-link" href="{{ route('readings.index') }}">相談の記録</a>
                    <a class="account-popover-link" href="{{ route('account.settings.edit') }}">アカウント設定</a>
                    @if (auth()->user()->isAdmin())
                        <a class="account-popover-link" href="{{ route('admin.users.index') }}">ユーザー管理</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="account-popover-link" type="submit">ログアウト</button>
                    </form>
                </section>
            </div>
        @else
            <a href="{{ route('login') }}">ログイン</a>
            <a class="account-register" href="{{ route('register') }}">新規登録</a>
        @endauth
    </nav>
</header>
