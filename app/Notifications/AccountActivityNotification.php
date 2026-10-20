<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountActivityNotification extends Notification
{
    public function __construct(
        public string $activity,
        public ?string $ipAddress,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
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
            ->line(__('次の操作が行われました：').__($this->activity))
            ->line(__('日時：').now()->format(app()->isLocale('ja') ? 'Y年n月j日 H:i:s' : 'M j, Y H:i:s'));

        if ($this->ipAddress !== null) {
            $mail->line(__('接続元IPアドレス：').$this->ipAddress);
        }

        return $mail
            ->line(__('この操作にお心当たりがない場合は、パスワードを変更し、お問い合わせください。'));
    }
}
