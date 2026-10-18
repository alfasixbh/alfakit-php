<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationState;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;

/**
 * How the panel description reaches the browser as Inertia props:
 *
 * - **Once props** (`onceProps()`, on by default): the translations, the navigation
 *   structure, the endpoints, the localization, the command palette and the theme are sent
 *   once and reused on later visits. Each is keyed by a hash of its content, so it is sent
 *   again as soon as it changes (another language, other permissions, new configuration).
 * - **Navigation state**: the active items and the badges travel per request in
 *   `navigationState`, keyed by item position (`group.item[.child…]`).
 * - **Deferred badges** (`deferNavigationBadges()`): badges (often count queries) are left
 *   out of the page response and load with the `alfakitNavigationBadges` deferred prop.
 *
 * @mixin \AlfaSix\Alfakit\Panel
 */
trait HasInertiaProps
{
    /** Deferred Inertia prop (and defer group) with the navigation badges keyed by item id. */
    public const NAVIGATION_BADGES_PROP = 'alfakitNavigationBadges';

    protected bool|Closure $hasOnceProps = true;

    protected bool|Closure $isNavigationBadgesDeferred = false;

    private bool $serializingSharedProps = false;

    /**
     * Send the translations, navigation, endpoints, localization, command palette and theme
     * once (Inertia once props) and reuse them on later visits until their content changes.
     */
    public function onceProps(bool|Closure $condition = true): static
    {
        $this->hasOnceProps = $condition;

        return $this;
    }

    public function hasOnceProps(): bool
    {
        return (bool) $this->evaluate($this->hasOnceProps);
    }

    /**
     * Load navigation badges after the page (an Inertia deferred prop), so their queries
     * never slow down a visit. Items with a badge show a placeholder until it arrives.
     */
    public function deferNavigationBadges(bool|Closure $condition = true): static
    {
        $this->isNavigationBadgesDeferred = $condition;

        return $this;
    }

    public function hasDeferredNavigationBadges(): bool
    {
        return (bool) $this->evaluate($this->isNavigationBadgesDeferred);
    }

    /**
     * The `alfakit` page prop: `toSchema()` with the navigation split into structure and
     * state, and the static parts wrapped in once props.
     *
     * @return array<string, mixed>
     */
    public function getSharedInertiaSchema(): array
    {
        $this->serializingSharedProps = true;

        try {
            $schema = [
                ...$this->toSchema(),
                'localization' => $this->getLocalizationSchema(),
                'csrf' => csrf_token(),
                'translations' => $this->getFrontendTranslations(),
            ];
        } finally {
            $this->serializingSharedProps = false;
        }

        if ($this->showsNavigation($this->auth()->user(), $this->getTenant())) {
            [$navigation, $state] = $this->getSplitNavigation($this->hasDeferredNavigationBadges());

            $schema['navigation'] = $navigation;
            $schema['navigationState'] = array_filter([
                'active' => $state['active'],
                'badges' => $state['badges'],
            ]) ?: null;

            if ($schema['navigationState'] === null) {
                unset($schema['navigationState']);
            }
        }

        if (! $this->hasOnceProps()) {
            return $schema;
        }

        foreach ($this->getOncePropKeys() as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }

            $value = $schema[$key];
            $schema[$key] = Inertia::once(static fn (): mixed => $value)->as("alfakit.{$key}:".self::hashOnceProp($value));
        }

        return $schema;
    }

    /**
     * The shared deferred props: the navigation badges when `deferNavigationBadges()`.
     *
     * @return array<string, mixed>
     */
    public function getDeferredSharedProps(): array
    {
        if (! $this->hasDeferredNavigationBadges() || ! $this->showsNavigation($this->auth()->user(), $this->getTenant())) {
            return [];
        }

        return [self::NAVIGATION_BADGES_PROP => Inertia::defer(fn (): array => $this->getNavigationBadges(), self::NAVIGATION_BADGES_PROP)];
    }

    /**
     * The navigation badges keyed by item id (`group.item[.child…]`): what the
     * `alfakitNavigationBadges` deferred prop carries.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getNavigationBadges(): array
    {
        if (! $this->showsNavigation($this->auth()->user(), $this->getTenant())) {
            return [];
        }

        return $this->getSplitNavigation(false)[1]['badges'];
    }

    /**
     * The `alfakit` keys sent as once props.
     *
     * @return list<string>
     */
    protected function getOncePropKeys(): array
    {
        return ['translations', 'navigation', 'endpoints', 'localization', 'commandPalette', 'theme'];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array{active: list<string>, badges: array<string, array<string, mixed>>}}
     */
    protected function getSplitNavigation(bool $deferBadges): array
    {
        return NavigationState::split(NavigationState::serializing(
            $deferBadges,
            fn (): array => array_map(static fn (NavigationGroup $group): array => $group->toSchema(), $this->applyNavigationGroupOptions($this->getNavigation())),
        ));
    }

    /**
     * Navigation items link to tenant routes, so tenant-less pages of a tenancy panel
     * (profile, tenant registration, email verification) render without them.
     * `starterKit()`: the host sidebar renders the navigation from the shared
     * `alfakitNavigation` prop instead.
     */
    protected function showsNavigation(?Authenticatable $user, ?Model $tenant): bool
    {
        return ! $this->isStarterKit() && ($user !== null || $this->hasGuestAccess($tenant)) && (! $this->hasTenancy() || $tenant !== null);
    }

    protected function isSerializingSharedProps(): bool
    {
        return $this->serializingSharedProps;
    }

    private static function hashOnceProp(mixed $value): string
    {
        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);

        return hash('xxh3', $encoded === false ? serialize($value) : $encoded);
    }
}
