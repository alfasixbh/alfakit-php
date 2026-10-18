<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Email\Notifications;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time sign-in code.
 */
class VerifyEmailAuthentication extends Notification
{
    use Queueable;
    use UsesEditableTemplate;

    public const TEMPLATE = 'alfakit.multi-factor-code';

    /**
     * @param  string|null  $name  The user's display name for the greeting (resolved while
     *                             the panel is known, so queued mails greet by name too).
     */
    public function __construct(
        public readonly string $code,
        public readonly int $codeExpiryMinutes,
        public readonly ?string $name = null,
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
            ->subject(__('alfakit::multi-factor.email.mail.subject'))
            ->greeting(filled($this->name)
                ? __('alfakit::multi-factor.email.mail.greeting', ['name' => $this->name])
                : __('alfakit::multi-factor.email.mail.greeting_anonymous'))
            ->line(__('alfakit::multi-factor.email.mail.intro'))
            ->line($this->code)
            ->line(trans_choice('alfakit::multi-factor.email.mail.expiry', $this->codeExpiryMinutes, ['minutes' => $this->codeExpiryMinutes]))
            ->line(__('alfakit::multi-factor.email.mail.ignore'))), Credit::emailLine());
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        return [
            'name' => $this->name ?? '',
            'code' => $this->code,
            'minutes' => (string) $this->codeExpiryMinutes,
            'app' => MailTheme::brand()->name,
        ];
    }
}
