<footer class="site-footer">
    <span>YOUR MOMENT, YOUR MEANING</span>
    <nav class="site-footer-links" aria-label="{{ __('サイト情報') }}">
        <a href="{{ route('about') }}">{{ __('心の庵について') }}</a>
        <a href="{{ route('legal.terms') }}">{{ __('利用規約') }}</a>
        <a href="{{ route('legal.privacy') }}">{{ __('プライバシーポリシー') }}</a>
        <a href="{{ route('contact.create') }}">{{ __('お問い合わせ') }}</a>
        <a href="{{ route('legal.commercial-transactions') }}">{{ __('特定商取引法に基づく表示') }}</a>
        <a href="{{ route('legal.refund-credit-policy') }}">{{ __('返金・クレジットポリシー') }}</a>
    </nav>
</footer>
@vite(['resources/js/app.js'])
