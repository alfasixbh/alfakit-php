<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Notifications\ResetPassword;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;

/**
 * Ask for a password reset link. The answer is the same whether an account exists or
 * not, so the page can't be used to find out who has one.
 */
class ForgotPassword extends AuthKitComponent
{
    public function getTitle(): string
    {
        return __('alfakit::auth.password_reset.request.title');
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.password_reset.request.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.password_reset.request.description');
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
                // Three requests a minute from one IP address, and five per email address.
                ->beforeFormValidated(function (array $data): void {
                    Throttle::attempt('alfakit-auth-kit-password-reset|'.request()->ip(), 'email', 3);

                    $email = $data['email'] ?? null;

                    if (is_string($email) && filled($email)) {
                        Throttle::attempt(Throttle::emailKey('password-reset-request', $email, $this->getPanel()), 'email');
                    }
                })
                ->action(fn (array $data) => $this->request($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(array $data): mixed
    {
        $panel = $this->getAuthKit();

        Password::broker($panel->getAuthPasswordBroker())->sendResetLink(['email' => $data['email']], function (CanResetPassword $user, string $token) use ($panel): void {
            $url = $panel->pageRoute('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]) ?? url('/');

            assert(method_exists($user, 'notify'));

            $user->notify(new ResetPassword($token, $url));
        });

        Notification::make()->success()->title(__('alfakit::auth.password_reset.request.sent'))->send();

        return back();
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
