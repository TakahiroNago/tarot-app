<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountEmailChangeVerification extends Notification
{
    public function __construct(public string $verificationUrl) {}

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
        return (new MailMessage)
            ->subject('メールアドレス変更の確認')
            ->greeting('メールアドレス変更のご確認')
            ->line('このメールアドレスへの変更を完了するには、以下のボタンを押してください。')
            ->action('メールアドレスを確認する', $this->verificationUrl)
            ->line('このリンクは60分後に期限切れになります。お心当たりがない場合は、このメールを破棄してください。');
    }
}
