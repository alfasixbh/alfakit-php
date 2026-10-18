<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Notifications;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the old address when an email change is requested, with a link to cancel it.
 */
class NoticeOfEmailChangeRequest extends Notification
{
    use Queueable;
    use UsesEditableTemplate;

    public const TEMPLATE = 'alfakit.email-change-notice';

    public function __construct(
        public readonly string $blockUrl,
        public readonly string $newEmail,
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
            ->subject(__('alfakit::email-change.notice_mail.subject'))
            ->line(__('alfakit::email-change.notice_mail.intro', ['email' => $this->newEmail]))
            ->line(__('alfakit::email-change.notice_mail.no_action'))
            ->action(__('alfakit::email-change.notice_mail.action'), $this->blockUrl)), Credit::emailLine());
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        return [
            'new_email' => $this->newEmail,
            'url' => $this->blockUrl,
            'button' => EditableTemplates::button($this->blockUrl, (string) __('alfakit::email-change.notice_mail.action')),
            'app' => MailTheme::brand()->name,
        ];
    }
}
