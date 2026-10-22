<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;

class AccountActivityNotification extends Notification
{
    public const ALWAYS_SENT_TYPES = [
        'admin_account_changed',
    ];

    public const ACTIVITY_TYPES = [
        'login' => 'ログイン',
        'google_login' => 'Googleログイン',
        'password_reset' => 'パスワードの再設定',
        'name_changed' => 'お名前の変更',
        'email_changed' => 'メールアドレスの変更',
        'google_linked' => 'Googleアカウントの連携',
        'admin_account_changed' => '管理者によるアカウント情報の変更',
        'account_suspended' => 'アカウントの利用停止',
        'account_deleted' => 'アカウントの削除',
    ];

    public string $type;

    public function __construct(
        public string $activity,
        public ?string $ipAddress,
    ) {
        $type = array_search($activity, self::ACTIVITY_TYPES, true);

        if (! is_string($type)) {
            throw new InvalidArgumentException('Unsupported account activity notification type.');
        }

        $this->type = $type;
    }

    /**
     * @return array<string, string>
     */
    public static function activityTypes(): array
    {
        return self::ACTIVITY_TYPES;
    }

    /**
     * @return array<string, array{label: string, types: array<int, string>}>
     */
    public static function configurableActivityTypeGroups(): array
    {
        $types = self::ACTIVITY_TYPES;

        return [
            'login_group' => ['label' => 'ログイン', 'types' => ['login', 'google_login']],
            'password_reset' => ['label' => $types['password_reset'], 'types' => ['password_reset']],
            'name_changed' => ['label' => $types['name_changed'], 'types' => ['name_changed']],
            'email_changed' => ['label' => $types['email_changed'], 'types' => ['email_changed']],
            'google_linked' => ['label' => $types['google_linked'], 'types' => ['google_linked']],
            'account_status_group' => [
                'label' => 'アカウントの利用停止・削除',
                'types' => ['account_suspended', 'account_deleted'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function configurableActivityTypes(): array
    {
        return array_diff_key(self::ACTIVITY_TYPES, array_flip(self::ALWAYS_SENT_TYPES));
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User && ! $notifiable->receivesSecurityNotification($this->type)) {
            return [];
        }

        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('アカウントのセキュリティ通知'))
            ->greeting(__('アカウントの操作をお知らせします。'))
            ->line(__('次の操作が行われました：').__(self::ACTIVITY_TYPES[$this->type]))
            ->line(__('日時：').now()->format(app()->isLocale('ja') ? 'Y年n月j日 H:i:s' : 'M j, Y H:i:s'));

        if ($this->ipAddress !== null) {
            $mail->line(__('接続元IPアドレス：').$this->ipAddress);
        }

        return $mail
            ->line(__('この操作にお心当たりがない場合は、パスワードを変更し、お問い合わせください。'));
    }

    public function sendToEmail(User $user, string $email): void
    {
        if (! $user->receivesSecurityNotification($this->type)) {
            return;
        }

        NotificationFacade::route('mail', $email)
            ->notify($this->locale($user->preferredLocale()));
    }
}
