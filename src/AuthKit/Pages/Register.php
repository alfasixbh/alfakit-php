<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\RegistrationInvitations;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\AuthKit\Support\Fortify;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Create an account and sign in. With `invitationOnlyRegistration()` only the address of a
 * signed invitation link can register.
 */
class Register extends AuthKitComponent
{
    public function getTitle(): string
    {
        return __('alfakit::auth.register.title');
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.register.heading');
    }

    public function form(Schema $schema): Schema
    {
        $panel = $this->getPanel();
        $model = $this->getUserModel();
        $invited = $panel->hasInvitationOnlyRegistration();

        return $schema->components([
            TextInput::make('name')->label(__('alfakit::auth.register.name'))->required()->maxLength(255)->autocomplete('name')->autofocus(),
            TextInput::make('email')
                ->label(__('alfakit::auth.register.email'))
                ->email()
                ->required()
                ->maxLength(255)
                ->autocomplete('email')
                ->unique(table: (new $model)->getTable())
                ->default(fn (): ?string => $invited ? RegistrationInvitations::email($panel) : null)
                ->readOnly($invited),
            TextInput::make('password')
                ->label(__('alfakit::auth.register.password'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password')
                ->rule(Password::default())
                ->confirmed(),
            TextInput::make('password_confirmation')
                ->label(__('alfakit::auth.register.password_confirmation'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->autocomplete('new-password')
                ->required(),
        ])->columns(1);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('register')
                ->label(__('alfakit::auth.register.submit'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                // Five attempts a minute for each email address, counted before validation.
                ->beforeFormValidated(function (array $data): void {
                    $email = $data['email'] ?? null;

                    if (is_string($email) && filled($email)) {
                        Throttle::attempt(Throttle::emailKey('register', $email, $this->getPanel()), 'email');
                    }

                    Throttle::attempt('alfakit-auth-kit-register|'.request()->ip(), 'email', 20, 3600);
                })
                ->action(fn (array $data) => $this->register($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): mixed
    {
        $panel = $this->getAuthKit();

        if ($panel->hasInvitationOnlyRegistration() && ! RegistrationInvitations::matches($panel, $data['email'] ?? null)) {
            throw ValidationException::withMessages(['email' => __('alfakit::auth.invitation.invalid')]);
        }

        $user = $this->createUser($data);

        RegistrationInvitations::forget($panel);

        event(new Registered($user));

        $guard = $panel->auth();

        if (method_exists($guard, 'login')) {
            $guard->login($user);
        }

        request()->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    /**
     * Create the user: with Fortify, its `CreatesNewUsers` action (`App\Actions\Fortify\CreateNewUser`).
     *
     * @param  array<string, mixed>  $data
     */
    protected function createUser(array $data): Authenticatable
    {
        $creator = $this->getAuthKit()->usesFortify() ? Fortify::action(Fortify::CREATES_NEW_USERS) : null;

        if ($creator !== null && method_exists($creator, 'create')) {
            $user = $creator->create($data);

            assert($user instanceof Authenticatable);

            return $user;
        }

        $model = $this->getUserModel();
        $user = $model::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make((string) $data['password']),
        ]);

        assert($user instanceof Authenticatable);

        return $user;
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $this->linksBlock([['label' => __('alfakit::auth.register.login'), 'url' => $this->getPanel()->getLoginUrl()]]),
        ]));
    }
}
