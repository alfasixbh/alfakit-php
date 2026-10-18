<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends Page
{
    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    public static function requiresAuthentication(): bool
    {
        return false;
    }

    public static function getRelativeRouteName(): string
    {
        return 'auth.login';
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.login.title');
    }

    public function getHeading(): ?string
    {
        return __('alfakit::auth.login.heading');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label(__('alfakit::auth.login.email'))
                ->email()
                ->required()
                ->autocomplete('email')
                ->autofocus()
                ->extraInputAttributes(['tabindex' => 1]),
            TextInput::make('password')
                ->label(__('alfakit::auth.login.password'))
                ->password()
                ->revealable($this->getPanel()->arePasswordsRevealable())
                ->required()
                ->autocomplete('current-password')
                ->hint($this->getPanel()->hasPasswordReset() ? __('alfakit::auth.login.forgot_password') : null)
                ->meta(['hintUrl' => $this->getPanel()->getPasswordResetUrl()])
                ->extraInputAttributes(['tabindex' => 2]),
            Checkbox::make('remember')->label(__('alfakit::auth.login.remember')),
        ])->columns(1);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('authenticate')
                ->label(__('alfakit::auth.login.submit'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->authenticate($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function authenticate(array $data): mixed
    {
        $this->ensureIsNotRateLimited($data);

        $panel = $this->getPanel();
        $guard = $panel->auth();

        // A correct password with a second factor set up continues on the challenge page
        // instead of signing in.
        $challenge = MultiFactorAuthentication::interceptLogin($panel, $this->getCredentials($data), (bool) ($data['remember'] ?? false));

        if ($challenge !== null) {
            RateLimiter::clear($this->throttleKey($data));

            return $challenge;
        }

        if (! $guard->attempt($this->getCredentials($data), (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($this->throttleKey($data));

            throw ValidationException::withMessages(['email' => __('alfakit::auth.login.failed')]);
        }

        RateLimiter::clear($this->throttleKey($data));

        $user = $guard->user();

        if ($user instanceof CanAccessPanel && ! $user->canAccessPanel($panel)) {
            $guard->logout();

            throw ValidationException::withMessages(['email' => __('alfakit::auth.login.failed')]);
        }

        request()->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentials(array $data): array
    {
        return ['email' => $data['email'], 'password' => $data['password']];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ensureIsNotRateLimited(array $data): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($data), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('alfakit::auth.login.throttled', ['seconds' => RateLimiter::availableIn($this->throttleKey($data))]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function throttleKey(array $data): string
    {
        return Str::transliterate(Str::lower((string) ($data['email'] ?? '')).'|'.request()->ip());
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();
        $panel = $this->getPanel();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $panel->hasRegistration() && ! $panel->hasInvitationOnlyRegistration() ? ['type' => 'Links', 'key' => 'links', 'links' => [['label' => __('alfakit::auth.login.register'), 'url' => $panel->getRegistrationUrl()]]] : null,
        ]));
    }
}
