<header class="site-header">
    <a class="brand" href="{{ route('home') }}">
        <span class="brand-mark" aria-hidden="true">✦</span>
        <span class="brand-name">月詠</span>
        <span class="brand-caption">TAROT JOURNAL</span>
    </a>
    <nav class="account-navigation" aria-label="アカウント">
        <a href="{{ route('shop.products.index') }}">商品</a>
        <a href="{{ route('shop.cart.index') }}">カート</a>
        @auth
            @if (isset($creditSummary))
                <a
                    class="credit-balance-link"
                    href="{{ route('tarot') }}"
                    aria-label="利用可能な占い回数 {{ $creditSummary['total'] }}回"
                >
                    <strong>{{ $creditSummary['total'] }}</strong> 回
                </a>
            @endif
        @endauth
        @auth
            <div class="account-menu">
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
                    <a class="account-popover-link" href="{{ route('readings.index') }}">占い履歴</a>
                    <a class="account-popover-link" href="{{ route('account.settings.edit') }}">アカウント設定</a>
                    <a class="account-popover-link" href="{{ route('account.orders.index') }}">注文履歴</a>
                    @if (auth()->user()->isAdmin())
                        <a class="account-popover-link" href="{{ route('admin.users.index') }}">ユーザー管理</a>
                        <a class="account-popover-link" href="{{ route('admin.products.index') }}">商品管理</a>
                        <a class="account-popover-link" href="{{ route('admin.categories.index') }}">カテゴリ管理</a>
                        <a class="account-popover-link" href="{{ route('admin.orders.index') }}">注文管理</a>
                        <a class="account-popover-link" href="{{ route('admin.shipping.index') }}">送料・税設定</a>
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
