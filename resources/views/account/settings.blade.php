<div>
    <!-- Simplicity is the ultimate sophistication. - Leonardo da Vinci -->
</div>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('アカウント設定') }} | {{ __('心の庵') }} ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel account-settings-panel">
                <p class="eyebrow">ACCOUNT SETTINGS</p>
                <h1>{{ __('アカウント設定') }}</h1>

                @if (session('status'))
                    <p class="form-notice" data-auto-dismiss role="status">{{ __(session('status')) }}</p>
                @endif
                <div class="toast-region" data-account-settings-notice data-error-message="{{ __('更新に失敗しました。時間をおいて再度お試しください。') }}" aria-live="polite"></div>
                @if ($errors->has('account'))
                    <p class="form-error" role="alert">{{ __($errors->first('account')) }}</p>
                @endif
                @if ($errors->has('billing'))
                    <p class="form-error" role="alert">{{ __($errors->first('billing')) }}</p>
                @endif

                <section class="account-settings-section">
                    <h2>{{ __('登録情報') }}</h2>
                    <form class="question-form" method="POST" action="{{ route('account.settings.update') }}" data-account-settings-form>
                        @csrf
                        @method('PUT')
                        <label class="question-label" for="name">{{ __('お名前') }}</label>
                        <input class="question-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="255">
                        @error('name')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                        <button class="primary-action" type="submit">{{ __('お名前を変更') }}</button>
                    </form>

                    <form class="question-form" method="POST" action="{{ route('account.settings.email.update') }}" data-account-settings-form>
                        @csrf
                        @method('PUT')
                        <label class="question-label" for="email">{{ __('メールアドレス') }}</label>
                        <input class="question-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" autocomplete="email" required maxlength="255">
                        @error('email')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror
                        <p class="settings-note">{{ __('入力したメールアドレスに確認メールを送信します。メール内のリンクを開くまで登録情報は変更されません。') }}</p>
                        @if ($user->google_id !== null)
                            <p class="settings-note">{{ __('Googleアカウントでログインしている場合、メールアドレスを変更してもGoogleログインには元の連携アカウントを使用します。') }}</p>
                        @endif

                        <button class="primary-action" type="submit">{{ __('メールアドレスを変更') }}</button>
                    </form>
                </section>

                <section class="account-settings-section">
                    <h2>{{ __('セキュリティ通知') }}</h2>
                    <p class="settings-note">{{ __('アカウントに関するセキュリティ通知をメールで受け取るか、通知の種類ごとに設定できます。') }}</p>
                    <form class="question-form" method="POST" action="{{ route('account.settings.security-notifications.update') }}" data-account-settings-form>
                        @csrf
                        @method('PUT')
                        @php($selectedNotificationTypes = (array) old('types', $user->security_notification_types ?? array_keys(\App\Notifications\AccountActivityNotification::configurableActivityTypes())))
                        @php($selectedNotificationTypes = collect($selectedNotificationTypes)->flatMap(fn (string $type): array => $notificationTypeGroups[$type]['types'] ?? [$type])->all())
                        @php($securityNotificationsEnabled = old('enabled', $user->security_notifications_enabled ?? true))
                        <label class="remember-option security-notification-switch">
                            <input name="enabled" type="checkbox" value="1" data-security-notifications-enabled @checked($securityNotificationsEnabled)>
                            {{ __('セキュリティ通知を受け取る') }}
                        </label>
                        @error('enabled')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                        <fieldset data-security-notification-types @disabled(! $securityNotificationsEnabled)>
                            <legend class="question-label">{{ __('通知の種類') }}</legend>
                            @foreach ($notificationTypeGroups as $group => $notificationTypeGroup)
                                @php($types = $notificationTypeGroup['types'])
                                @php($groupEnabled = count(array_intersect($types, $selectedNotificationTypes)) > 0)
                                <label class="remember-option security-notification-switch">
                                    <input name="types[]" type="checkbox" value="{{ $group }}" @checked($groupEnabled)>
                                    {{ __($notificationTypeGroup['label']) }}
                                </label>
                            @endforeach
                        </fieldset>
                        @error('types')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror
                        @error('types.*')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror

                        <button class="primary-action" type="submit" data-security-notifications-save disabled>{{ __('通知設定を保存') }}</button>
                    </form>
                </section>

                <section class="account-settings-section">
                    <h2>{{ __('契約・お支払い') }}</h2>
                    <p class="form-notice" role="status">
                        {{ __('現在のプラン') }}{{ app()->isLocale('ja') ? '：' : ': ' }}{{ $user->plan === \App\Models\User::PLAN_PREMIUM ? __('プレミアム') : ($user->plan === \App\Models\User::PLAN_LITE ? __('ライト') : __('無料')) }}
                        @if ($user->stripe_cancel_at_period_end && $user->stripe_current_period_end)
                            <br>{{ app()->isLocale('ja') ? $user->stripe_current_period_end->format('Y年n月j日').'に終了予定' : __('契約終了予定: :date', ['date' => $user->stripe_current_period_end->format('M j, Y')]) }}
                        @endif
                    </p>
                    @if (request('billing') === 'returned')
                        <p class="settings-note" role="status">{{ __('Stripeの管理画面から戻りました。契約内容の変更はWebhookで反映されるため、反映まで時間がかかる場合があります。最新の状態を確認するには、このページを再読み込みしてください。') }}</p>
                    @endif
                    @if ($user->stripe_customer_id !== null)
                        <form method="POST" action="{{ route('billing.portal') }}">
                            @csrf
                            @if ($user->stripe_subscription_id !== null)
                                <button class="primary-action" type="submit">{{ __('契約・お支払いを管理') }}</button>
                            @else
                                <button class="text-link" type="submit">{{ __('お支払い情報を管理（Stripe）') }}</button>
                            @endif
                        </form>
                    @endif
                    @if ($user->stripe_scheduled_plan === \App\Models\User::PLAN_LITE && $user->stripe_current_period_end)
                        <p role="status">
                            {{ app()->isLocale('ja') ? $user->stripe_current_period_end->format('Y年n月j日').'にライトプランへ切り替わる予定です。' : __('ライトプランへの切り替え予定日').' '.$user->stripe_current_period_end->format('M j, Y') }}
                        </p>
                        <form method="POST" action="{{ route('billing.plan.cancel') }}">
                            @csrf
                            @method('DELETE')
                            <button class="secondary-action" type="submit">{{ __('予約を取り消す') }}</button>
                        </form>
                    @elseif ($user->stripe_subscription_id !== null && $user->plan === \App\Models\User::PLAN_LITE && ! $user->stripe_cancel_at_period_end)
                        <p class="settings-note">{{ __('プレミアムへの変更は即時適用され、現在の期間の未使用分は日割り計算で今回の請求に充当されます。') }}</p>
                        <form method="POST" action="{{ route('billing.plan.change') }}">
                            @csrf
                            <input type="hidden" name="plan" value="premium">
                            <button class="primary-action" type="submit">{{ __('プレミアムへ変更') }}</button>
                        </form>
                    @elseif ($user->stripe_subscription_id !== null && $user->plan === \App\Models\User::PLAN_PREMIUM && ! $user->stripe_cancel_at_period_end)
                        <p class="settings-note">{{ __('ライトへの変更は予約として登録され、現在のプレミアム期間終了後に適用されます。') }}</p>
                        <form method="POST" action="{{ route('billing.plan.change') }}">
                            @csrf
                            <input type="hidden" name="plan" value="lite">
                            <button class="secondary-action" type="submit">{{ __('ライトへ変更') }}</button>
                        </form>
                    @endif
                </section>

                <section class="account-settings-section account-deletion-section">
                    <h2>{{ __('アカウント削除') }}</h2>
                    <p>{{ __('アカウントを削除すると、相談の記録や保有クレジットを含む登録情報が削除され、元に戻すことはできません。有料プランをご利用中の場合は、関連する契約も解約されます。') }}</p>
                    @if ($user->google_id !== null)
                        <p class="settings-note">{{ __('削除には現在のパスワードが必要です。Googleログインのみをご利用の場合は、一度ログアウトし、ログイン画面の「パスワードをお忘れですか？」からパスワードを設定して、再度ログインしてください。') }}</p>
                    @endif
                    <form class="question-form" method="POST" action="{{ route('account.settings.destroy') }}" data-delete-account-form data-verify-url="{{ route('account.settings.verify-password') }}">
                        @csrf
                        @method('DELETE')
                        <label class="question-label" for="delete-password">{{ __('現在のパスワード') }}</label>
                        <input class="question-input" id="delete-password" name="password" type="password" autocomplete="current-password" required>
                        @error('password')<p class="form-error" role="alert">{{ __($message) }}</p>@enderror
                        <button class="danger-action" type="submit">{{ __('アカウントを削除する') }}</button>
                    </form>
                </section>
                <a class="text-link" href="{{ route('zen') }}">→ {{ __('相談に戻る') }}</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
    <dialog class="confirm-dialog" data-delete-account-dialog aria-labelledby="delete-account-dialog-title">
        <h2 id="delete-account-dialog-title">{{ __('アカウントを削除する') }}</h2>
        <p>{{ __('アカウントと関連データを削除します。この操作は取り消せません。削除しますか？') }}</p>
        <div class="confirm-dialog-actions">
            <button class="secondary-action" type="button" data-delete-account-cancel>{{ __('キャンセル') }}</button>
            <button class="danger-action" type="button" data-delete-account-confirm>{{ __('アカウントを削除する') }}</button>
        </div>
    </dialog>
    <script>
        const securityNotificationsToggle = document.querySelector('[data-security-notifications-enabled]');
        const securityNotificationTypes = document.querySelector('[data-security-notification-types]');
        const securityNotificationSwitches = document.querySelectorAll(
            'input[name="enabled"], input[name="types[]"]',
        );
        const securityNotificationsSaveButton = document.querySelector('[data-security-notifications-save]');

        securityNotificationsToggle.addEventListener('change', () => {
            securityNotificationTypes.disabled = !securityNotificationsToggle.checked;
        });

        securityNotificationSwitches.forEach((securityNotificationSwitch) => {
            securityNotificationSwitch.addEventListener('change', () => {
                securityNotificationsSaveButton.disabled = false;
            });
        });
    </script>
</body>
</html>
