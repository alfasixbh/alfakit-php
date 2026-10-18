<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\AuthKit\Support\Fortify;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Choose a new password from a reset link. The token travels in the component's encrypted
 * context, never in the form.
 */
class ResetPassword extends AuthKitComponent
{
    public ?string $token = null;

    public ?string $email = null;

    public function getTitle(): string
    {
        return __('alfakit::auth.password_reset.reset.title');
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.password_reset.reset.heading');
    }

    public function form(Schema $schema): Schema
    {
        $panel = $this->getPanel();

        return $schema->components([
            TextInput::make('email')
                ->label(__('alfakit::auth.password_reset.reset.email'))
                ->email()
                ->required()
                ->autocomplete('email')
                ->default(fn (): ?string => $this->email)
                ->readOnly(fn (): bool => filled($this->email)),
            TextInput::make('password')
                ->label(__('alfakit::auth.password_reset.reset.password'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password')
                ->rule(PasswordRule::default())
                ->confirmed()
                ->autofocus(),
            TextInput::make('password_confirmation')
                ->label(__('alfakit::auth.password_reset.reset.password_confirmation'))
                ->password()
                ->revealable($panel->arePasswordsRevealable())
                ->autocomplete('new-password')
                ->required(),
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
                ->beforeFormValidated(function (array $data): void {
                    $email = $data['email'] ?? null;

                    if (is_string($email) && filled($email)) {
                        Throttle::attempt(Throttle::emailKey('reset-password', $email, $this->getPanel()), 'email');
                    }
                })
                ->action(fn (array $data) => $this->reset($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reset(array $data): mixed
    {
        $panel = $this->getAuthKit();
        $resetter = $panel->usesFortify() ? Fortify::action(Fortify::RESETS_USER_PASSWORDS) : null;

        $status = Password::broker($panel->getAuthPasswordBroker())->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'] ?? null,
                'token' => (string) $this->token,
            ],
            static function (CanResetPassword $user, string $password) use ($resetter, $data): void {
                assert($user instanceof Authenticatable && $user instanceof Model);

                // Fortify's `ResetUserPassword` action validates and saves the new password.
                if ($resetter !== null && method_exists($resetter, 'reset')) {
                    $resetter->reset($user, ['password' => $password, 'password_confirmation' => $data['password_confirmation'] ?? null]);
                } else {
                    $user->forceFill(['password' => Hash::make($password)]);
                }

                $user->forceFill(['remember_token' => Str::random(60)])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        Notification::make()->success()->title(__('alfakit::auth.password_reset.reset.done'))->send();

        return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $this->linksBlock([['label' => __('alfakit::auth.password_reset.request.back'), 'url' => $this->getPanel()->getLoginUrl()]]),
        ]));
    }
}
