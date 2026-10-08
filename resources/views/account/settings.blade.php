<div>
    <!-- Simplicity is the ultimate sophistication. - Leonardo da Vinci -->
</div>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>アカウント設定 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel account-settings-panel">
                <p class="eyebrow">ACCOUNT SETTINGS</p>
                <h1>アカウント設定</h1>

                @if (session('status'))
                    <p class="form-notice" role="status">{{ session('status') }}</p>
                @endif
                @if ($errors->has('account'))
                    <p class="form-error" role="alert">{{ $errors->first('account') }}</p>
                @endif
                @if ($errors->has('billing'))
                    <p class="form-error" role="alert">{{ $errors->first('billing') }}</p>
                @endif

                <section class="account-settings-section">
                    <h2>登録情報</h2>
                    <form class="question-form" method="POST" action="{{ route('account.settings.update') }}">
                        @csrf
                        @method('PUT')
                        <label class="question-label" for="name">お名前</label>
                        <input class="question-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="255">
                        @error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror

                        <label class="question-label" for="email">メールアドレス</label>
                        <input class="question-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" autocomplete="email" required maxlength="255">
                        @error('email')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        @if ($user->google_id !== null)
                            <p class="settings-note">Googleアカウントでログインしている場合、メールアドレスを変更してもGoogleログインには元の連携アカウントを使用します。</p>
                        @endif

                        <button class="primary-action" type="submit">登録情報を保存</button>
                    </form>
                </section>

                <section class="account-settings-section">
                    <h2>契約・お支払い</h2>
                    <p class="form-notice" role="status">
                        現在のプラン：{{ $user->plan === \App\Models\User::PLAN_PREMIUM ? 'プレミアム' : ($user->plan === \App\Models\User::PLAN_LITE ? 'ライト' : '無料') }}
                        @if ($user->stripe_cancel_at_period_end && $user->stripe_current_period_end)
                            <br>{{ $user->stripe_current_period_end->format('Y年n月j日') }}に終了予定
                        @endif
                    </p>
                    @if (request('billing') === 'returned')
                        <p class="settings-note" role="status">Stripeの管理画面から戻りました。契約内容の変更はWebhookで反映されるため、反映まで時間がかかる場合があります。最新の状態を確認するには、このページを再読み込みしてください。</p>
                    @endif
                    @if ($user->stripe_customer_id !== null)
                        <form method="POST" action="{{ route('billing.portal') }}">
                            @csrf
                            @if ($user->stripe_subscription_id !== null)
                                <button class="primary-action" type="submit">契約・お支払いを管理</button>
                            @else
                                <button class="text-link" type="submit">お支払い情報を管理</button>
                            @endif
                        </form>
                    @endif
                    @if ($user->stripe_scheduled_plan === \App\Models\User::PLAN_LITE && $user->stripe_current_period_end)
                        <p role="status">
                            {{ $user->stripe_current_period_end->format('Y年n月j日') }}にライトプランへ切り替わる予定です。
                        </p>
                        <form method="POST" action="{{ route('billing.plan.cancel') }}">
                            @csrf
                            @method('DELETE')
                            <button class="secondary-action" type="submit">予約を取り消す</button>
                        </form>
                    @elseif ($user->stripe_subscription_id !== null && $user->plan === \App\Models\User::PLAN_LITE && ! $user->stripe_cancel_at_period_end)
                        <p class="settings-note">プレミアムへの変更は即時適用され、現在の期間の未使用分は日割り計算で今回の請求に充当されます。</p>
                        <form method="POST" action="{{ route('billing.plan.change') }}">
                            @csrf
                            <input type="hidden" name="plan" value="premium">
                            <button class="primary-action" type="submit">プレミアムへ変更</button>
                        </form>
                    @elseif ($user->stripe_subscription_id !== null && $user->plan === \App\Models\User::PLAN_PREMIUM && ! $user->stripe_cancel_at_period_end)
                        <p class="settings-note">ライトへの変更は予約として登録され、現在のプレミアム期間終了後に適用されます。</p>
                        <form method="POST" action="{{ route('billing.plan.change') }}">
                            @csrf
                            <input type="hidden" name="plan" value="lite">
                            <button class="secondary-action" type="submit">ライトへ変更</button>
                        </form>
                    @endif
                </section>

                <section class="account-settings-section account-deletion-section">
                    <h2>アカウント削除</h2>
                    <p>アカウントを削除すると、占い履歴や保有クレジットを含む登録情報が削除され、元に戻すことはできません。有料プランをご利用中の場合は、関連する契約も解約されます。</p>
                    @if ($user->google_id !== null)
                        <p class="settings-note">削除には現在のパスワードが必要です。Googleログインのみをご利用の場合は、一度ログアウトし、ログイン画面の「パスワードをお忘れですか？」からパスワードを設定して、再度ログインしてください。</p>
                    @endif
                    <form class="question-form" method="POST" action="{{ route('account.settings.destroy') }}" onsubmit="return confirm('アカウントと関連データを削除します。この操作は取り消せません。削除しますか？');">
                        @csrf
                        @method('DELETE')
                        <label class="question-label" for="delete-password">現在のパスワード</label>
                        <input class="question-input" id="delete-password" name="password" type="password" autocomplete="current-password" required>
                        @error('password')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        <button class="danger-action" type="submit">アカウントを削除する</button>
                    </form>
                </section>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
