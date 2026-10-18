<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Auth\RegistrationInvitations;
use AlfaSix\Alfakit\Support\Enums\Width;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Customer-facing panels: the `portal()` preset (simple chrome, branded sign-in),
 * invitation-only registration and record scoping to the signed-in user.
 *
 * ```php
 * $panel->id('portal')->path('portal')
 *     ->portal()
 *     ->invitationOnlyRegistration()
 *     ->scopeToUser('customer_id');
 * ```
 */
trait HasPortal
{
    protected bool $isPortal = false;

    protected bool|Closure $hasInvitationOnlyRegistration = false;

    protected int $registrationInvitationDays = 7;

    protected ?string $userOwnership = null;

    protected ?Closure $userOwnerKeyResolver = null;

    /**
     * The customer portal preset: top navigation, no global search, command palette,
     * keyboard shortcuts overlay, recent records or breadcrumbs, a centred content width,
     * the panel's own branded sign-in, password reset and a full profile page, and no
     * "Developed by" footer credit. Every option can be changed after this call.
     */
    public function portal(bool $condition = true): static
    {
        $this->isPortal = $condition;

        if (! $condition) {
            return $this;
        }

        return $this
            ->topNavigation()
            ->globalSearch(false)
            ->commandPalette(false)
            ->keyboardShortcuts(false)
            ->recentRecords(false)
            ->breadcrumbs(false)
            ->maxContentWidth(Width::SixExtraLarge)
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->credit(false);
    }

    public function isPortal(): bool
    {
        return $this->isPortal;
    }

    /**
     * Only people with an invitation link may register: `getRegistrationInvitationUrl()`
     * or `inviteToRegister()` sign a link to the registration page for one email address,
     * valid for `$expiresAfterDays`. Turns registration on.
     */
    public function invitationOnlyRegistration(bool|Closure $condition = true, int $expiresAfterDays = 7): static
    {
        $this->hasInvitationOnlyRegistration = $condition;
        $this->registrationInvitationDays = max(1, $expiresAfterDays);

        if ($condition !== false && ! $this->hasRegistration()) {
            $this->registration();
        }

        return $this;
    }

    public function hasInvitationOnlyRegistration(): bool
    {
        return $this->hasRegistration() && (bool) $this->evaluate($this->hasInvitationOnlyRegistration);
    }

    public function getRegistrationInvitationDays(): int
    {
        return $this->registrationInvitationDays;
    }

    /**
     * The name of the registration route, for signed invitation links.
     */
    public function getRegistrationRouteName(): string
    {
        return $this->generateRouteName('auth.register');
    }

    /**
     * A signed link to the registration page for this email address only.
     */
    public function getRegistrationInvitationUrl(string $email, DateTimeInterface|int|null $expiresAt = null): string
    {
        return RegistrationInvitations::url($this, $email, $expiresAt);
    }

    /**
     * Email an invitation link to `$email` and return it.
     */
    public function inviteToRegister(string $email, DateTimeInterface|int|null $expiresAt = null): string
    {
        return RegistrationInvitations::send($this, $email, $expiresAt);
    }

    /**
     * Scope every resource of the panel to the signed-in user: a column (`customer_id`) or a
     * relationship of the model (`customer`, `members`) that points at the owner. The
     * owner's key is the user's id, or what `$ownerKey($user)` returns (e.g. the customer
     * the user belongs to). Resources opt out with `$userOwnership = false`, or pick their
     * own with `Resource::scopeToUser()` / `Resource::make('key')->scopeToUser()`. New
     * records get the owner filled in.
     *
     * @param  (Closure(Authenticatable): mixed)|null  $ownerKey
     */
    public function scopeToUser(?string $ownership = 'user_id', ?Closure $ownerKey = null): static
    {
        $this->userOwnership = $ownership;
        $this->userOwnerKeyResolver = $ownerKey;

        return $this;
    }

    public function getUserOwnership(): ?string
    {
        return $this->userOwnership;
    }

    public function getUserOwnerKeyResolver(): ?Closure
    {
        return $this->userOwnerKeyResolver;
    }

    /**
     * Whether signed-in users must pass `CanAccessPanel::canAccessPanel()` to use this
     * panel's pages and internal endpoints. The app auth kit serves every user of the app
     * (their own account), so it doesn't.
     */
    public function requiresPanelAccessCheck(): bool
    {
        return true;
    }
}
