<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email with a signed link to register (invitation-only registration).
 */
class RegistrationInvitation extends Notification
{
    use Queueable;

    public function __construct(public string $url, public string $appName, public int $days) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('alfakit::auth.invitation.mail.subject', ['app' => $this->appName]))
            ->greeting(__('alfakit::auth.invitation.mail.greeting'))
            ->line(__('alfakit::auth.invitation.mail.intro', ['app' => $this->appName]))
            ->action(__('alfakit::auth.invitation.mail.action'), $this->url)
            ->line(trans_choice('alfakit::auth.invitation.mail.expiry', $this->days, ['days' => $this->days]));
    }
}
