<header class="site-header">
    <a class="brand" href="{{ route('home') }}">
        <span class="brand-mark" aria-hidden="true">✦</span>
        <span class="brand-name">月詠</span>
        <span class="brand-caption">TAROT JOURNAL</span>
    </a>
    <nav class="account-navigation" aria-label="アカウント">
        <a href="{{ route('readings.index') }}">占い履歴</a>
        @auth
            <span class="account-name">{{ auth()->user()->name }}</span>
            @if (auth()->user()->isAdmin())
                <a href="{{ route('admin.users.index') }}">ユーザー管理</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">ログアウト</button>
            </form>
        @else
            <a href="{{ route('login') }}">ログイン</a>
            <a class="account-register" href="{{ route('register') }}">新規登録</a>
        @endauth
    </nav>
</header>