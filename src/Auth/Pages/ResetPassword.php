<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\Forms\Components\Hidden;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ResetPassword extends Page
{
    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    public static function requiresAuthentication(): bool
    {
        return false;
    }

    public static function getRelativeRouteName(): string
    {
        return 'auth.password-reset.reset';
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.password_reset.reset.title');
    }

    public function getHeading(): ?string
    {
        return __('alfakit::auth.password_reset.reset.heading');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('token')->default(fn (): ?string => request()->query('token')),
            TextInput::make('email')->label(__('alfakit::auth.password_reset.reset.email'))->email()->required()->default(fn (): ?string => request()->query('email'))->readOnly(fn (): bool => request()->filled('email')),
            TextInput::make('password')->label(__('alfakit::auth.password_reset.reset.password'))->password()->revealable($this->getPanel()->arePasswordsRevealable())->required()->rule(PasswordRule::default())->confirmed()->autofocus(),
            TextInput::make('password_confirmation')->label(__('alfakit::auth.password_reset.reset.password_confirmation'))->password()->revealable($this->getPanel()->arePasswordsRevealable())->required()->dehydrated(false),
        ])->columns(1);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('reset')
                ->label(__('alfakit::auth.password_reset.reset.submit'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->beforeFormValidated(fn (array $data) => $this->ensureResetIsNotRateLimited($data))
                ->action(fn (array $data) => $this->reset($data)),
        ];
    }

    /**
     * Five attempts a minute for each e-mail address, counted before validation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensureResetIsNotRateLimited(array $data): void
    {
        $email = $data['email'] ?? null;

        if (! is_string($email) || blank($email)) {
            return;
        }

        Throttle::attempt(Throttle::emailKey('reset-password', $email, $this->getPanel()), 'email');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reset(array $data): mixed
    {
        $panel = $this->getPanel();

        $status = Password::broker($panel->getAuthPasswordBroker())->reset(
            ['email' => $data['email'], 'password' => $data['password'], 'token' => $data['token'] ?? ''],
            static function (CanResetPassword&Model $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();

                assert($user instanceof \Illuminate\Contracts\Auth\Authenticatable);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        Notification::make()->success()->title(__('alfakit::auth.password_reset.reset.done'))->send();

        return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
    }
}
