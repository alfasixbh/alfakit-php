<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use AlfaSix\Alfakit\Auth\EmailChange\EmailChangeVerification;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use AlfaSix\Alfakit\Auth\Notifications\NoticeOfEmailChangeRequest;
use AlfaSix\Alfakit\Auth\Notifications\ResetPassword;
use AlfaSix\Alfakit\Auth\Notifications\VerifyEmailChange;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * The mails of the AlfaKit core, registered for the mail preview page and editable
 * templates, and Laravel's email verification mail in the AlfaKit theme.
 *
 * @internal
 */
final class CoreMails
{
    public const EMAIL_VERIFICATION = 'alfakit.email-verification';

    public const USERS_INVITATION = 'alfakit.users-invitation';

    public static function register(): void
    {
        $group = static fn (): string => (string) __('alfakit::mail.groups.authentication');

        Mails::register(
            MailDefinition::make(VerifyEmailAuthentication::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit::mail.templates.multi_factor_code'))
                ->group($group)
                ->preview(static fn (): VerifyEmailAuthentication => new VerifyEmailAuthentication('428913', 10, 'Ada Lovelace'))
                ->editable(
                    mergeTags: static fn (): array => self::tags(['name', 'code', 'minutes', 'app']),
                    subject: static fn (): string => (string) __('alfakit::multi-factor.email.mail.subject'),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) __('alfakit::multi-factor.email.mail.greeting', ['name' => '{{ name }}']),
                        (string) __('alfakit::multi-factor.email.mail.intro'),
                        '{{ code }}',
                        trans_choice('alfakit::multi-factor.email.mail.expiry', 10, ['minutes' => '{{ minutes }}']),
                        (string) __('alfakit::multi-factor.email.mail.ignore'),
                    ),
                ),
            MailDefinition::make(VerifyEmailChange::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit::mail.templates.email_change_verify'))
                ->group($group)
                ->preview(static fn (): VerifyEmailChange => new VerifyEmailChange(url('/email-change/verify?preview=1'), 'ada@example.com'))
                ->editable(
                    mergeTags: static fn (): array => self::tags(['button', 'url', 'old_email', 'minutes', 'app']),
                    subject: static fn (): string => (string) __('alfakit::email-change.verify_mail.subject'),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) __('alfakit::email-change.verify_mail.intro'),
                        '{{ button }}',
                        trans_choice('alfakit::email-change.verify_mail.expiry', EmailChangeVerification::LIFETIME, ['minutes' => '{{ minutes }}']),
                        (string) __('alfakit::email-change.verify_mail.ignore'),
                    ),
                ),
            MailDefinition::make(NoticeOfEmailChangeRequest::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit::mail.templates.email_change_notice'))
                ->group($group)
                ->preview(static fn (): NoticeOfEmailChangeRequest => new NoticeOfEmailChangeRequest(url('/email-change/block?preview=1'), 'ada.lovelace@example.com'))
                ->editable(
                    mergeTags: static fn (): array => self::tags(['new_email', 'button', 'url', 'app']),
                    subject: static fn (): string => (string) __('alfakit::email-change.notice_mail.subject'),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) __('alfakit::email-change.notice_mail.intro', ['email' => '{{ new_email }}']),
                        (string) __('alfakit::email-change.notice_mail.no_action'),
                        '{{ button }}',
                    ),
                ),
            MailDefinition::make(ResetPassword::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit::mail.templates.password_reset'))
                ->group($group)
                ->preview(static fn (): ResetPassword => new ResetPassword('preview-token', url('/password-reset/reset?preview=1')))
                ->editable(
                    mergeTags: static fn (): array => self::tags(['button', 'url', 'minutes', 'app']),
                    subject: static fn (): string => (string) Lang::get('Reset your password'),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) Lang::get('You are receiving this email because we received a password reset request for your account.'),
                        '{{ button }}',
                        (string) Lang::get('This password reset link will expire in :count minutes.', ['count' => '{{ minutes }}']),
                        (string) Lang::get('If you did not request a password reset, no further action is required.'),
                    ),
                ),
            MailDefinition::make(self::EMAIL_VERIFICATION)
                ->label(static fn (): string => (string) __('alfakit::mail.templates.email_verification'))
                ->group($group)
                ->preview(static fn (): BaseMailMessage => self::verifyEmailMessage(url('/email-verification/verify?preview=1')))
                ->sampleData(static fn (): array => self::verifyEmailData(url('/email-verification/verify?preview=1')))
                ->editable(
                    mergeTags: static fn (): array => self::tags(['button', 'url', 'app']),
                    subject: static fn (): string => (string) Lang::get('Verify your email address'),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) Lang::get('Please click the button below to verify your email address.'),
                        '{{ button }}',
                        (string) Lang::get('If you did not create an account, no further action is required.'),
                    ),
                ),
            MailDefinition::make(self::USERS_INVITATION)
                ->label(static fn (): string => (string) __('alfakit::mail.templates.users_invitation'))
                ->group(static fn (): string => (string) __('alfakit::mail.groups.users'))
                ->description(static fn (): string => (string) __('alfakit::mail.templates.users_invitation_description'))
                ->sampleData(static fn (): array => [
                    'name' => 'Ada Lovelace',
                    'url' => url('/invitations/preview'),
                    'button' => EditableTemplates::button(url('/invitations/preview'), (string) __('alfakit::users.invitation.mail.action')),
                    'days' => '7',
                    'app' => MailTheme::brand()->name,
                ])
                ->editable(
                    mergeTags: static fn (): array => self::tags(['name', 'button', 'url', 'days', 'app']),
                    subject: static fn (): string => (string) __('alfakit::users.invitation.mail.subject', ['app' => '{{ app }}']),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) __('alfakit::users.invitation.mail.greeting'),
                        (string) __('alfakit::users.invitation.mail.intro', ['app' => '{{ app }}']),
                        '{{ button }}',
                        (string) __('alfakit::users.invitation.mail.expiry', ['days' => '{{ days }}']),
                    ),
                ),
        );

        // Laravel's "verify your email address" mail, themed and editable, unless the
        // application builds its own (`VerifyEmail::toMailUsing()` in its providers wins).
        if (config('alfakit.mail.verify_email', true) && VerifyEmail::$toMailCallback === null) {
            VerifyEmail::toMailUsing(static fn (mixed $notifiable, string $url): BaseMailMessage => self::verifyEmailMessage($url));
        }
    }

    /**
     * Laravel's email verification mail in the AlfaKit theme, or its edited template.
     */
    public static function verifyEmailMessage(string $url): BaseMailMessage
    {
        $message = Credit::addToMail((new MailMessage)
            ->subject(Lang::get('Verify your email address'))
            ->line(Lang::get('Please click the button below to verify your email address.'))
            ->action(Lang::get('Verify Email Address'), $url)
            ->line(Lang::get('If you did not create an account, no further action is required.')));

        $template = EditableTemplates::renderEdited(self::EMAIL_VERIFICATION, self::verifyEmailData($url), Credit::emailLine(), defaultSubject: (string) $message->subject);

        return $template === null || ! $message instanceof MailMessage ? $message : $message->rendered($template);
    }

    /**
     * @return array<string, mixed>
     */
    private static function verifyEmailData(string $url): array
    {
        return [
            'url' => $url,
            'button' => EditableTemplates::button($url, (string) Lang::get('Verify Email Address')),
            'app' => MailTheme::brand()->name,
        ];
    }

    /**
     * Merge tag labels (`alfakit::mail.tags.*`).
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    public static function tags(array $ids): array
    {
        $tags = [];

        foreach ($ids as $id) {
            $tags[$id] = (string) __('alfakit::mail.tags.'.$id);
        }

        return $tags;
    }
}
