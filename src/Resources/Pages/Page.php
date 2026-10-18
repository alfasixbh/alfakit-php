<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Pages\Concerns\ComposesPageContent;
use AlfaSix\Alfakit\Pages\Page as BasePage;
use AlfaSix\Alfakit\Resources\ParentRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Resources\ResourceConfiguration;
use AlfaSix\Alfakit\Support\SchemaContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use ReflectionProperty;

/**
 * Base class for the pages of a Resource.
 */
abstract class Page extends BasePage implements HasResource
{
    use ComposesPageContent;

    /** @var class-string<resource> */
    protected static string $resource;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @return class-string<resource>
     */
    /**
     * Resource pages follow their resource's `isTenantSubscriptionRequired()`.
     */
    public static function isTenantSubscriptionRequired(\AlfaSix\Alfakit\Panel $panel): bool
    {
        return static::getResource()::isTenantSubscriptionRequired($panel);
    }

    public static function getResource(): string
    {
        return static::$resource;
    }

    // ---------------------------------------------------------------- Configurations

    /**
     * Serve the request as the resource registration its route belongs to
     * (`Resource::make($key)`), or as the plain registration.
     */
    public function __invoke(Request $request): mixed
    {
        $key = $request->route()?->parameter(ResourceConfiguration::ROUTE_PARAMETER);

        static::activateResourceConfiguration(is_string($key) ? $key : null);

        $response = parent::__invoke($request);

        $this->rememberRecentRecord($request, is_string($key) ? $key : null);

        return $response;
    }

    /**
     * View and edit pages remember the record for the command palette's recent records.
     */
    protected function rememberRecentRecord(Request $request, ?string $configuration): void
    {
        if (! $request->isMethod('GET') || ! ($this instanceof ViewRecord || $this instanceof EditRecord)) {
            return;
        }

        $record = $this->getRecord();
        $panel = $this->getPanel();
        $user = $panel->auth()->user();

        if ($record === null || $user === null || ! $panel->hasCommandPalette()) {
            return;
        }

        \AlfaSix\Alfakit\CommandPalette\RecentRecords::record($panel, $user, static::getResource(), $record, $this instanceof EditRecord ? 'edit' : 'view', $configuration);
    }

    /**
     * Edit and view pages of resources with versions (`$hasVersions`, `HasRecordVersions`)
     * get a "History" header action, unless the page defines its own `history` action.
     */
    public function getCachedHeaderActions(): array
    {
        $actions = parent::getCachedHeaderActions();

        if (! ($this instanceof ViewRecord || $this instanceof EditRecord) || $this->getRecord() === null || ! static::getResource()::hasVersions()) {
            return $actions;
        }

        foreach ($actions as $action) {
            foreach ($action instanceof \AlfaSix\Alfakit\Actions\ActionGroup ? $action->getFlatActions() : [$action] as $flat) {
                if ($flat->getName() === \AlfaSix\Alfakit\Actions\HistoryAction::DEFAULT_NAME) {
                    return $actions;
                }
            }
        }

        return [...$actions, $this->prepareAction(\AlfaSix\Alfakit\Actions\HistoryAction::make())];
    }

    /**
     * Internal requests carry the registration key in the context token's parameters.
     */
    public static function fromContext(SchemaContext $context): static
    {
        static::activateResourceConfigurationFromContext($context);

        return parent::fromContext($context);
    }

    protected static function activateResourceConfigurationFromContext(SchemaContext $context): void
    {
        $key = $context->parameters[ResourceConfiguration::ROUTE_PARAMETER] ?? null;

        static::activateResourceConfiguration(is_string($key) ? $key : null);

        // Authorisation ran before the registration was known: check again with it.
        if (is_string($key)) {
            abort_unless(static::canAccess(), 403);
        }
    }

    protected static function activateResourceConfiguration(?string $key): void
    {
        $resource = static::getResource();
        $configuration = $key === null ? null : alfakit()->getCurrentOrDefaultPanel()->getResourceConfiguration($resource, $key);

        abort_if($key !== null && $configuration === null, 404);

        $resource::setConfiguration($configuration);
    }

    /**
     * Guest-accessible when the page says so, or when its resource does (`protected static
     * bool $isGuestAccessible = true` or an `isGuestAccessible()` method on the resource):
     * then every page of the resource opens to guests.
     */
    public static function isGuestAccessible(): bool
    {
        return parent::isGuestAccessible() || static::isResourceGuestAccessible(static::getResource());
    }

    /**
     * @param  class-string  $resource
     */
    public static function isResourceGuestAccessible(string $resource): bool
    {
        if (method_exists($resource, 'isGuestAccessible')) {
            return (bool) $resource::isGuestAccessible();
        }

        if (! property_exists($resource, 'isGuestAccessible')) {
            return false;
        }

        $property = new ReflectionProperty($resource, 'isGuestAccessible');

        return $property->isStatic() && $property->getValue() === true;
    }

    public function getResourceClass(): ?string
    {
        return static::getResource();
    }

    /**
     * @return class-string<Model>
     */
    public function getModel(): string
    {
        return static::getResource()::getModel();
    }

    public static function getRelativeRouteName(): string
    {
        $pages = static::getResource()::getPages();

        foreach ($pages as $name => $registration) {
            if ($registration->page === static::class) {
                return static::getResource()::getRouteBaseName().'.'.$name;
            }
        }

        return static::getResource()::getRouteBaseName().'.'.static::getSlug();
    }

    public static function getResourcePageName(): ?string
    {
        foreach (static::getResource()::getPages() as $name => $registration) {
            if ($registration->page === static::class) {
                return $name;
            }
        }

        return null;
    }

    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();

        $breadcrumbs = $this->getParentBreadcrumbs();

        if ($resource::hasPage('index')) {
            $breadcrumbs[$resource::getUrl()] = $resource::getTitleCasePluralModelLabel();
        } elseif ($resource::isNested() && ($url = $resource::getParentRelationPageUrl()) !== null && ! array_key_exists($url, $breadcrumbs)) {
            // The parent's relation page (`ParentResourceRegistration::page()`).
            $breadcrumbs[$url] = $resource::getTitleCasePluralModelLabel();
        }

        $breadcrumbs[] = $this->getBreadcrumb();

        return $breadcrumbs;
    }

    public function getBreadcrumb(): string
    {
        return static::$breadcrumb ?? $this->getTitle();
    }

    public function getRecord(): ?Model
    {
        return null;
    }

    // ---------------------------------------------------------------- Nested resources

    /**
     * Resolve the parent records of a nested resource page from the route (outermost
     * first): each must exist within its own resource's (tenant-scoped, parent-scoped)
     * query, and the user must be allowed to view or edit it.
     */
    protected function bootRouteParameters(): void
    {
        parent::bootRouteParameters();

        foreach ($this->getParentChain() as [$parentResource, $parameter]) {
            $key = $this->routeParameters[$parameter] ?? null;
            $parent = $key === null || $key instanceof Model ? $key : $parentResource::resolveRecordRouteBinding($key);

            abort_unless($parent instanceof Model, 404);
            abort_unless($parentResource::canView($parent) || $parentResource::canEdit($parent), 403);

            ParentRecords::set($parentResource, $parent);
        }
    }

    /**
     * Parent resources of this page's resource with their route parameter, outermost first.
     *
     * @return array<int, array{0: class-string<resource>, 1: string}>
     */
    protected function getParentChain(): array
    {
        $chain = [];
        $resource = static::getResource();

        while (($registration = $resource::getParentResourceRegistration()) !== null) {
            array_unshift($chain, [$registration->getParentResource(), $registration->getRouteParameterName()]);
            $resource = $registration->getParentResource();
        }

        return $chain;
    }

    protected function getNonMountParameterNames(): array
    {
        return [...parent::getNonMountParameterNames(), ResourceConfiguration::ROUTE_PARAMETER, ...array_map(static fn (array $link): string => $link[1], $this->getParentChain())];
    }

    /**
     * The record this nested resource page lives under (null when not nested).
     */
    public function getParentRecord(): ?Model
    {
        return static::getResource()::getParentRecord();
    }

    /**
     * Breadcrumbs of the parent records (`Posts › My post`) for nested resource pages.
     *
     * @return array<int|string, string>
     */
    protected function getParentBreadcrumbs(): array
    {
        $breadcrumbs = [];

        foreach ($this->getParentChain() as [$parentResource]) {
            $parent = ParentRecords::get($parentResource);

            if ($parentResource::hasPage('index')) {
                $breadcrumbs[$parentResource::getUrl()] = $parentResource::getTitleCasePluralModelLabel();
            }

            if ($parent === null) {
                continue;
            }

            $title = (string) $parentResource::getRecordTitle($parent);

            if ($parentResource::hasPage('view') && $parentResource::canView($parent)) {
                $breadcrumbs[$parentResource::getUrl('view', ['record' => $parent])] = $title;
            } elseif ($parentResource::hasPage('edit') && $parentResource::canEdit($parent)) {
                $breadcrumbs[$parentResource::getUrl('edit', ['record' => $parent])] = $title;
            } else {
                $breadcrumbs[] = $title;
            }
        }

        return $breadcrumbs;
    }

    // ---------------------------------------------------------------- Redirects

    /**
     * The panel's `resourceCreatePageRedirect()` / `resourceEditPageRedirect()` target
     * (`index`, `view` or `edit`) for this operation, if set.
     */
    protected function getPanelResourcePageRedirect(string $operation): ?string
    {
        $panel = $this->getPanel();

        return $operation === 'create' ? $panel->getResourceCreatePageRedirect() : $panel->getResourceEditPageRedirect();
    }

    /**
     * The URL of a resource page for the record, when the resource has that page and the
     * user may open it; null otherwise (the caller falls back to its default).
     */
    protected function getResourcePageRedirectUrl(string $target, ?Model $record): ?string
    {
        $resource = static::getResource();

        return match ($target) {
            'index' => $resource::hasPage('index') && $resource::canViewAny() ? $resource::getUrl() : null,
            'view' => $record !== null && $resource::hasPage('view') && $resource::canView($record) ? $resource::getUrl('view', ['record' => $record]) : null,
            'edit' => $record !== null && $resource::hasPage('edit') && $resource::canEdit($record) ? $resource::getUrl('edit', ['record' => $record]) : null,
            default => null,
        };
    }

    public function getPanelTenant(): ?Model
    {
        return $this->getPanel()->getTenant();
    }

    // ---------------------------------------------------------------- Sub-navigation

    /**
     * Resource pages belong to their resource's cluster.
     */
    public static function getCluster(): ?string
    {
        return static::$cluster ?? static::getResource()::getCluster();
    }

    /**
     * Record pages show `Resource::getRecordSubNavigation()`; other pages the cluster's.
     */
    public function getSubNavigation(): array
    {
        if ($this->getRecord() !== null) {
            $navigation = static::getResource()::getRecordSubNavigation($this);

            if ($navigation !== []) {
                return $navigation;
            }
        }

        return parent::getSubNavigation();
    }

    public function getSubNavigationPosition(): SubNavigationPosition
    {
        return static::$subNavigationPosition
            ?? static::getResource()::getSubNavigationPosition()
            ?? parent::getSubNavigationPosition();
    }

    /**
     * Items linking to other pages of this resource for the current record. Pages the user
     * may not open for the record (edit without `update`, view without `view`) are skipped.
     *
     * @param  array<class-string<BasePage>>  $pages
     * @return array<NavigationItem>
     */
    public function generateNavigationItems(array $pages): array
    {
        $resource = static::getResource();
        $record = $this->getRecord();
        $current = static::class;
        $items = [];

        foreach ($pages as $page) {
            if (! is_subclass_of($page, self::class) || $page::getResource() !== $resource) {
                array_push($items, ...$page::buildNavigationItems());

                continue;
            }

            $name = $page::getResourcePageName();

            if ($name === null || ! $page::canAccessRecord($record)) {
                continue;
            }

            $items[] = NavigationItem::make($page::getSubNavigationLabel())
                ->icon($page::getNavigationIcon())
                ->activeIcon($page::getActiveNavigationIcon())
                ->sort($page::getNavigationSort())
                ->badge($page::getNavigationBadge(), $page::getNavigationBadgeColor())
                ->isActiveWhen(static fn (): bool => $page === $current)
                ->url($resource::getUrl($name, $record !== null ? ['record' => $record] : []));
        }

        return $items;
    }

    /**
     * Whether the user may open this page for the record, checked before linking to it.
     */
    public static function canAccessRecord(?Model $record): bool
    {
        $resource = static::getResource();

        return match (true) {
            $record !== null && is_a(static::class, EditRecord::class, true) => $resource::canEdit($record),
            $record !== null && is_a(static::class, ViewRecord::class, true) => $resource::canView($record),
            default => static::canAccess(),
        };
    }

    /**
     * Label of the page in a record sub-navigation: `$navigationLabel`, `$title`, or the
     * page type (Edit, View).
     */
    public static function getSubNavigationLabel(): string
    {
        return static::$navigationLabel ?? static::$title ?? match (true) {
            is_a(static::class, EditRecord::class, true) => __('alfakit::panel.resources.edit.breadcrumb'),
            is_a(static::class, ViewRecord::class, true) => __('alfakit::panel.resources.view.breadcrumb'),
            default => \AlfaSix\Alfakit\Support\Html::toText(static::getNavigationLabel()),
        };
    }
}
