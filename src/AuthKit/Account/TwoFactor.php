<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Account;

use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\AuthKit\Concerns\InteractsWithAuthKit;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Component;
use AlfaSix\Alfakit\Support\Enums\Color;

/**
 * Account page: set up, manage and remove the second factors configured with
 * `multiFactorAuthentication()` (authenticator app with recovery codes, emailed codes).
 */
class TwoFactor extends Component
{
    use InteractsWithAuthKit;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::auth.account.two_factor.heading');
    }

    public function getSubheading(): ?string
    {
        return $this->subheading ?? __('alfakit::auth.account.two_factor.description');
    }

    public function infolist(Schema $schema): Schema
    {
        $panel = $this->getPanel();
        $user = $this->getUser();

        if ($user === null || ! $panel->hasMultiFactorAuthentication()) {
            return $schema;
        }

        $components = [];

        if ($panel->isMultiFactorAuthenticationRequired() && MultiFactorAuthentication::getEnabledProviders($panel, $user) === []) {
            $components[] = Text::make(__('alfakit::multi-factor.required'))->icon('shield-alert')->color(Color::Warning);
        }

        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isSupportedBy($user)) {
                array_push($components, ...$provider->getManagementSchemaComponents($user));
            }
        }

        return $schema->components($components)->columns(1);
    }
}
