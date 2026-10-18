<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Account;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Rules\ThrottledRule;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\AuthKit\Concerns\InteractsWithAuthKit;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Standalone\Component;
use AlfaSix\Alfakit\Support\Enums\Color;

/**
 * Account page: delete the account for good, after confirming the password. The user is
 * signed out and the session ends; `accountDeletion(using: …)` deletes it your way.
 */
class DeleteAccount extends Component
{
    use InteractsWithAuthKit;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.account.delete.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.account.delete.description');
    }

    public function actions(): array
    {
        return [
            Action::make('deleteAccount')
                ->label(__('alfakit::auth.account.delete.submit'))
                ->icon('trash-2')
                ->color(Color::Danger)
                ->button()
                // Outlined: starter kits set `--destructive-foreground` to the destructive colour.
                ->outlined()
                ->requiresConfirmation()
                ->modalIcon('triangle-alert')
                ->modalHeading(__('alfakit::auth.account.delete.confirm_heading'))
                ->modalDescription(__('alfakit::auth.account.delete.confirm_description'))
                ->modalSubmitActionLabel(__('alfakit::auth.account.delete.submit'))
                ->form([
                    TextInput::make('password')
                        ->label(__('alfakit::auth.profile.current_password'))
                        ->password()
                        ->revealable($this->getPanel()->arePasswordsRevealable())
                        ->required()
                        ->autocomplete('current-password')
                        ->rule(fn (): ThrottledRule => Throttle::currentPasswordRule(Throttle::userKey('auth-kit-current-password', panel: $this->getPanel()), $this->getPanel())),
                ])
                ->action(fn () => $this->deleteAccount()),
        ];
    }

    public function deleteAccount(): mixed
    {
        $panel = $this->getAuthKit();
        $user = $this->getUser();
        $guard = $panel->auth();

        abort_if($user === null || ! $panel->hasAccountDeletion(), 403);

        if (method_exists($guard, 'logout')) {
            $guard->logout();
        }

        $panel->deleteUser($user);

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        Notification::make()->success()->title(__('alfakit::auth.account.delete.done'))->send();

        return redirect()->to(url('/'));
    }
}
