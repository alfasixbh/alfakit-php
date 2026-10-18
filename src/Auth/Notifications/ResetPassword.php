<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Notifications;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * Laravel's reset notification pointed at the panel's reset page, in the AlfaKit mail
 * theme and editable (`alfakit.password-reset`).
 */
class ResetPassword extends BaseResetPassword
{
    use UsesEditableTemplate;

    public const TEMPLATE = 'alfakit.password-reset';

    public function __construct(string $token, protected string $url)
    {
        parent::__construct($token);
    }

    protected function resetUrl(mixed $notifiable): string
    {
        return $this->url;
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): BaseMailMessage
    {
        return $this->templateMailMessage(Credit::addToMail((new MailMessage)
            ->subject(Lang::get('Reset your password'))
            ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
            ->action(Lang::get('Reset Password'), $url)
            ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => self::expiry()]))
            ->line(Lang::get('If you did not request a password reset, no further action is required.'))), Credit::emailLine());
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        return [
            'url' => $this->url,
            'button' => EditableTemplates::button($this->url, (string) Lang::get('Reset Password')),
            'minutes' => (string) self::expiry(),
            'app' => MailTheme::brand()->name,
        ];
    }

    private static function expiry(): int
    {
        $broker = config('auth.defaults.passwords');

        return (int) config('auth.passwords.'.(is_string($broker) ? $broker : 'users').'.expire', 60);
    }
}
