<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\AvatarProviders\Contracts\AvatarProvider;
use AlfaSix\Alfakit\Contracts\HasName;
use AlfaSix\Alfakit\Theming\ThemeMode;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Panel chrome: the top bar, the theme switcher and default colour scheme, the tenant
 * menu's search and switcher, default avatars and global search field options.
 */
trait HasChromeOptions
{
    protected bool|Closure $hasTopbar = true;

    protected bool|Closure $hasThemeSwitcher = true;

    protected ThemeMode $defaultThemeMode = ThemeMode::System;

    protected bool|Closure|null $isTenantMenuSearchable = null;

    protected bool|Closure $hasTenantSwitcher = true;

    /** @var class-string<AvatarProvider>|AvatarProvider|Closure|null */
    protected string|AvatarProvider|Closure|null $defaultAvatarProvider = null;

    protected bool $isGlobalSearchResourceOptIn = false;

    protected string|int|Closure|null $globalSearchDebounce = null;

    protected string|Closure|null $globalSearchFieldSuffix = null;

    protected bool $hasGlobalSearchFieldKeyBindingSuffix = true;

    // ---------------------------------------------------------------- Top bar & theme

    /**
     * Whether sidebar layouts show the top bar (breadcrumbs, global search, notifications,
     * theme switcher). Without it, phones keep a slim bar with the sidebar toggle. The top
     * navigation layout always keeps its bar: it holds the navigation.
     */
    public function topbar(bool|Closure $condition = true): static
    {
        $this->hasTopbar = $condition;

        return $this;
    }

    public function hasTopbar(): bool
    {
        return (bool) $this->evaluate($this->hasTopbar);
    }

    /**
     * Whether users can switch between light, dark and system colours. Without the switcher
     * the panel always uses `defaultThemeMode()` (dark mode support stays on).
     */
    public function themeSwitcher(bool|Closure $condition = true): static
    {
        $this->hasThemeSwitcher = $condition;

        return $this;
    }

    public function hasThemeSwitcher(): bool
    {
        return (bool) $this->evaluate($this->hasThemeSwitcher);
    }

    /**
     * The colour scheme users start with (and keep, without the theme switcher).
     */
    public function defaultThemeMode(ThemeMode $mode): static
    {
        $this->defaultThemeMode = $mode;

        return $this;
    }

    public function getDefaultThemeMode(): ThemeMode
    {
        return $this->defaultThemeMode;
    }

    /**
     * The colour scheme for this request from the stored choice (`alfakit_appearance`
     * cookie), within the panel's `darkMode()`, `themeSwitcher()` and `defaultThemeMode()`.
     *
     * @return 'light'|'dark'|'system'
     */
    public function resolveAppearance(?string $stored): string
    {
        if ($this->hasDarkModeForced()) {
            return 'dark';
        }

        if (! $this->hasDarkMode()) {
            return 'light';
        }

        if ($this->hasThemeSwitcher() && in_array($stored, ['light', 'dark', 'system'], true)) {
            return $stored;
        }

        return $this->defaultThemeMode->value;
    }

    // ---------------------------------------------------------------- Tenant menu

    /**
     * A search field in the tenant menu. By default it appears when the user has more
     * than 10 tenants.
     */
    public function searchableTenantMenu(bool|Closure|null $condition = true): static
    {
        $this->isTenantMenuSearchable = $condition;

        return $this;
    }

    public function isTenantMenuSearchable(int $tenantCount): bool
    {
        $searchable = $this->evaluate($this->isTenantMenuSearchable);

        return $searchable === null ? $tenantCount > 10 : (bool) $searchable;
    }

    /**
     * Whether the tenant menu lists the user's other tenants to switch to. Without it the
     * menu keeps the current tenant and its menu items.
     */
    public function tenantSwitcher(bool|Closure $condition = true): static
    {
        $this->hasTenantSwitcher = $condition;

        return $this;
    }

    public function hasTenantSwitcher(): bool
    {
        return (bool) $this->evaluate($this->hasTenantSwitcher);
    }

    // ---------------------------------------------------------------- Avatars

    /**
     * Makes avatars for users and tenants that have none (`HasAvatar` returning null and
     * no `avatar_url` attribute): `InitialsAvatarProvider` (drawn on the server),
     * `UiAvatarsProvider` (ui-avatars.com) or your own `AvatarProvider`. Without one, the
     * renderer shows initials.
     *
     * @param  class-string<AvatarProvider>|AvatarProvider|Closure|null  $provider
     */
    public function defaultAvatarProvider(string|AvatarProvider|Closure|null $provider): static
    {
        if (is_string($provider) && ! is_subclass_of($provider, AvatarProvider::class)) {
            throw new InvalidArgumentException(sprintf('Avatar provider [%s] must implement %s.', $provider, AvatarProvider::class));
        }

        $this->defaultAvatarProvider = $provider;

        return $this;
    }

    public function getDefaultAvatarProvider(): ?AvatarProvider
    {
        $provider = $this->defaultAvatarProvider instanceof Closure ? $this->evaluate($this->defaultAvatarProvider) : $this->defaultAvatarProvider;

        if (is_string($provider)) {
            $provider = app($provider);
        }

        return $provider instanceof AvatarProvider ? $provider : null;
    }

    public function getDefaultAvatarUrl(Model|Authenticatable $record): ?string
    {
        return $this->getDefaultAvatarProvider()?->get($record);
    }

    /**
     * The name avatar providers draw initials from: the user's name (`userName()`,
     * `HasName`) or the tenant's.
     */
    public function getNameForDefaultAvatar(Model|Authenticatable $record): string
    {
        if ($record instanceof Authenticatable) {
            return $this->getUserName($record);
        }

        if ($record instanceof HasName) {
            return \AlfaSix\Alfakit\Support\Html::toText($record->getAlfakitName());
        }

        return (string) ($record->getAttribute('name') ?? $record->getKey());
    }

    // ---------------------------------------------------------------- Global search

    /**
     * Only resources that set `protected static bool $isGloballySearchable = true;`
     * themselves appear in global search.
     */
    public function globalSearchResourceOptIn(bool $condition = true): static
    {
        $this->isGlobalSearchResourceOptIn = $condition;

        return $this;
    }

    public function isGlobalSearchResourceOptIn(): bool
    {
        return $this->isGlobalSearchResourceOptIn;
    }

    /**
     * @param  array<string>  $keyBindings  e.g. `['mod+k', 'ctrl+/']`
     */
    public function globalSearchKeyBindings(array $keyBindings): static
    {
        $this->globalSearchKeyBindings = array_values($keyBindings);

        return $this;
    }

    /**
     * How long to wait after typing before searching: `'500ms'`, `'1s'` or milliseconds.
     * Defaults to 250ms.
     */
    public function globalSearchDebounce(string|int|Closure|null $debounce): static
    {
        $this->globalSearchDebounce = $debounce;

        return $this;
    }

    public function getGlobalSearchDebounce(): ?int
    {
        $value = $this->evaluate($this->globalSearchDebounce);

        if (is_int($value)) {
            return max(0, $value);
        }

        if (! is_string($value) || preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ms|s)?\s*$/', $value, $match) !== 1) {
            return null;
        }

        return (int) round((float) $match[1] * (($match[2] ?? 'ms') === 's' ? 1000 : 1));
    }

    /**
     * Text at the end of the search field instead of the key binding hint.
     */
    public function globalSearchFieldSuffix(string|Closure|null $suffix): static
    {
        $this->globalSearchFieldSuffix = $suffix;

        return $this;
    }

    public function getGlobalSearchFieldSuffix(): ?string
    {
        $suffix = $this->evaluate($this->globalSearchFieldSuffix);

        return is_string($suffix) && $suffix !== '' ? $suffix : null;
    }

    /**
     * Whether the field shows the first key binding (`⌘K` / `Ctrl K`) as its suffix. On
     * by default.
     */
    public function globalSearchFieldKeyBindingSuffix(bool $condition = true): static
    {
        $this->hasGlobalSearchFieldKeyBindingSuffix = $condition;

        return $this;
    }

    public function hasGlobalSearchFieldKeyBindingSuffix(): bool
    {
        return $this->hasGlobalSearchFieldKeyBindingSuffix;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getGlobalSearchFieldSchema(): array
    {
        return array_filter([
            'debounce' => $this->getGlobalSearchDebounce(),
            'suffix' => $this->getGlobalSearchFieldSuffix(),
            'keyBindingSuffix' => $this->hasGlobalSearchFieldKeyBindingSuffix ? null : false,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
