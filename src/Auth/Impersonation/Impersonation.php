<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Impersonation;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Contracts\CanBeImpersonated;
use AlfaSix\Alfakit\Contracts\CanImpersonate;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\Alfakit\Panel;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Signing in as another user and back. The real user is remembered in the server-side
 * session only, so the browser can't choose who it returns to.
 */
final class Impersonation
{
    private static bool $switching = false;

    /**
     * Whether `$impersonator` may sign in as `$target` on this panel: never themselves, never
     * while already impersonating, only when the impersonator implements `CanImpersonate`,
     * the target doesn't refuse with `CanBeImpersonated`, the target can access the panel,
     * and the `impersonate` policy ability (when defined) allows it.
     */
    public static function canImpersonate(Panel $panel, ?Authenticatable $impersonator, mixed $target): bool
    {
        if ($impersonator === null || ! $target instanceof Authenticatable || self::isImpersonating($panel)) {
            return false;
        }

        if ((string) $impersonator->getAuthIdentifier() === (string) $target->getAuthIdentifier() && $impersonator::class === $target::class) {
            return false;
        }

        if (! $impersonator instanceof CanImpersonate || ! $impersonator->canImpersonate()) {
            return false;
        }

        if ($target instanceof CanBeImpersonated && ! $target->canBeImpersonated()) {
            return false;
        }

        if ($target instanceof CanAccessPanel && ! $target->canAccessPanel($panel)) {
            return false;
        }

        $gate = Gate::forUser($impersonator);

        if ($target instanceof Model && Gate::getPolicyFor($target) !== null && method_exists(Gate::getPolicyFor($target), 'impersonate')) {
            return $gate->allows('impersonate', $target);
        }

        if (Gate::has('impersonate')) {
            return $gate->allows('impersonate', $target);
        }

        return true;
    }

    /**
     * Sign in as `$target`, remembering the current user so they can leave later.
     */
    public static function start(Panel $panel, Authenticatable $target, ?string $backUrl = null, ?string $redirectUrl = null): RedirectResponse
    {
        $guard = $panel->auth();
        $impersonator = $guard->user();

        abort_unless(self::canImpersonate($panel, $impersonator, $target), 403);

        session()->put(self::sessionKey($panel), [
            'impersonator' => $impersonator?->getAuthIdentifier(),
            'back' => $backUrl ?? url()->previous($panel->getUrl()),
        ]);

        if (method_exists($guard, 'login')) {
            self::switchTo(fn () => $guard->login($target));
        }

        // A new session id for the new identity; the impersonation record is kept.
        session()->migrate(true);

        return redirect()->to($redirectUrl ?? self::homeOf($panel, $target));
    }

    /**
     * Where the impersonated user lands by default: the panel home in a tenant they belong
     * to (the current one when they can open it, else their default tenant), never a tenant
     * of the impersonator's that would answer 404.
     */
    private static function homeOf(Panel $panel, Authenticatable $target): string
    {
        if (! $panel->hasTenancy()) {
            return $panel->getUrl();
        }

        $current = $panel->getTenant();
        $tenant = $current !== null && $target instanceof HasTenants && $target->canAccessTenant($current)
            ? $current
            : $panel->getDefaultTenant($target);

        if ($tenant !== null) {
            return $panel->getUrl($tenant);
        }

        if ($panel->hasTenantRegistration()) {
            return $panel->route('tenant.registration');
        }

        return $panel->isStarterKit() && Route::has('dashboard') ? route('dashboard') : url($panel->getPath());
    }

    /**
     * Return to the real user. Without an impersonation in progress this does nothing.
     */
    public static function leave(Panel $panel): RedirectResponse
    {
        $record = session()->get(self::sessionKey($panel));
        $impersonator = self::getImpersonator($panel);

        if ($impersonator === null) {
            session()->forget(self::sessionKey($panel));

            return redirect()->to($panel->getUrl());
        }

        $guard = $panel->auth();

        if (method_exists($guard, 'login')) {
            self::switchTo(fn () => $guard->login($impersonator));
        }

        session()->forget(self::sessionKey($panel));
        session()->migrate(true);

        $back = is_array($record) && is_string($record['back'] ?? null) ? $record['back'] : null;

        return redirect()->to($back ?? $panel->getUrl());
    }

    /**
     * Whether the current sign-in is an impersonation switch (starting or leaving), not a
     * real sign-in: `Login` listeners that record "last signed in" can skip it.
     */
    public static function isSwitching(): bool
    {
        return self::$switching;
    }

    private static function switchTo(Closure $login): void
    {
        self::$switching = true;

        try {
            $login();
        } finally {
            self::$switching = false;
        }
    }

    public static function isImpersonating(Panel $panel): bool
    {
        $record = session()->get(self::sessionKey($panel));

        return is_array($record) && isset($record['impersonator']);
    }

    public static function getImpersonator(Panel $panel): ?Authenticatable
    {
        $record = session()->get(self::sessionKey($panel));

        if (! is_array($record) || ! isset($record['impersonator'])) {
            return null;
        }

        $guard = $panel->auth();
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        return $provider instanceof UserProvider ? $provider->retrieveById($record['impersonator']) : null;
    }

    /**
     * The banner the renderers show while impersonating.
     *
     * @return array<string, string>|null
     */
    public static function toSchema(Panel $panel): ?array
    {
        $user = $panel->auth()->user();

        if ($user === null || ! self::isImpersonating($panel)) {
            return null;
        }

        return [
            'label' => __('alfakit::impersonation.banner', ['name' => $panel->getUserName($user)]),
            'leaveLabel' => __('alfakit::impersonation.leave'),
            'leaveUrl' => $panel->route('auth.impersonation.leave'),
        ];
    }

    public static function sessionKey(Panel $panel): string
    {
        return 'alfakit.impersonation.'.$panel->getId();
    }
}
