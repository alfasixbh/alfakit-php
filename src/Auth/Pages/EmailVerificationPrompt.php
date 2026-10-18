<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationPrompt extends Page
{
    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRelativeRouteName(): string
    {
        return 'auth.email-verification.prompt';
    }

    public function render(): \Inertia\Response
    {
        $user = $this->getPanel()->auth()->user();

        if ($user instanceof MustVerifyEmail && $user->hasVerifiedEmail()) {
            abort(redirect()->to($this->getPanel()->getUrl()));
        }

        return parent::render();
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.email_verification.title');
    }

    public function getHeading(): ?string
    {
        return __('alfakit::auth.email_verification.heading');
    }

    public function getSubheading(): ?string
    {
        return __('alfakit::auth.email_verification.description', ['email' => $this->getPanel()->auth()->user()?->getAttribute('email')]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('resend')
                ->label(__('alfakit::auth.email_verification.resend'))
                ->color(Color::Primary)
                ->button()
                ->action(fn () => $this->resend()),
        ];
    }

    public function resend(): mixed
    {
        $user = $this->getPanel()->auth()->user();

        if ($user instanceof MustVerifyEmail && ! RateLimiter::tooManyAttempts('alfakit-verify:'.$user->getKey(), 2)) {
            RateLimiter::hit('alfakit-verify:'.$user->getKey(), 60);
            $user->sendEmailVerificationNotification();
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
