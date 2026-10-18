<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\AuthKit\Notifications\VerifyEmail as VerifyEmailNotification;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Check your inbox": shown to signed-in users whose email address isn't verified yet
 * (the `verified` middleware sends them here), with a button to send the link again.
 */
class VerifyEmail extends AuthKitComponent
{
    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.email_verification.title');
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.email_verification.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.email_verification.description', ['email' => $this->getUserRecord()?->getAttribute('email')]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * The buttons, rendered as a form without fields.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        $logout = $this->getPanel()->getLogoutUrl();

        return [
            Action::make('resend')
                ->label(__('alfakit::auth.email_verification.resend'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->action(fn () => $this->resend()),
            Action::make('logout')
                ->label(__('alfakit::auth.account.logout'))
                ->color(Color::Gray)
                ->ghost()
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->url($logout)
                ->postToUrl(),
        ];
    }

    /**
     * Two emails a minute per user.
     */
    public function resend(): mixed
    {
        $user = $this->getUser();
        $key = 'alfakit-auth-kit-verify|'.($user?->getAuthIdentifier() ?? request()->ip());

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail() && ! RateLimiter::tooManyAttempts($key, 2)) {
            RateLimiter::hit($key, 60);

            if (method_exists($user, 'notify')) {
                $user->notify(new VerifyEmailNotification);
            } else {
                $user->sendEmailVerificationNotification();
            }
        }

        Notification::make()->success()->title(__('alfakit::auth.email_verification.sent'))->send();

        return back();
    }

    protected function getContentBlocks(): array
    {
        return [[
            'type' => 'Form',
            'key' => 'form',
            'component' => 'form',
            'components' => [],
            'state' => [],
            'actions' => $this->serializeActions($this->getCachedFormActions()),
        ]];
    }
}
