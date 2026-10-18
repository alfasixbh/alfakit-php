<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Account;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\EmailChange\EmailChangeVerification;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\AuthKit\Concerns\InteractsWithAuthKit;
use AlfaSix\Alfakit\AuthKit\Support\Fortify;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * Account page: name and email address. A new address is verified again (or, with
 * `emailChangeVerification()`, only replaces the old one once confirmed).
 */
class Profile extends Form
{
    use InteractsWithAuthKit;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.account.profile.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.account.profile.description');
    }

    public function getRecord(): ?Model
    {
        return $this->getUserRecord();
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->getUser();
        $pending = $user !== null && $this->getPanel()->hasEmailChangeVerification()
            ? EmailChangeVerification::getPendingEmail($this->getPanel(), $user)
            : null;

        return $schema->components(array_values(array_filter([
            TextInput::make('name')->label(__('alfakit::auth.profile.name'))->required()->maxLength(255)->autocomplete('name'),
            TextInput::make('email')->label(__('alfakit::auth.profile.email'))->email()->required()->maxLength(255)->autocomplete('email')->unique(ignoreRecord: true),
            // Changing the address needs the current password, so a hijacked session can't
            // take the account over (every attempt counts against a per-user limit).
            TextInput::make('current_password')
                ->label(__('alfakit::auth.profile.current_password'))
                ->password()
                ->revealable($this->getPanel()->arePasswordsRevealable())
                ->autocomplete('current-password')
                ->helperText(__('alfakit::auth.account.profile.current_password_hint'))
                ->required(fn (Get $get): bool => $user !== null && is_string($get('email')) && mb_strtolower($get('email')) !== mb_strtolower((string) $this->getUserRecord()?->getAttribute('email')))
                ->rule(fn (): \AlfaSix\Alfakit\Auth\Rules\ThrottledRule => Throttle::currentPasswordRule(Throttle::userKey('auth-kit-current-password', panel: $this->getPanel()), $this->getPanel()))
                ->dehydrated(false),
            $pending === null ? null : Text::make(__('alfakit::email-change.pending', ['email' => $pending]))->icon('mail-check')->color(Color::Info),
        ])))->columns(1);
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? __('alfakit::auth.profile.save');
    }

    protected function getSubmitAction(): Action
    {
        // Five saves a minute per user.
        return parent::getSubmitAction()
            ->beforeFormValidated(fn () => Throttle::attemptOrNotify(Throttle::userKey('auth-kit-profile', panel: $this->getPanel())));
    }

    public function submit(array $data): mixed
    {
        $user = $this->getUserRecord();

        abort_if($user === null || ! $user instanceof Authenticatable, 403);

        $updater = $this->getAuthKit()->usesFortify() ? Fortify::action(Fortify::UPDATES_USER_PROFILE_INFORMATION) : null;

        if ($updater !== null && method_exists($updater, 'update')) {
            // Fortify's `UpdateUserProfileInformation` validates, saves and re-sends the
            // verification email itself.
            $updater->update($user, $data);
        } else {
            $this->save($user, $data);
        }

        Notification::make()->success()->title(__('alfakit::auth.profile.saved'))->send();

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function save(Model&Authenticatable $user, array $data): void
    {
        $user->fill(['name' => $data['name'], 'email' => $data['email']]);

        $panel = $this->getPanel();

        // With email-change verification the new address waits for its link.
        if ($panel->hasEmailChangeVerification() && $user->isDirty('email')) {
            $newEmail = (string) $user->getAttribute('email');
            $user->setAttribute('email', $user->getOriginal('email'));

            EmailChangeVerification::request($panel, $user, $newEmail);

            Notification::make()->info()->title(__('alfakit::email-change.sent', ['email' => $newEmail]))->persistent()->send();
        }

        $emailChanged = $user->isDirty('email');

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }
    }
}
