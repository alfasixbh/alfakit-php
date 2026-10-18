<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\AuthKit\Support\Fortify;
use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign in with an email address and password. A correct password with a second factor set
 * up continues on the two-step verification page instead of signing in.
 */
class Login extends AuthKitComponent
{
    public function getTitle(): string
    {
        return __('alfakit::auth.login.title');
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.login.heading');
    }

    public function form(Schema $schema): Schema
    {
        $panel = $this->getPanel();

        return $schema->components([
            TextInput::make('email')
                ->label(__('alfakit::auth.login.email'))
                ->email()
                ->required()
                ->autocomplete('email')
                ->autofocus(),
            TextInput::make('password')
                ->label(__('alfakit::auth.login.password'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->required()
                ->autocomplete('current-password')
                ->hint($panel->getPasswordResetUrl() !== null ? __('alfakit::auth.login.forgot_password') : null)
                ->meta(['hintUrl' => $panel->getPasswordResetUrl()]),
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

        $panel = $this->getAuthKit();
        $guard = $panel->auth();
        $remember = (bool) ($data['remember'] ?? false);

        $user = $this->resolveUser($data);

        if ($user === null) {
            RateLimiter::hit($this->throttleKey($data));

            throw ValidationException::withMessages(['email' => __('alfakit::auth.login.failed')]);
        }

        RateLimiter::clear($this->throttleKey($data));

        // Fortify's own two-factor authentication, then AlfaKit's second factors.
        if ($panel->usesFortify() && Fortify::hasTwoFactor($user)) {
            MultiFactorAuthentication::startChallenge($panel, $user, $remember);
            Fortify::challenged($user);

            return redirect()->to($panel->getMultiFactorChallengeUrl(TwoFactorChallenge::FORTIFY));
        }

        if ($panel->hasMultiFactorAuthentication()
            && MultiFactorAuthentication::getEnabledProviders($panel, $user) !== []
            && ! MultiFactorAuthentication::isDeviceRemembered($panel, $user)) {
            MultiFactorAuthentication::startChallenge($panel, $user, $remember);

            return redirect()->to($panel->getMultiFactorChallengeUrl());
        }

        if (method_exists($guard, 'login')) {
            $guard->login($user, $remember);
        }

        request()->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    /**
     * The user the credentials belong to, without signing in yet (second factors come
     * first): Fortify's `authenticateUsing()` callback when the app uses one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveUser(array $data): ?Authenticatable
    {
        $panel = $this->getAuthKit();

        if ($panel->usesFortify() && ($callback = Fortify::authenticateUsing()) !== null) {
            return Fortify::authenticate($callback, [
                Fortify::username() => $data['email'] ?? null,
                'password' => $data['password'] ?? null,
                'remember' => (bool) ($data['remember'] ?? false),
            ]);
        }

        $guard = $panel->auth();

        if (! method_exists($guard, 'getProvider')) {
            return null;
        }

        $credentials = $this->getCredentials($data);
        $provider = $guard->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        return $user !== null && $provider->validateCredentials($user, $credentials) ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentials(array $data): array
    {
        $username = $this->getAuthKit()->usesFortify() ? Fortify::username() : 'email';

        return [$username => $data['email'] ?? null, 'password' => $data['password'] ?? null];
    }

    /**
     * Five attempts a minute for each email address and IP.
     *
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
        return 'alfakit-auth-kit-login|'.Str::transliterate(Str::lower((string) ($data['email'] ?? '')).'|'.request()->ip());
    }

    protected function getContentBlocks(): array
    {
        $panel = $this->getAuthKit();
        $form = $this->getForm();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $this->linksBlock([
                ['label' => __('alfakit::auth.login.register'), 'url' => $panel->hasInvitationOnlyRegistration() ? null : $panel->getRegistrationUrl()],
            ]),
        ]));
    }
}
