<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

/**
 * The paths of the authentication pages (Filament's names), relative to the panel path:
 *
 * ```php
 * $panel->loginRouteSlug('sign-in')
 *     ->registrationRouteSlug('sign-up')
 *     ->passwordResetRoutePrefix('forgot')->passwordResetRequestRouteSlug('start')->passwordResetRouteSlug('new')
 *     ->emailVerificationRoutePrefix('verify')->emailVerificationPromptRouteSlug('notice')->emailVerificationRouteSlug('confirm')
 *     ->emailChangeVerificationRoutePrefix('email-change')->emailChangeVerificationRouteSlug('confirm');
 * ```
 *
 * Route names stay the same (`auth.login`, …), so `$panel->getLoginUrl()` and friends follow.
 */
trait HasAuthRouteSlugs
{
    protected string $loginRouteSlug = 'login';

    protected string $registrationRouteSlug = 'register';

    protected string $passwordResetRoutePrefix = 'password-reset';

    protected string $passwordResetRequestRouteSlug = 'request';

    protected string $passwordResetRouteSlug = 'reset';

    protected string $emailVerificationRoutePrefix = 'email-verification';

    protected string $emailVerificationPromptRouteSlug = 'prompt';

    protected string $emailVerificationRouteSlug = 'verify';

    protected string $emailChangeVerificationRoutePrefix = 'email-change-verification';

    protected string $emailChangeVerificationRouteSlug = 'verify';

    protected bool $arePasswordsRevealable = true;

    public function loginRouteSlug(string $slug): static
    {
        $this->loginRouteSlug = $slug;

        return $this;
    }

    public function registrationRouteSlug(string $slug): static
    {
        $this->registrationRouteSlug = $slug;

        return $this;
    }

    public function passwordResetRoutePrefix(string $prefix): static
    {
        $this->passwordResetRoutePrefix = $prefix;

        return $this;
    }

    public function passwordResetRequestRouteSlug(string $slug): static
    {
        $this->passwordResetRequestRouteSlug = $slug;

        return $this;
    }

    public function passwordResetRouteSlug(string $slug): static
    {
        $this->passwordResetRouteSlug = $slug;

        return $this;
    }

    public function emailVerificationRoutePrefix(string $prefix): static
    {
        $this->emailVerificationRoutePrefix = $prefix;

        return $this;
    }

    public function emailVerificationPromptRouteSlug(string $slug): static
    {
        $this->emailVerificationPromptRouteSlug = $slug;

        return $this;
    }

    public function emailVerificationRouteSlug(string $slug): static
    {
        $this->emailVerificationRouteSlug = $slug;

        return $this;
    }

    public function emailChangeVerificationRoutePrefix(string $prefix): static
    {
        $this->emailChangeVerificationRoutePrefix = $prefix;

        return $this;
    }

    public function emailChangeVerificationRouteSlug(string $slug): static
    {
        $this->emailChangeVerificationRouteSlug = $slug;

        return $this;
    }

    public function getLoginRoutePath(): string
    {
        return $this->authRoutePath($this->loginRouteSlug);
    }

    public function getRegistrationRoutePath(): string
    {
        return $this->authRoutePath($this->registrationRouteSlug);
    }

    public function getPasswordResetRequestRoutePath(): string
    {
        return $this->authRoutePath($this->passwordResetRoutePrefix, $this->passwordResetRequestRouteSlug);
    }

    public function getPasswordResetRoutePath(): string
    {
        return $this->authRoutePath($this->passwordResetRoutePrefix, $this->passwordResetRouteSlug);
    }

    public function getEmailVerificationPromptRoutePath(): string
    {
        return $this->authRoutePath($this->emailVerificationRoutePrefix, $this->emailVerificationPromptRouteSlug);
    }

    public function getEmailVerificationRoutePath(): string
    {
        return $this->authRoutePath($this->emailVerificationRoutePrefix, $this->emailVerificationRouteSlug);
    }

    public function getEmailChangeVerificationRoutePath(): string
    {
        return $this->authRoutePath($this->emailChangeVerificationRoutePrefix, $this->emailChangeVerificationRouteSlug);
    }

    /**
     * The path of the link that blocks a pending email change (next to the verify link).
     */
    public function getEmailChangeBlockRoutePath(): string
    {
        return $this->authRoutePath($this->emailChangeVerificationRoutePrefix, 'block');
    }

    /**
     * `revealablePasswords(false)`: password inputs on the authentication and profile
     * pages get no show/hide toggle.
     */
    public function revealablePasswords(bool $condition = true): static
    {
        $this->arePasswordsRevealable = $condition;

        return $this;
    }

    public function arePasswordsRevealable(): bool
    {
        return $this->arePasswordsRevealable;
    }

    protected function authRoutePath(string ...$segments): string
    {
        $segments = array_values(array_filter(array_map(static fn (string $segment): string => trim($segment, '/'), $segments), static fn (string $segment): bool => $segment !== ''));

        return '/'.implode('/', $segments);
    }
}
