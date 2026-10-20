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
            ->subject('アカウントのセキュリティ通知')
            ->greeting('アカウントの操作をお知らせします。')
            ->line('次の操作が行われました：'.$this->activity)
            ->line('日時：'.now()->format('Y年n月j日 H:i:s'));

        if ($this->ipAddress !== null) {
            $mail->line('接続元IPアドレス：'.$this->ipAddress);
        }

        return $mail
            ->line('この操作にお心当たりがない場合は、パスワードを変更し、お問い合わせください。');
    }
}
