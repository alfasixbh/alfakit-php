<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class RequestPasswordReset extends Page
{
    // Registered by the panel's `login()`, `registration()`… only, never discovered as a page:
    // a subclass in a discovered folder would otherwise also become a panel page (W-S1-15).
    protected static bool $isDiscovered = false;

    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    public static function requiresAuthentication(): bool
    {
        return false;
    }

    public static function getRelativeRouteName(): string
    {
        return 'auth.password-reset.request';
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.password_reset.request.title');
    }

    public function getHeading(): ?string
    {
        return __('alfakit::auth.password_reset.request.heading');
    }

    public function getSubheading(): ?string
    {
        return __('alfakit::auth.password_reset.request.description');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->label(__('alfakit::auth.password_reset.request.email'))->email()->required()->autofocus()->autocomplete('email'),
        ])->columns(1);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('request')
                ->label(__('alfakit::auth.password_reset.request.submit'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->request($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(array $data): mixed
    {
        $key = 'alfakit-password-reset:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => __('alfakit::auth.password_reset.request.throttled')]);
        }

        RateLimiter::hit($key, 60);

        $panel = $this->getPanel();
        $broker = Password::broker($panel->getAuthPasswordBroker());

        $broker->sendResetLink(['email' => $data['email']], function (CanResetPassword $user, string $token) use ($panel): void {
            $url = $panel->route('auth.password-reset.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);

            assert(method_exists($user, 'notify'));

            $user->notify(new \AlfaSix\Alfakit\Auth\Notifications\ResetPassword($token, $url));
        });

        Notification::make()->success()->title(__('alfakit::auth.password_reset.request.sent'))->send();

        return back();
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            ['type' => 'Links', 'key' => 'links', 'links' => [['label' => __('alfakit::auth.password_reset.request.back'), 'url' => $this->getPanel()->getLoginUrl()]]],
        ]));
    }
}
