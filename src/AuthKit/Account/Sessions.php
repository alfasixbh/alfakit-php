<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Account;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Rules\ThrottledRule;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\AuthKit\Concerns\InteractsWithAuthKit;
use AlfaSix\Alfakit\AuthKit\Support\BrowserSessions;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry\TableColumn;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Component;
use AlfaSix\Alfakit\Support\Enums\Color;

/**
 * Account page: the browsers and devices the user is signed in on (database session
 * driver), and "sign out other sessions" after confirming the password.
 */
class Sessions extends Component
{
    use InteractsWithAuthKit;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.account.sessions.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.account.sessions.description');
    }

    public function infolist(Schema $schema): Schema
    {
        if (! BrowserSessions::isAvailable()) {
            return $schema->components([
                Text::make(__('alfakit::auth.account.sessions.unavailable'))->color(Color::Gray),
            ]);
        }

        return $schema->components([
            RepeatableEntry::make('sessions')
                ->hiddenLabel()
                ->columnSpanFull()
                ->getStateUsing(function (): array {
                    $user = $this->getUser();

                    return $user === null ? [] : BrowserSessions::for($user);
                })
                ->placeholder(__('alfakit::users.sessions.empty'))
                ->table([
                    TableColumn::make(__('alfakit::users.sessions.device')),
                    TableColumn::make(__('alfakit::users.sessions.ip_address')),
                    TableColumn::make(__('alfakit::users.sessions.last_active')),
                ])
                ->schema([
                    TextEntry::make('device')->hiddenLabel()->icon('monitor'),
                    TextEntry::make('ip_address')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('last_active')->hiddenLabel(),
                ]),
        ])->columns(1);
    }

    public function actions(): array
    {
        return [
            Action::make('logoutOtherSessions')
                ->label(__('alfakit::auth.account.sessions.logout_others'))
                ->icon('log-out')
                ->color(Color::Gray)
                ->button()
                ->outlined()
                ->modalHeading(__('alfakit::auth.account.sessions.logout_others'))
                ->modalDescription(__('alfakit::auth.account.sessions.logout_others_description'))
                ->modalSubmitActionLabel(__('alfakit::auth.account.sessions.logout_others'))
                ->form([
                    TextInput::make('password')
                        ->label(__('alfakit::auth.profile.current_password'))
                        ->password()
                        ->revealable($this->getPanel()->arePasswordsRevealable())
                        ->required()
                        ->autocomplete('current-password')
                        ->rule(fn (): ThrottledRule => Throttle::currentPasswordRule(Throttle::userKey('auth-kit-current-password', panel: $this->getPanel()), $this->getPanel())),
                ])
                ->action(fn (array $data) => $this->logoutOtherSessions((string) $data['password'])),
        ];
    }

    public function logoutOtherSessions(string $password): mixed
    {
        $user = $this->getUser();
        $guard = $this->getPanel()->auth();

        abort_if($user === null, 403);

        // Rehashes the password, so `AuthenticateSession` signs every other session out.
        if (method_exists($guard, 'logoutOtherDevices')) {
            $guard->logoutOtherDevices($password);
        }

        BrowserSessions::endOthers($user);

        Notification::make()->success()->title(__('alfakit::auth.account.sessions.logged_out'))->send();

        return back();
    }
}
