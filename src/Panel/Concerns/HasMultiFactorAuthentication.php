<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Auth\Pages\MultiFactorChallenge;
use AlfaSix\Alfakit\Http\Controllers\Auth\DownloadRecoveryCodesController;
use AlfaSix\Alfakit\Http\Middleware\Authenticate;
use AlfaSix\Alfakit\Http\Middleware\EnsureMultiFactorAuthenticationIsSetUp;
use AlfaSix\Alfakit\Pages\Page;
use Closure;
use Illuminate\Routing\Router;

/**
 * Second factors after the password: `->multiFactorAuthentication([AppAuthentication::make(),
 * EmailAuthentication::make()], isRequired: true)`.
 */
trait HasMultiFactorAuthentication
{
    /** @var array<string, MultiFactorAuthenticationProvider> */
    protected array $multiFactorAuthenticationProviders = [];

    protected bool|Closure $isMultiFactorAuthenticationRequired = false;

    protected ?int $multiFactorAuthenticationRememberDeviceDays = null;

    /**
     * @param  array<MultiFactorAuthenticationProvider>|MultiFactorAuthenticationProvider  $providers
     * @param  bool|Closure  $isRequired  Users without a second factor can only use the profile page until they set one up.
     */
    public function multiFactorAuthentication(array|MultiFactorAuthenticationProvider $providers, bool|Closure $isRequired = false): static
    {
        $this->multiFactorAuthenticationProviders = [];

        foreach (is_array($providers) ? $providers : [$providers] as $provider) {
            $this->multiFactorAuthenticationProviders[$provider->getId()] = $provider;
        }

        $this->isMultiFactorAuthenticationRequired = $isRequired;

        return $this;
    }

    /**
     * Offer "trust this device" on the challenge page: the challenge is skipped on that
     * browser for `$days` days (`false` turns it off). Changing the password or a second
     * factor ends the trust.
     */
    public function multiFactorAuthenticationRememberDevice(int|bool $days = 30): static
    {
        $this->multiFactorAuthenticationRememberDeviceDays = $days === false ? null : ($days === true ? 30 : max(1, $days));

        return $this;
    }

    /**
     * `starterKit()` panels sign users in with the host's login (Fortify), whose two-factor
     * settings protect it: Alfakit's challenge and set-up belong to Alfakit's own login and
     * profile pages, so the providers are ignored there unless `->login()` brings the
     * panel's login back.
     */
    public function hasMultiFactorAuthentication(): bool
    {
        if ($this->isStarterKit() && ! $this->hasLogin()) {
            return false;
        }

        return $this->multiFactorAuthenticationProviders !== [];
    }

    public function isMultiFactorAuthenticationRequired(): bool
    {
        return $this->hasMultiFactorAuthentication() && (bool) $this->evaluate($this->isMultiFactorAuthenticationRequired);
    }

    /**
     * @return array<string, MultiFactorAuthenticationProvider>
     */
    public function getMultiFactorAuthenticationProviders(): array
    {
        return $this->multiFactorAuthenticationProviders;
    }

    public function getMultiFactorAuthenticationProvider(string $id): ?MultiFactorAuthenticationProvider
    {
        return $this->multiFactorAuthenticationProviders[$id] ?? null;
    }

    public function getMultiFactorAuthenticationRememberDeviceDays(): ?int
    {
        return $this->multiFactorAuthenticationRememberDeviceDays;
    }

    public function getMultiFactorChallengeUrl(?string $method = null): string
    {
        return $this->route('auth.multi-factor-challenge', array_filter(['method' => $method]));
    }

    public function getRecoveryCodesDownloadUrl(): string
    {
        return $this->route('auth.multi-factor.recovery-codes');
    }

    /**
     * @return class-string<Page>|null
     */
    public function getProfilePage(): ?string
    {
        return $this->hasProfile() ? $this->profilePage : null;
    }

    /**
     * @return array<string>
     */
    protected function getMultiFactorAuthenticationMiddleware(): array
    {
        return $this->hasMultiFactorAuthentication() ? [EnsureMultiFactorAuthenticationIsSetUp::class.':'.$this->getId()] : [];
    }

    protected function registerMultiFactorAuthenticationRoutes(Router $router): void
    {
        if (! $this->hasMultiFactorAuthentication()) {
            return;
        }

        if ($this->hasLogin()) {
            $router->get('/multi-factor-challenge/{method?}', MultiFactorChallenge::class)
                ->name('auth.multi-factor-challenge')
                ->where('method', '[a-z0-9_-]+')
                ->middleware('guest:'.$this->getAuthGuard());
        }

        $router->get('/profile/recovery-codes', DownloadRecoveryCodesController::class)
            ->name('auth.multi-factor.recovery-codes')
            ->middleware([Authenticate::class.':'.$this->getId()]);
    }
}
