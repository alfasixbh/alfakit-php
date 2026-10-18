<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Account;

use AlfaSix\Alfakit\Auth\Rules\ThrottledRule;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\AuthKit\Concerns\InteractsWithAuthKit;
use AlfaSix\Alfakit\AuthKit\Support\Fortify;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Account page: change the password (the current one is required and throttled). The
 * current session stays signed in; with `AuthenticateSession` other sessions are not.
 */
class UpdatePassword extends Form
{
    use InteractsWithAuthKit;

    protected bool $shouldResetAfterSubmit = true;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.account.password.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.account.password.description');
    }

    public function form(Schema $schema): Schema
    {
        $panel = $this->getPanel();

        return $schema->components([
            TextInput::make('current_password')
                ->label(__('alfakit::auth.profile.current_password'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->required()
                ->autocomplete('current-password')
                ->rule(fn (): ThrottledRule => Throttle::currentPasswordRule(Throttle::userKey('auth-kit-current-password', panel: $panel), $panel)),
            TextInput::make('password')
                ->label(__('alfakit::auth.profile.password'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password')
                ->rule(Password::default())
                ->confirmed(),
            TextInput::make('password_confirmation')
                ->label(__('alfakit::auth.profile.password_confirmation'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password'),
        ])->columns(1);
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? __('alfakit::auth.account.password.submit');
    }

    public function submit(array $data): mixed
    {
        $user = $this->getUserRecord();

        abort_if($user === null || ! $user instanceof Authenticatable, 403);

        $updater = $this->getAuthKit()->usesFortify() ? Fortify::action(Fortify::UPDATES_USER_PASSWORDS) : null;

        if ($updater !== null && method_exists($updater, 'update')) {
            // Fortify's `UpdateUserPassword` checks the current password again and saves.
            $updater->update($user, $data);
        } else {
            $user->forceFill(['password' => Hash::make((string) $data['password'])])->save();
        }

        $this->keepSessionSignedIn($user);

        Notification::make()->success()->title(__('alfakit::auth.account.password.saved'))->send();

        return back();
    }

    /**
     * `AuthenticateSession` signs out sessions whose stored password hash is stale; keep
     * this one signed in.
     */
    protected function keepSessionSignedIn(Model&Authenticatable $user): void
    {
        if (request()->hasSession()) {
            request()->session()->put('password_hash_'.$this->getPanel()->getAuthGuard(), $user->getAuthPassword());
        }
    }
}
