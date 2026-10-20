<header class="site-header">
    <a class="brand" href="{{ route('home') }}">
        <span class="brand-mark" aria-hidden="true">✦</span>
        <span class="brand-name">{{ __('心の庵') }}</span>
        <span class="brand-caption">ZEN REFLECTIONS</span>
    </a>
    <nav class="account-navigation" aria-label="{{ __('アカウント') }}">
        <div class="language-navigation" aria-label="Language">
            @if (app()->isLocale('ja'))
                <a href="{{ route('home', ['locale' => 'en']) }}" hreflang="en" lang="en">English</a>
            @else
                <a href="{{ route('home', ['locale' => 'ja']) }}" hreflang="ja" lang="ja">日本語</a>
            @endif
        </div>
        @auth
            @if (isset($creditSummary))
                <a
                    class="credit-balance-link"
                    href="{{ route('plans') }}"
                    aria-label="{{ $creditSummary['unlimited'] ? __('相談回数無制限') : __('利用可能な相談回数').(app()->isLocale('ja') ? ' '.$creditSummary['total'].'回' : ': '.$creditSummary['total'].' '.($creditSummary['total'] === 1 ? 'consultation' : 'consultations')) }}{{ app()->isLocale('ja') ? '。' : '. ' }}{{ __('料金・プランを見る') }}"
                >
                    <strong>{{ $creditSummary['unlimited'] ? __('無制限') : $creditSummary['total'] }}</strong>@if (! $creditSummary['unlimited'])<span class="credit-balance-unit">{{ app()->isLocale('ja') ? ' '.__('回') : ' '.($creditSummary['total'] === 1 ? 'consultation' : 'consultations') }}</span>@endif
                </a>
            @else
                <a href="{{ route('plans') }}">{{ __('料金・プラン') }}</a>
            @endif
        @endauth
        @auth
            <div class="account-menu{{ request()->routeIs('plans') && request()->query('account_menu') === 'open' ? ' is-open' : '' }}">
                <button
                    type="button"
                    class="account-menu-trigger"
                    aria-label="{{ __('アカウントメニューを開く') }}"
                    aria-haspopup="true"
                >
                    <svg class="account-menu-icon" aria-hidden="true" viewBox="0 0 16 16">
                        <path fill="currentColor" d="M3 9.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
                    </svg>
                </button>
                <section class="account-popover" aria-label="{{ __('アカウントメニュー') }}">
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
                    <a class="account-popover-link" href="{{ route('consultations.index') }}">{{ __('相談の記録') }}</a>
                    <a class="account-popover-link" href="{{ route('account.settings.edit') }}">{{ __('アカウント設定') }}</a>
                    @if (auth()->user()->isAdmin())
                        <a class="account-popover-link" href="{{ route('admin.users.index') }}">{{ __('ユーザー管理') }}</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="account-popover-link" type="submit">{{ __('ログアウト') }}</button>
                    </form>
                </section>
            </div>
        @else
            <a href="{{ route('login') }}">{{ __('ログイン') }}</a>
            <a class="account-register" href="{{ route('register') }}">{{ __('新規登録') }}</a>
        @endauth
    </nav>
</header>
