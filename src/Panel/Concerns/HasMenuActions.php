<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;

/**
 * The user and tenant menus. `userMenuItems()` / `tenantMenuItems()` take `Action`s (or
 * the older `MenuItem`s): plain links, POST links, or actions with server logic and modals
 * (run through the action endpoint as the `userMenu` / `tenantMenu` component). Nested
 * arrays are groups, separated by a divider. String keys customise the built-in entries:
 * `profile` and `logout` in the user menu, `profile`, `register` and `billing` in the
 * tenant menu, as an `Action`/`MenuItem` or a closure `fn (Action $action) => $action->label('…')`.
 */
trait HasMenuActions
{
    /** Action endpoint component of user menu actions. */
    public const USER_MENU_COMPONENT = 'userMenu';

    /** Action endpoint component of tenant menu actions. */
    public const TENANT_MENU_COMPONENT = 'tenantMenu';

    /** Keys that customise a built-in user menu entry. */
    public const USER_MENU_KEYS = ['profile', 'logout'];

    /** Keys that customise a built-in tenant menu entry. */
    public const TENANT_MENU_KEYS = ['profile', 'register', 'billing'];

    /**
     * Split configured items into ordered entries (with their group) and keyed overrides.
     *
     * @param  array<array-key, mixed>  $items
     * @param  array<int, string>  $keys
     * @return array{entries: array<int, array{0: Action|MenuItem, 1: int}>, overrides: array<string, mixed>}
     */
    protected function normalizeMenuItems(array $items, array $keys): array
    {
        $entries = [];
        $overrides = [];
        $group = 0;
        // Whether the current group already has loose (non-array) items.
        $hasLooseItems = false;

        $add = function (mixed $key, mixed $item, int $group) use (&$entries, &$overrides, $keys): bool {
            if (is_string($key) && in_array($key, $keys, true)) {
                $overrides[$key] = $item;

                return false;
            }

            if ($item instanceof Action || $item instanceof MenuItem) {
                $entries[] = [$item, $group];

                return true;
            }

            return false;
        };

        foreach ($items as $key => $item) {
            if (is_array($item)) {
                // Each nested array is its own group.
                if ($hasLooseItems) {
                    $group++;
                }

                foreach ($item as $nestedKey => $nested) {
                    $add($nestedKey, $nested, $group);
                }

                $group++;
                $hasLooseItems = false;

                continue;
            }

            $hasLooseItems = $add($key, $item, $group) || $hasLooseItems;
        }

        return ['entries' => $entries, 'overrides' => $overrides];
    }

    /**
     * Visible entries, ordered by group, then `sort()`, then position.
     *
     * @param  array<int, array{0: Action|MenuItem, 1: int}>  $entries
     * @return array<int, array{0: Action|MenuItem, 1: int}>
     */
    protected function visibleMenuEntries(array $entries): array
    {
        $visible = [];

        foreach ($entries as $index => [$item, $group]) {
            if ($item->isVisible()) {
                $visible[] = [$item, $group, $index];
            }
        }

        usort($visible, static fn (array $a, array $b): int => [$a[1], $a[0]->getSort(), $a[2]] <=> [$b[1], $b[0]->getSort(), $b[2]]);

        return array_map(static fn (array $entry): array => [$entry[0], $entry[1]], $visible);
    }

    /**
     * A built-in entry with its override applied, or null when the override hides it.
     */
    protected function applyMenuOverride(Action $default, mixed $override): Action|MenuItem|null
    {
        $item = $default;

        if ($override instanceof Closure) {
            $result = $this->evaluate($override, ['action' => $default, 'item' => $default], [Action::class => $default]);
            $item = $result instanceof Action || $result instanceof MenuItem ? $result : $default;
        } elseif ($override instanceof Action || $override instanceof MenuItem) {
            $item = $override;
        } elseif ($override === false) {
            return null;
        }

        return $item->isVisible() ? $item : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMenuEntry(Action|MenuItem $item, ?int $group = null, ?string $fallbackUrl = null, bool $isPost = false): array
    {
        if ($item instanceof MenuItem) {
            $url = $item->getUrl() ?? $fallbackUrl;

            return Serializable::compact([
                ...$item->toSchema(),
                'url' => $url,
                'method' => $item->shouldPostToUrl() || ($isPost && $item->getUrl() === null) ? 'post' : null,
                'group' => $group ?: null,
            ]);
        }

        $label = $item->getLabel();
        $icon = $item->getIcon();
        $color = $item->getColor();

        // Server logic or a modal: the renderer runs it through the action endpoint.
        if (! $item->isLink() && ($item->hasAction() || $item->hasModal())) {
            return Serializable::compact([
                'label' => $label,
                'icon' => $icon,
                'color' => $color,
                'action' => $item->toSchema(),
                'group' => $group ?: null,
            ]);
        }

        $url = $item->getUrl() ?? $fallbackUrl;

        return Serializable::compact([
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
            'openInNewTab' => $item->shouldOpenUrlInNewTab() ?: null,
            'color' => $color,
            'method' => $item->shouldPostToUrl() || ($isPost && $item->getUrl() === null) ? 'post' : null,
            'group' => $group ?: null,
        ]);
    }

    /**
     * @return array{entries: array<int, array{0: Action|MenuItem, 1: int}>, overrides: array<string, mixed>}
     */
    protected function getUserMenuConfiguration(): array
    {
        return $this->normalizeMenuItems((array) ($this->evaluate($this->userMenuItems) ?? []), self::USER_MENU_KEYS);
    }

    /**
     * @return array{entries: array<int, array{0: Action|MenuItem, 1: int}>, overrides: array<string, mixed>}
     */
    protected function getTenantMenuConfiguration(): array
    {
        return $this->normalizeMenuItems((array) ($this->evaluate($this->tenantMenuItems) ?? []), self::TENANT_MENU_KEYS);
    }

    /**
     * The user menu as sent to the renderer.
     *
     * @return array<string, mixed>
     */
    protected function getUserMenuSchema(): array
    {
        ['entries' => $entries, 'overrides' => $overrides] = $this->getUserMenuConfiguration();

        $profileUrl = $this->getProfileUrl();
        $logoutUrl = $this->getLogoutUrl();
        $schema = [
            'items' => array_map(fn (array $entry): array => $this->serializeMenuEntry($entry[0], $entry[1]), $this->visibleMenuEntries($entries)),
            'profileUrl' => $profileUrl,
            'logoutUrl' => $logoutUrl,
        ];

        if (array_key_exists('profile', $overrides) && $profileUrl !== null) {
            $profile = $this->applyMenuOverride($this->makeUserMenuProfileAction(), $overrides['profile']);
            $schema['profile'] = $profile === null ? null : $this->serializeMenuEntry($profile, fallbackUrl: $profileUrl);

            if ($profile === null) {
                $schema['profileUrl'] = null;
            }
        }

        if (array_key_exists('logout', $overrides)) {
            $logout = $this->applyMenuOverride($this->makeUserMenuLogoutAction(), $overrides['logout']);
            $schema['logout'] = $logout === null ? false : $this->serializeMenuEntry($logout, fallbackUrl: $logoutUrl, isPost: true);
        }

        return Serializable::compact($schema);
    }

    /**
     * The user menu's own entries (`userMenuItems()`, plugin entries such as Billing's) for a
     * starter kit's user menu (`<AlfakitUserMenuItems />`): links only, because the host's
     * pages have no action endpoint context to run server logic or open a modal. The
     * built-in profile and logout entries stay the kit's own.
     *
     * @return list<array<string, mixed>>
     */
    public function getStarterKitUserMenuItems(): array
    {
        $items = (array) ($this->getUserMenuSchema()['items'] ?? []);

        return array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item) && ! isset($item['action']) && is_string($item['url'] ?? null) && $item['url'] !== '',
        ));
    }

    /**
     * The tenant menu's entries and built-in overrides as sent to the renderer.
     *
     * @return array<string, mixed>
     */
    protected function getTenantMenuSchema(): array
    {
        ['entries' => $entries, 'overrides' => $overrides] = $this->getTenantMenuConfiguration();

        $items = array_map(fn (array $entry): array => $this->serializeMenuEntry($entry[0], $entry[1]), $this->visibleMenuEntries($entries));
        $profileUrl = $this->getTenantProfileUrl();
        $registrationUrl = $this->getTenantRegistrationUrl();
        $billingUrl = $this->getTenantBillingUrl();
        $schema = [
            'profileUrl' => $profileUrl,
            'registrationUrl' => $registrationUrl,
            // The pages' own labels; the renderers fall back to the generic "Settings" / "New".
            'profileLabel' => $profileUrl === null ? null : $this->getTenantProfileLabel(),
            'registrationLabel' => $registrationUrl === null ? null : $this->getTenantRegistrationLabel(),
        ];

        if (array_key_exists('profile', $overrides) && $profileUrl !== null) {
            $profile = $this->applyMenuOverride(Action::make('profile')->label($this->getTenantProfileLabel() ?? __('alfakit::panel.tenancy.profile'))->icon('settings')->url($profileUrl), $overrides['profile']);
            $schema['profile'] = $profile === null ? null : $this->serializeMenuEntry($profile, fallbackUrl: $profileUrl);
            $schema['profileUrl'] = $profile === null ? null : $profileUrl;
        }

        if (array_key_exists('register', $overrides) && $registrationUrl !== null) {
            $registration = $this->applyMenuOverride(Action::make('register')->label($this->getTenantRegistrationLabel() ?? __('alfakit::panel.tenancy.register'))->icon('plus')->url($registrationUrl), $overrides['register']);
            $schema['registration'] = $registration === null ? null : $this->serializeMenuEntry($registration, fallbackUrl: $registrationUrl);
            $schema['registrationUrl'] = $registration === null ? null : $registrationUrl;
        }

        if ($billingUrl !== null) {
            $billing = $this->applyMenuOverride(Action::make('billing')->label(__('alfakit::tenancy.billing'))->icon('credit-card')->url($billingUrl), $overrides['billing'] ?? null);

            if ($billing !== null) {
                $items[] = $this->serializeMenuEntry($billing, fallbackUrl: $billingUrl);
            }
        }

        $schema['menu'] = $items;

        return $schema;
    }

    protected function makeUserMenuProfileAction(): Action
    {
        return Action::make('profile')->label(__('alfakit::ui.profile'))->icon('user')->url($this->getProfileUrl());
    }

    protected function makeUserMenuLogoutAction(): Action
    {
        return Action::make('logout')->label(__('alfakit::ui.logout'))->icon('log-out')->url($this->getLogoutUrl())->postToUrl();
    }

    /**
     * The menu action the action endpoint runs (`component` = `userMenu` / `tenantMenu`):
     * a visible `Action` with server logic or a modal. Links never run.
     */
    public function getMenuAction(string $component, string $name): ?Action
    {
        if ($component === self::COMMANDS_COMPONENT) {
            return $this->getCommand($name);
        }

        $configuration = match ($component) {
            self::USER_MENU_COMPONENT => $this->getUserMenuConfiguration(),
            self::TENANT_MENU_COMPONENT => $this->getTenantMenuConfiguration(),
            default => null,
        };

        if ($configuration === null) {
            return null;
        }

        $candidates = array_map(static fn (array $entry): Action|MenuItem => $entry[0], $configuration['entries']);

        foreach ($configuration['overrides'] as $override) {
            if ($override instanceof Action) {
                $candidates[] = $override;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Action && $candidate->getName() === $name && ! $candidate->isLink()) {
                return $candidate;
            }
        }

        return null;
    }
}
