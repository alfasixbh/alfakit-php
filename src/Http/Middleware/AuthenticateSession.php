<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Panel;
use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a session out once the user's password is no longer the one it signed in with, so
 * "log out other sessions" and a password change end every other session on any session
 * driver. The panel's own guard is used (Laravel's middleware of the same name only
 * watches the default guard), and the session remembers whose password it holds, so
 * impersonating or signing in as someone else starts afresh.
 */
final class AuthenticateSession
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        return $this->handleFor(alfakit()->getPanel($panelId), $request, $next);
    }

    public function handleFor(Panel $panel, Request $request, Closure $next): Response
    {
        $guard = $panel->auth();
        $user = $guard->user();

        if (! $guard instanceof StatefulGuard || ! $request->hasSession() || $user === null || ! $user->getAuthPassword()) {
            return $next($request);
        }

        $stored = $request->session()->get(self::key($panel));
        $stale = is_string($stored) && str_starts_with($stored, $user->getAuthIdentifier().'|') && ! hash_equals(self::fingerprint($user), $stored);

        // A "remember me" cookie carries the password hash it was issued with: a device
        // whose session was ended must not sign straight back in with an old cookie.
        if (! $stale && method_exists($guard, 'viaRemember') && $guard->viaRemember() && method_exists($guard, 'getRecallerName')) {
            $cookie = $request->cookies->get($guard->getRecallerName());
            $hash = is_string($cookie) ? (explode('|', $cookie)[2] ?? null) : null;
            $stale = $hash === null || $hash === '' || ! self::matchesRecallerHash($guard, (string) $user->getAuthPassword(), $hash);
        }

        if ($stale) {
            method_exists($guard, 'logoutCurrentDevice') ? $guard->logoutCurrentDevice() : $guard->logout();
            $request->session()->flush();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(401);
            }

            $login = $panel->getLoginUrl();

            abort_if($login === null, 401);

            return redirect()->guest($login);
        }

        // The first request of this user on this session: remember the password it has now.
        // Later changes made by this session itself are followed by `keepUpWithPasswordChange()`
        // (never by re-reading here, which would also keep a session that only happened to
        // be in a request while another one changed the password).
        if (! is_string($stored) || ! str_starts_with($stored, $user->getAuthIdentifier().'|')) {
            self::remember($request, $panel, $user);
        }

        return $next($request);
    }

    /**
     * Keep this session signed in with the user's current password.
     */
    public static function remember(Request $request, Panel $panel, Authenticatable $user): void
    {
        if (! $request->hasSession() || ! $user->getAuthPassword()) {
            return;
        }

        self::store($request, $panel->getAuthGuard(), $user);
    }

    private static function store(Request $request, string $guard, Authenticatable $user): void
    {
        $request->session()->put('alfakit.password_hash.'.$guard, self::fingerprint($user));

        // Laravel's own `AuthenticateSession`, when the application uses it too.
        $request->session()->put('password_hash_'.$guard, $user->getAuthPassword());
    }

    /**
     * Listener for the user models' `updated` event: when the request that changed a
     * password belongs to a session signed in as that user, the session keeps up with it.
     * A request of another session does not save the password, so it is not kept.
     */
    public static function keepUpWithPasswordChange(mixed $user): void
    {
        if (! $user instanceof Model || ! $user instanceof Authenticatable || ! $user->wasChanged($user->getAuthPasswordName())) {
            return;
        }

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        foreach ((array) config('auth.guards', []) as $name => $config) {
            if (! is_string($name) || ($config['driver'] ?? null) !== 'session') {
                continue;
            }

            $guard = Auth::guard($name);

            // `id()` reads the session: no query, and no guess about another guard's user.
            $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

            if ($guard->id() !== null && (string) $guard->id() === (string) $user->getAuthIdentifier()
                && $provider instanceof EloquentUserProvider && is_a($user, $provider->getModel())) {
                self::store($request, $name, $user);
            }
        }
    }

    private static function matchesRecallerHash(StatefulGuard $guard, string $password, string $hash): bool
    {
        // Laravel 12+ stores an HMAC of the hash in the cookie; older cookies hold the hash itself.
        if (method_exists($guard, 'hashPasswordForCookie') && hash_equals((string) $guard->hashPasswordForCookie($password), $hash)) {
            return true;
        }

        return hash_equals($password, $hash);
    }

    private static function key(Panel $panel): string
    {
        return 'alfakit.password_hash.'.$panel->getAuthGuard();
    }

    private static function fingerprint(Authenticatable $user): string
    {
        return $user->getAuthIdentifier().'|'.hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('app.key'));
    }
}
