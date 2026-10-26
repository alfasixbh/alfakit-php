<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\RegistrationInvitations;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class Register extends Page
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
        return 'auth.register';
    }

    /**
     * With `invitationOnlyRegistration()` the page only opens from a signed invitation link
     * (or later in the same session); everyone else is sent to the sign-in page.
     */
    public function __invoke(Request $request): mixed
    {
        $panel = $this->getPanel();

        if ($panel->hasInvitationOnlyRegistration() && ! RegistrationInvitations::accept($panel, $request)) {
            Notification::make()->warning()->title(__('alfakit::auth.invitation.required'))->send();

            return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
        }

        return parent::__invoke($request);
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.register.title');
    }

    public function getHeading(): ?string
    {
        return __('alfakit::auth.register.heading');
    }

    /**
     * @return class-string<Model>
     */
    protected function getUserModel(): string
    {
        $provider = config('auth.guards.'.$this->getPanel()->getAuthGuard().'.provider');

        return config("auth.providers.{$provider}.model");
    }

    public function form(Schema $schema): Schema
    {
        $model = $this->getUserModel();

        return $schema->components([
            TextInput::make('name')->label(__('alfakit::auth.register.name'))->required()->maxLength(255)->autofocus(),
            TextInput::make('email')->label(__('alfakit::auth.register.email'))->email()->required()->maxLength(255)->unique(table: (new $model)->getTable())
                // An invitation registers the invited address only.
                ->default(fn (): ?string => $this->getPanel()->hasInvitationOnlyRegistration() ? RegistrationInvitations::email($this->getPanel()) : null)
                ->readOnly(fn (): bool => $this->getPanel()->hasInvitationOnlyRegistration()),
            TextInput::make('password')->label(__('alfakit::auth.register.password'))->password()->revealable($this->getPanel()->arePasswordsRevealable())->required()->rule(Password::default())->confirmed(),
            TextInput::make('password_confirmation')->label(__('alfakit::auth.register.password_confirmation'))->password()->revealable($this->getPanel()->arePasswordsRevealable())->required()->dehydrated(false),
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
                ->beforeFormValidated(fn (array $data) => $this->ensureRegistrationIsNotRateLimited($data))
                ->action(fn (array $data) => $this->register($data)),
        ];
    }

    /**
     * Five attempts a minute for each e-mail address, counted before validation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensureRegistrationIsNotRateLimited(array $data): void
    {
        $email = $data['email'] ?? null;

        if (! is_string($email) || blank($email)) {
            return;
        }

        Throttle::attempt(Throttle::emailKey('register', $email, $this->getPanel()), 'email');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): mixed
    {
        $panel = $this->getPanel();

        if ($panel->hasInvitationOnlyRegistration() && ! RegistrationInvitations::matches($panel, $data['email'] ?? null)) {
            throw ValidationException::withMessages(['email' => __('alfakit::auth.invitation.invalid')]);
        }

        $user = $this->handleRegistration($this->mutateFormDataBeforeRegister($data));
        RegistrationInvitations::forget($panel);

        assert($user instanceof Authenticatable);

        event(new Registered($user));

        $panel->auth()->login($user);
        request()->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeRegister(array $data): array
    {
        $data['password'] = Hash::make($data['password']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $model = $this->getUserModel();

        return $model::query()->create($data);
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            ['type' => 'Links', 'key' => 'links', 'links' => [['label' => __('alfakit::auth.register.login'), 'url' => $this->getPanel()->getLoginUrl()]]],
        ]));
    }
}
