<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Clusters;

use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\Labels;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Groups pages and resources under one navigation item with its own sub-navigation and
 * URL prefix. Pages and resources join a cluster with `protected static ?string $cluster
 * = Settings::class;`. Visiting the cluster's URL redirects to its first page.
 */
abstract class Cluster extends Page
{
    protected static ?string $clusterBreadcrumb = null;

    /**
     * Whether the cluster's pages show the cluster sub-navigation. Turn it off to link
     * between them yourself (the cluster still groups their URLs and main navigation item).
     */
    protected static bool $shouldRegisterSubNavigation = true;

    public static function shouldRegisterSubNavigation(): bool
    {
        return static::$shouldRegisterSubNavigation;
    }

    public static function getSlug(): string
    {
        if (static::$slug !== null) {
            return static::$slug;
        }

        $name = Str::beforeLast(class_basename(static::class), 'Cluster') ?: class_basename(static::class);

        return Str::of($name)->kebab()->slug()->toString();
    }

    public static function getNavigationLabel(): string
    {
        $label = static::getNavigationOverride('label');

        return is_string($label) ? $label : static::$navigationLabel ?? static::$title ?? Labels::fromName(Str::beforeLast(class_basename(static::class), 'Cluster') ?: class_basename(static::class));
    }

    public static function getClusterBreadcrumb(): string
    {
        return static::$clusterBreadcrumb ?? \AlfaSix\Alfakit\Support\Html::toText(static::getNavigationLabel());
    }

    /**
     * The pages and resources of the panel that belong to this cluster.
     *
     * @return array<class-string<Page>|class-string<resource>>
     */
    public static function getClusteredComponents(?Panel $panel = null): array
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();
        $components = [];

        foreach ([...$panel->getPages(), ...$panel->getResources()] as $component) {
            if ($component::getCluster() === static::class) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * Navigation items of the cluster's pages and resources the user may access, sorted.
     *
     * @return array<NavigationItem>
     */
    public static function getClusterNavigationItems(): array
    {
        $items = [];

        foreach (static::getClusteredComponents() as $component) {
            array_push($items, ...$component::buildNavigationItems());
        }

        $items = array_values(array_filter($items, static fn (NavigationItem $item): bool => $item->isVisible()));

        usort($items, static fn (NavigationItem $a, NavigationItem $b): int => $a->getSort() <=> $b->getSort());

        return $items;
    }

    /**
     * Whether the current request is on one of the cluster's pages.
     */
    public static function isActive(): bool
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $routes = [$panel->generateRouteName(static::getRelativeRouteName())];

        foreach (static::getClusteredComponents($panel) as $component) {
            $routes[] = is_a($component, Resource::class, true)
                ? $panel->generateRouteName($component::getRouteBaseName()).'.*'
                : $panel->generateRouteName($component::getRelativeRouteName());
        }

        return request()->routeIs($routes);
    }

    /**
     * One item in the main navigation, active on every page of the cluster. Hidden when
     * the user can't open any of its pages.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (static::getClusterNavigationItems() === []) {
            return [];
        }

        return array_map(
            static fn (NavigationItem $item): NavigationItem => $item->isActiveWhen(static fn (): bool => static::isActive()),
            static::buildNavigationItems(),
        );
    }

    public function __invoke(Request $request): mixed
    {
        $this->flushCaches();

        abort_unless(static::canAccess(), 403);

        $url = (static::getClusterNavigationItems()[0] ?? null)?->getUrl();

        abort_if($url === null, 404);

        return redirect()->to($url);
    }
}
