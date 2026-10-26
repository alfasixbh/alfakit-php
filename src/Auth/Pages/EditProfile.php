<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class EditProfile extends Page
{
    use Concerns\ManagesAccountSecurity;

    // Registered by the panel's `login()`, `registration()`… only, never discovered as a page:
    // a subclass in a discovered folder would otherwise also become a panel page (W-S1-15).
    protected static bool $isDiscovered = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function getRelativeRouteName(): string
    {
        return 'auth.profile';
    }

    public static function getLayout(): string
    {
        return alfakit()->getCurrentOrDefaultPanel()->isProfilePageSimple() ? 'auth' : 'app';
    }

    public function getTitle(): string
    {
        return __('alfakit::auth.profile.title');
    }

    public function getRecord(): ?Model
    {
        $user = $this->getPanel()->auth()->user();

        return $user instanceof Model ? $user : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ])->columns(1);
    }

    /**
     * The default fields, to reuse (and customise) in your own `form()`:
     * `$this->getPasswordFormComponent()->revealable(false)`.
     */
    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')->label(__('alfakit::auth.profile.name'))->required()->maxLength(255);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label(__('alfakit::auth.profile.email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('alfakit::auth.profile.password'))
            ->password()
            ->revealable($this->getPanel()->arePasswordsRevealable())
            ->rule(Password::default())
            ->confirmed()
            ->helperText(__('alfakit::auth.profile.password_hint'))
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->dehydrateStateUsing(static fn (string $state): string => Hash::make($state));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('password_confirmation')
            ->label(__('alfakit::auth.profile.password_confirmation'))
            ->password()
            ->revealable($this->getPanel()->arePasswordsRevealable())
            ->dehydrated(false);
    }

    /**
     * Required when the email address or password changes, so a hijacked session can't
     * take over the account.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('current_password')
            ->label(__('alfakit::auth.profile.current_password'))
            ->password()
            ->revealable($this->getPanel()->arePasswordsRevealable())
            ->helperText(__('alfakit::auth.profile.current_password_hint'))
            ->required(fn (Get $get): bool => $this->changesCredentials($get('email'), $get('password')))
            ->rule('current_password:'.$this->getPanel()->getAuthGuard())
            ->dehydrated(false);
    }

    /**
     * Changing the email address or password needs the current password, so a hijacked
     * session can't take over the account.
     */
    protected function changesCredentials(mixed $email, mixed $password): bool
    {
        $record = $this->getRecord();

        return filled($password) || ($record !== null && is_string($email) && $email !== $record->getAttribute('email'));
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password'], $data['password_confirmation']);

        return $data;
    }

    protected function getFormActions(): array
    {
        return array_values(array_filter([
            Action::make('save')
                ->label(__('alfakit::auth.profile.save'))
                ->color(Color::Primary)
                ->button()
                ->keyBindings(['mod+s'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                // Five saves a minute for each user, counted before validation, so the
                // current password can't be guessed by retrying.
                ->beforeFormValidated(fn () => Throttle::attemptOrNotify(Throttle::userKey('edit-profile', panel: $this->getPanel())))
                ->action(fn (array $data) => $this->save($data)),
            $this->getPanel()->isProfilePageSimple()
                ? Action::make('back')->label(__('alfakit::auth.profile.back'))->color(Color::Gray)->ghost()->button()->url($this->getPanel()->getUrl())
                : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): mixed
    {
        $record = $this->getRecord();

        abort_if($record === null, 403);

        $record->fill($data);

        // With email-change verification the new address waits for its link.
        $this->deferEmailChange($record);

        $emailChanged = $record->isDirty('email');

        // A new address has to be verified again.
        if ($emailChanged && $record instanceof MustVerifyEmail) {
            $record->forceFill(['email_verified_at' => null]);
        }

        $record->save();

        if ($emailChanged && $record instanceof MustVerifyEmail) {
            $record->sendEmailVerificationNotification();
        }

        Notification::make()->success()->title(__('alfakit::auth.profile.saved'))->send();

        return back();
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();
        $security = $this->getInfolist();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $security === null ? null : $this->serializeInfolist($security),
        ]));
    }
}
