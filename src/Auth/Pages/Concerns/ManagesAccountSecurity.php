<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\EmailChange\EmailChangeVerification;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The security part of the profile page: second factors (set up, recovery codes, remove)
 * and a pending email change. Rendered as the page's infolist, below the profile form.
 */
trait ManagesAccountSecurity
{
    public function infolist(Schema $schema): Schema
    {
        $user = $this->getPanel()->auth()->user();

        if ($user === null) {
            return $schema;
        }

        return $schema->components([
            ...$this->getPendingEmailChangeComponents($user),
            ...$this->getMultiFactorAuthenticationComponents($user),
        ])->columns(1);
    }

    /**
     * @return array<Component>
     */
    protected function getMultiFactorAuthenticationComponents(Authenticatable $user): array
    {
        $panel = $this->getPanel();

        if (! $panel->hasMultiFactorAuthentication()) {
            return [];
        }

        $components = [];

        if ($panel->isMultiFactorAuthenticationRequired() && MultiFactorAuthentication::getEnabledProviders($panel, $user) === []) {
            $components[] = Text::make(__('alfakit::multi-factor.required'))->icon('shield-alert')->color(Color::Warning);
        }

        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            array_push($components, ...$provider->getManagementSchemaComponents($user));
        }

        return $components;
    }

    /**
     * @return array<Component>
     */
    protected function getPendingEmailChangeComponents(Authenticatable $user): array
    {
        $panel = $this->getPanel();
        $pending = $panel->hasEmailChangeVerification() ? EmailChangeVerification::getPendingEmail($panel, $user) : null;

        if ($pending === null) {
            return [];
        }

        return [
            Section::make(__('alfakit::email-change.pending_heading'))
                ->description(__('alfakit::email-change.pending', ['email' => $pending]))
                ->icon('mail-check')
                ->compact()
                ->columns(1)
                ->headerActions([
                    Action::make('cancelEmailChange')
                        ->label(__('alfakit::email-change.cancel'))
                        ->color(Color::Gray)
                        ->button()
                        ->outlined()
                        ->action(function () use ($panel): void {
                            $user = $panel->auth()->user();

                            if ($user !== null) {
                                EmailChangeVerification::cancel($panel, $user);
                            }

                            Notification::make()->success()->title(__('alfakit::email-change.cancelled'))->send();
                        }),
                ]),
        ];
    }

    /**
     * With `emailChangeVerification()`, keep the current address and email a link to the
     * new one instead. Returns whether the change was deferred.
     */
    protected function deferEmailChange(Model $record): bool
    {
        $panel = $this->getPanel();

        if (! $panel->hasEmailChangeVerification() || ! $record->isDirty('email') || ! $record instanceof Authenticatable) {
            return false;
        }

        $newEmail = (string) $record->getAttribute('email');
        $record->setAttribute('email', $record->getOriginal('email'));

        EmailChangeVerification::request($panel, $record, $newEmail);

        Notification::make()->info()->title(__('alfakit::email-change.sent', ['email' => $newEmail]))->persistent()->send();

        return true;
    }
}
