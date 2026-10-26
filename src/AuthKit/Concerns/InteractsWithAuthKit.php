<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Concerns;

use AlfaSix\Alfakit\AuthKit\AuthKitPanel;
use AlfaSix\Alfakit\Http\Middleware\Authenticate;
use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a standalone component part of the app auth kit: it is served by the auth kit
 * panel (its internal endpoints, guard and options).
 */
trait InteractsWithAuthKit
{
    public function getPanel(): Panel
    {
        return alfakit()->getAuthKitPanel();
    }

    /**
     * The auth kit routes its pages with its own `Authenticate` middleware only: the
     * verification prompt and the account page must work before the email address is
     * verified (resend the link, fix a mistyped address). Their internal requests run the
     * same instead of the panel's auth middleware, whose `EnsureEmailIsVerified` would turn
     * every action of an unverified user away with a 403.
     *
     * @return array<int, mixed>
     */
    public static function getPanelAuthMiddleware(Panel $panel): array
    {
        return [Authenticate::class.':'.$panel->getId()];
    }

    public function getAuthKit(): AuthKitPanel
    {
        return alfakit()->getAuthKitPanel();
    }

    /**
     * The page or account sections are rendered while the auth kit panel is current, so
     * the second-factor providers, throttles and closures see its options.
     */
    protected function ensureCurrentPanel(): void
    {
        $current = alfakit()->getCurrentPanel();

        if ($current === null || $current->getId() !== AuthKitPanel::ID) {
            alfakit()->setCurrentPanel($this->getPanel());
        }
    }

    public function getUser(): ?Authenticatable
    {
        return $this->getPanel()->auth()->user();
    }

    /**
     * The signed-in user as a model (account sections).
     */
    public function getUserRecord(): ?Model
    {
        $user = $this->getUser();

        return $user instanceof Model ? $user : null;
    }

    /**
     * @return class-string<Model>
     */
    protected function getUserModel(): string
    {
        $provider = config('auth.guards.'.$this->getPanel()->getAuthGuard().'.provider');

        return config("auth.providers.{$provider}.model");
    }

    /**
     * A row of links under the form ("Forgot your password?", "Sign up"…).
     *
     * @param  array<int, array{label: string, url: string|null}>  $links
     * @return array<string, mixed>|null
     */
    protected function linksBlock(array $links): ?array
    {
        $links = array_values(array_filter($links, static fn (array $link): bool => $link['url'] !== null));

        return $links === [] ? null : ['type' => 'Links', 'key' => 'links', 'links' => $links];
    }
}
