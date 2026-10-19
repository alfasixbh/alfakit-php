<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit;

use AlfaSix\Alfakit\AuthKit\Http\AuthKitController;
use AlfaSix\Alfakit\AuthKit\Http\Middleware\Authenticate;
use AlfaSix\Alfakit\Http\Controllers\Auth\DownloadRecoveryCodesController;
use AlfaSix\Alfakit\Http\Middleware\SetPanelLocale;
use AlfaSix\Alfakit\Standalone\StandalonePanel;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * The hidden panel behind the app auth kit: sign-in, registration, password reset, email
 * verification, two-step verification and the account page for the application's own
 * users, outside any panel. Its pages are standalone components rendered by two Inertia
 * pages of the app (`inertiaPages()`), in the app's own layouts.
 *
 * Configure it once (`AuthKit::configure()`), register the routes in `routes/web.php`
 * (`AuthKit::routes()`). Every panel option that makes sense for authentication works:
 * `authGuard()`, `authPasswordBroker()`, `revealablePasswords()`, `multiFactorAuthentication()`,
 * `emailChangeVerification()`, `homeUrl()`, `invitationOnlyRegistration()`…
 */
class AuthKitPanel extends StandalonePanel
{
    public const ID = 'auth-kit';

    protected string $guestPage = 'auth/alfakit';

    protected string $accountPage = 'settings/alfakit';

    protected bool $hasSessions = true;

    protected bool|Closure $hasAccountDeletion = true;

    protected ?Closure $deleteUsersUsing = null;

    protected bool $usesFortify = false;

    /** Prefix of the page route names (`login`, `register`…), `as:` of `AuthKit::routes()`. */
    protected string $pageRouteNamePrefix = '';

    /** @var array<string, class-string<\AlfaSix\Alfakit\Standalone\Component>> */
    protected array $components = [
        'login' => Pages\Login::class,
        'register' => Pages\Register::class,
        'forgot-password' => Pages\ForgotPassword::class,
        'reset-password' => Pages\ResetPassword::class,
        'verify-email' => Pages\VerifyEmail::class,
        'two-factor-challenge' => Pages\TwoFactorChallenge::class,
        'profile' => Account\Profile::class,
        'password' => Account\UpdatePassword::class,
        'two-factor' => Account\TwoFactor::class,
        'sessions' => Account\Sessions::class,
        'delete-account' => Account\DeleteAccount::class,
    ];

    public static function make(string $id = self::ID): static
    {
        $panel = new static(self::ID);
        $panel->configure();

        $panel
            ->authGuard('web')
            ->middleware(['web'])
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->revealablePasswords()
            ->globalSearch(false)
            ->localeSwitcher(false)
            ->brandName((string) config('app.name', 'Laravel'))
            ->homeUrl(static fn (): string => Route::has('dashboard') ? route('dashboard') : url('/'));

        return $panel;
    }

    // ---------------------------------------------------------------- Options

    /**
     * The app's Inertia pages that render the auth kit: one for the guest pages (sign-in,
     * registration…), one for the account page. Starter kits lay out `auth/*` pages in
     * their auth layout and `settings/*` pages in their settings layout.
     */
    public function inertiaPages(string $guest = 'auth/alfakit', string $account = 'settings/alfakit'): static
    {
        $this->guestPage = $guest;
        $this->accountPage = $account;

        return $this;
    }

    public function getGuestPage(): string
    {
        return $this->guestPage;
    }

    public function getAccountPage(): string
    {
        return $this->accountPage;
    }

    /**
     * Replace a page or account section with your own class (extend the default one):
     * `components(['login' => App\Auth\Login::class, 'profile' => App\Auth\Profile::class])`.
     *
     * @param  array<string, class-string<\AlfaSix\Alfakit\Standalone\Component>>  $components
     */
    public function components(array $components): static
    {
        $this->components = [...$this->components, ...$components];

        return $this;
    }

    /**
     * @return class-string<\AlfaSix\Alfakit\Standalone\Component>
     */
    public function getComponent(string $key): string
    {
        return $this->components[$key] ?? throw new InvalidArgumentException("Unknown auth kit component [{$key}].");
    }

    /**
     * The user's browser sessions on the account page, with "sign out other sessions"
     * (the list needs the `database` session driver).
     */
    public function sessions(bool $condition = true): static
    {
        $this->hasSessions = $condition;

        return $this;
    }

    public function hasSessions(): bool
    {
        return $this->hasSessions && $this->hasProfile();
    }

    /**
     * "Delete account" on the account page. `$using` deletes the user your way
     * (`fn (User $user) => $user->anonymize()`); by default the model is deleted.
     */
    public function accountDeletion(bool|Closure $condition = true, ?Closure $using = null): static
    {
        $this->hasAccountDeletion = $condition;
        $this->deleteUsersUsing = $using;

        return $this;
    }

    public function hasAccountDeletion(): bool
    {
        return $this->hasProfile() && (bool) $this->evaluate($this->hasAccountDeletion);
    }

    public function deleteUser(Authenticatable $user): void
    {
        if ($this->deleteUsersUsing !== null) {
            $this->evaluate($this->deleteUsersUsing, ['user' => $user], [Authenticatable::class => $user]);

            return;
        }

        if ($user instanceof Model) {
            $user->delete();
        }
    }

    /**
     * Use Laravel Fortify's backend: its `authenticateUsing()` callback and username, and
     * the app's Fortify actions (`CreateNewUser`, `ResetUserPassword`, `UpdateUserPassword`,
     * `UpdateUserProfileInformation`) for registration, password resets and the account
     * page. Users with Fortify two-factor authentication answer its challenge. Turn
     * Fortify's own views off (`'views' => false` in `config/fortify.php`).
     */
    public function fortify(bool $condition = true): static
    {
        $this->usesFortify = $condition;

        return $this;
    }

    public function usesFortify(): bool
    {
        return $this->usesFortify && Support\Fortify::isInstalled();
    }

    // ---------------------------------------------------------------- Routes & URLs

    /**
     * The path prefix of every auth kit route (`account` → `/account/login`) and the prefix
     * of the page route names (`kit.` → `kit.login`). `AuthKit::routes()` sets them too.
     */
    public function routeOptions(?string $prefix = '', ?string $as = ''): static
    {
        if ($prefix !== null) {
            $this->path(trim($prefix, '/'));
        }

        if ($as !== null) {
            $this->pageRouteNamePrefix = $as;
        }

        return $this;
    }

    public function getPageRouteName(string $name): string
    {
        return $this->pageRouteNamePrefix.$name;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function pageRoute(string $name, array $parameters = []): ?string
    {
        $route = $this->getPageRouteName($name);

        return Route::has($route) ? route($route, $parameters) : null;
    }

    public function getUrl(?Model $tenant = null): string
    {
        return $this->evaluate($this->homeUrl) ?? url('/');
    }

    public function getLoginUrl(): ?string
    {
        return $this->hasLogin() ? $this->pageRoute('login') : null;
    }

    public function getRegistrationUrl(): ?string
    {
        return $this->hasRegistration() ? $this->pageRoute('register') : null;
    }

    public function getRegistrationRouteName(): string
    {
        return $this->getPageRouteName('register');
    }

    public function getPasswordResetUrl(): ?string
    {
        return $this->hasPasswordReset() ? $this->pageRoute('password.request') : null;
    }

    public function getLogoutUrl(): string
    {
        return $this->pageRoute('logout') ?? url('/');
    }

    public function getProfileUrl(): ?string
    {
        return $this->hasProfile() ? $this->pageRoute('account') : null;
    }

    public function getEmailVerificationPromptUrl(): ?string
    {
        return $this->hasEmailVerification() ? $this->pageRoute('verification.notice') : null;
    }

    public function getMultiFactorChallengeUrl(?string $method = null): string
    {
        return $this->pageRoute('two-factor.login', array_filter(['method' => $method])) ?? url('/');
    }

    /**
     * Like other standalone components the auth kit follows the app's locale. Give it
     * `locales()` to have it pick the user's language itself (their preference, the
     * `alfakit_locale` cookie, or the browser's with `detectBrowserLocale()`).
     *
     * @return array<string|Closure>
     */
    public function getMiddleware(): array
    {
        $middleware = parent::getMiddleware();

        if ($this->locales === []) {
            return $middleware;
        }

        // After the session and the user are available (the `web` group), before the flash data.
        $last = array_pop($middleware);

        return [...$middleware, SetPanelLocale::class, $last];
    }

    /**
     * Account pages are every user's own: no `CanAccessPanel` check.
     */
    public function requiresPanelAccessCheck(): bool
    {
        return false;
    }

    protected function getInternalRoutePrefix(): string
    {
        return parent::getInternalRoutePrefix().'/auth';
    }

    /**
     * The internal endpoints (`/_alfakit/auth/*`), the pages under Laravel's conventional
     * route names (`login`, `register`, `password.request`, `password.reset`,
     * `verification.notice`, `verification.verify`, `two-factor.login`, `account`, `logout`)
     * and the recovery codes download.
     */
    public function registerRoutes(Router $router): void
    {
        parent::registerRoutes($router);

        $guest = 'guest:'.$this->getAuthGuard();
        $auth = Authenticate::class;

        // Fortify registers `verification.verify` even with its views off. Taking over its URI
        // replaces that route, so the name isn't registered twice (which breaks Wayfinder).
        $verifyUri = $this->hasEmailVerification() ? $this->existingRouteUri($router, $this->pageRouteNamePrefix.'verification.verify') : null;

        if ($verifyUri !== null) {
            $router->middleware($this->getMiddleware())
                ->get($verifyUri, [AuthKitController::class, 'verify'])
                ->name($this->pageRouteNamePrefix.'verification.verify')
                ->middleware([$auth, 'signed', 'throttle:6,1']);
        }

        $router->prefix($this->getPath())
            ->name($this->pageRouteNamePrefix)
            ->middleware($this->getMiddleware())
            ->group(function (Router $router) use ($guest, $auth, $verifyUri): void {
                if ($this->hasLogin()) {
                    $router->get('/login', [AuthKitController::class, 'login'])->name('login')->middleware($guest);
                }

                $router->post('/logout', [AuthKitController::class, 'logout'])->name('logout');

                if ($this->hasRegistration()) {
                    $router->get('/register', [AuthKitController::class, 'register'])->name('register')->middleware($guest);
                }

                if ($this->hasPasswordReset()) {
                    $router->get('/forgot-password', [AuthKitController::class, 'forgotPassword'])->name('password.request')->middleware($guest);
                    $router->get('/reset-password/{token}', [AuthKitController::class, 'resetPassword'])->name('password.reset')->middleware($guest);
                }

                if ($this->hasEmailVerification()) {
                    $router->get('/verify-email', [AuthKitController::class, 'verifyEmail'])->name('verification.notice')->middleware($auth);

                    if ($verifyUri === null) {
                        $router->get('/verify-email/{id}/{hash}', [AuthKitController::class, 'verify'])->name('verification.verify')->middleware([$auth, 'signed', 'throttle:6,1']);
                    }
                }

                if ($this->hasLogin()) {
                    $router->get('/two-factor-challenge/{method?}', [AuthKitController::class, 'twoFactorChallenge'])
                        ->name('two-factor.login')
                        ->where('method', '[a-z0-9_-]+')
                        ->middleware($guest);
                }

                if ($this->hasProfile()) {
                    $router->get('/account', [AuthKitController::class, 'account'])->name('account')->middleware($auth);
                }
            });

        // Routes the shared auth code links to by the panel's own route names.
        $router->prefix($this->getPath())
            ->name($this->getRouteNamePrefix())
            ->middleware($this->getMiddleware())
            ->group(function (Router $router) use ($auth): void {
                if ($this->hasMultiFactorAuthentication()) {
                    $router->get('/account/recovery-codes', DownloadRecoveryCodesController::class)
                        ->name('auth.multi-factor.recovery-codes')
                        ->middleware($auth);
                }

                $this->registerEmailChangeVerificationRoutes($router);
            });
    }

    /**
     * The URI of a route another package (Fortify) already registered under `$name`.
     */
    protected function existingRouteUri(Router $router, string $name): ?string
    {
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return '/'.ltrim($route->uri(), '/');
            }
        }

        return null;
    }
}
