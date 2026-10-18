<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Notifications;

use AlfaSix\Alfakit\Auth\EmailChange\EmailChangeVerification;
use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the new address: clicking the link applies the email change.
 */
class VerifyEmailChange extends Notification
{
    use Queueable;
    use UsesEditableTemplate;

    public const TEMPLATE = 'alfakit.email-change-verify';

    public function __construct(
        public readonly string $url,
        public readonly string $oldEmail,
    ) {}

    /**
     * @return array<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): BaseMailMessage
    {
        return $this->templateMailMessage(Credit::addToMail((new MailMessage)
            ->subject(__('alfakit::email-change.verify_mail.subject'))
            ->line(__('alfakit::email-change.verify_mail.intro'))
            ->action(__('alfakit::email-change.verify_mail.action'), $this->url)
            ->line(trans_choice('alfakit::email-change.verify_mail.expiry', EmailChangeVerification::LIFETIME, ['minutes' => EmailChangeVerification::LIFETIME]))
            ->line(__('alfakit::email-change.verify_mail.ignore'))), Credit::emailLine());
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        return [
            'url' => $this->url,
            'button' => EditableTemplates::button($this->url, (string) __('alfakit::email-change.verify_mail.action')),
            'old_email' => $this->oldEmail,
            'minutes' => (string) EmailChangeVerification::LIFETIME,
            'app' => MailTheme::brand()->name,
        ];
    }
}
