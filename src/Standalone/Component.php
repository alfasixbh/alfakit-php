<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Widgets\Widget;
use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * An Alfakit component rendered outside a panel, in any Inertia page of the application:
 * a headless page with a form, a table, an infolist, actions and widgets, in any mix.
 *
 * ```php
 * return Inertia::render('Account/Orders', [
 *     'orders' => OrdersTable::make(['customer' => $customer]),
 * ]);
 * ```
 *
 * `make()` parameters are assigned to public properties of the same name (models by
 * route key in the context token, re-resolved on every internal request) and passed to
 * `mount()`. The payload is JSON-serialisable and carries everything the renderer's
 * `<AlfakitComponent>` needs: the context token, content blocks, endpoints, translations
 * and localization. Internal requests rebuild the component from the token, bound to the
 * user it was issued to (see the security docs).
 *
 * @implements Arrayable<string, mixed>
 */
abstract class Component extends Page implements Arrayable, JsonSerializable
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string $layout = 'blank';

    protected ?Model $record = null;

    /** @var array<string, mixed> The `make()` parameters, as given (models included). */
    protected array $parameters = [];

    /** @var array<string, mixed>|null */
    protected ?array $cachedPayload = null;

    // ---------------------------------------------------------------- Creation

    /**
     * Build the component for a page response. Parameters are keyed by name; models are
     * stored by route key in the context token and resolved again on internal requests.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function make(array $parameters = []): static
    {
        $component = app(static::class);
        $component->flushCaches();

        $component->ensureCurrentPanel();
        $component->assignParameters($parameters);

        abort_unless(static::canAccess() && $component->authorize(), 403);

        return $component;
    }

    /**
     * Rebuild the component for an internal request (action, schema refresh, poll…).
     */
    public static function fromContext(SchemaContext $context): static
    {
        $component = app(static::class);
        $component->flushCaches();
        $component->assignParameters(static::hydrateParameters($context->parameters));

        // The tenant travels in the token by key: membership is re-checked on every
        // internal request, as a panel's tenant routes do (`Panel::findTenantFor()`).
        $tenant = $component->parameters['tenant'] ?? null;

        if ($tenant instanceof Model) {
            abort_unless($component->canAccessTenant($tenant), 404);
        }

        abort_unless($component->authorize(), 403);

        return $component;
    }

    /**
     * Whether the current user of the component's guard may use the tenant restored from a
     * context token. Fails closed: a guest, or a user that doesn't implement `HasTenants`,
     * can't reach any tenant. Override for components whose tenant membership is checked
     * another way (e.g. a public form scoped to a shop by its URL).
     */
    public function canAccessTenant(Model $tenant): bool
    {
        $user = $this->getPanel()->auth()->user();

        return $user instanceof HasTenants && $user->canAccessTenant($tenant);
    }

    /**
     * Inertia calls invokable prop values; a standalone component is never routed, so being
     * invoked means "serialise yourself as a prop".
     */
    public function __invoke(Request $request): mixed
    {
        return $this->toPayload();
    }

    /**
     * Components outside panels are usable by guests unless they say otherwise.
     */
    public static function requiresAuthentication(): bool
    {
        return false;
    }

    /**
     * Instance-level access check, run after the parameters are assigned (so it can look at
     * the resolved models) on page renders and on every internal request. Return false to
     * deny with a 403; throw or `abort()` for anything else.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Receives the `make()` parameters (named). Override to load related data.
     */
    public function mount(mixed ...$parameters): void {}

    /**
     * Pre-fill the form (`['email' => $user->email]`).
     *
     * @param  array<string, mixed>  $data
     */
    public function fill(array $data): static
    {
        $this->formData = [...($this->formData ?? []), ...$data];
        $this->cachedForm = null;
        $this->cachedPayload = null;

        return $this;
    }

    /**
     * Bind a record: forms are filled from it and validated as an edit, infolists read it,
     * and actions receive it as `$record`.
     */
    public function record(?Model $record): static
    {
        $this->record = $record;
        $this->parameters['record'] = $record;
        $this->routeParameters['record'] = $record === null ? null : static::dehydrateParameter($record);
        $this->cachedForm = null;
        $this->cachedInfolist = null;
        $this->cachedActions = null;
        $this->cachedPayload = null;

        return $this;
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }

    /**
     * Scope the component to a tenant: it is restored (from the context token) on every
     * internal request, so tenant-aware queries, uploads and policies see it.
     */
    public function tenant(?Model $tenant): static
    {
        $this->parameters['tenant'] = $tenant;
        $this->routeParameters['tenant'] = $tenant === null ? null : static::dehydrateParameter($tenant);
        alfakit()->setTenant($tenant, isQuiet: true);

        return $this;
    }

    public function heading(?string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function subheading(?string $subheading): static
    {
        $this->subheading = $subheading;

        return $this;
    }

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    // ---------------------------------------------------------------- Definition hooks

    /**
     * Buttons shown above the content (with the heading, when there is one).
     *
     * @return array<Action|ActionGroup>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Widgets shown above the content.
     *
     * @return array<class-string<Widget>|Widget>
     */
    public function widgets(): array
    {
        return [];
    }

    /**
     * Widgets shown below the content.
     *
     * @return array<class-string<Widget>|Widget>
     */
    public function footerWidgets(): array
    {
        return [];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function widgetColumns(): int|array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return $this->actions();
    }

    protected function getHeaderWidgets(): array
    {
        return $this->widgets();
    }

    protected function getFooterWidgets(): array
    {
        return $this->footerWidgets();
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return $this->widgetColumns();
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return $this->widgetColumns();
    }

    // ---------------------------------------------------------------- Context

    public function getPanel(): Panel
    {
        return alfakit()->getStandalonePanel();
    }

    public function getContextToken(?string $component = null): string
    {
        $panel = $this->getPanel();
        $user = $panel->auth()->id();

        // Bound to the standalone panel's guard explicitly: the page may render while
        // another panel (or none) is current.
        return (new SchemaContext($panel->getId(), static::class, $this->getRouteParameters(), $component, $user === null ? null : (string) $user))->toToken();
    }

    /**
     * The token parameters: scalars as given, models and enums in their serialised form.
     *
     * @return array<string, mixed>
     */
    public function getRouteParameters(): array
    {
        return $this->routeParameters;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->cachedPayload = null;
        $this->parameters = [];
    }

    /**
     * The page rendering the component may run outside any panel; make the standalone
     * panel current so closures, formatting and `alfakit()` helpers have one.
     */
    protected function ensureCurrentPanel(): void
    {
        if (alfakit()->getCurrentPanel() === null) {
            alfakit()->setCurrentPanel($this->getPanel());
        }
    }

    /**
     * Assign resolved parameters: public properties of the same name get the value, every
     * parameter is kept for the token, and `mount()` receives them all (named).
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function assignParameters(array $parameters): void
    {
        $this->parameters = [];
        $this->routeParameters = [];
        $tenant = null;

        foreach ($parameters as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException(sprintf('%s::make() parameters must be keyed by name.', static::class));
            }

            $this->parameters[$name] = $value;
            $this->routeParameters[$name] = static::dehydrateParameter($value);

            if ($name === 'record' && $value instanceof Model) {
                $this->record = $value;

                continue;
            }

            if ($name === 'tenant' && $value instanceof Model) {
                $tenant = $value;

                continue;
            }

            if (property_exists($this, $name) && (new ReflectionProperty($this, $name))->isPublic()) {
                $this->{$name} = $value;
            }
        }

        if ($tenant !== null) {
            alfakit()->setTenant($tenant, isQuiet: true);
        }

        $mount = array_filter($this->parameters, static fn (string $name): bool => ! in_array($name, ['record', 'tenant'], true), ARRAY_FILTER_USE_KEY);

        $this->mount(...$mount);
    }

    /**
     * Models travel as route keys (never serialised), enums as values.
     */
    protected static function dehydrateParameter(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return ['@model' => $value::class, 'key' => $value->getRouteKey()];
        }

        if ($value instanceof BackedEnum) {
            return ['@enum' => $value::class, 'value' => $value->value];
        }

        if (is_array($value)) {
            return array_map(static::dehydrateParameter(...), $value);
        }

        return $value;
    }

    /**
     * Resolve the token parameters back into models (404 when one is gone) and enums.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected static function hydrateParameters(array $parameters): array
    {
        $hydrated = [];

        foreach ($parameters as $name => $value) {
            $hydrated[$name] = static::hydrateParameter((string) $name, $value);
        }

        return $hydrated;
    }

    protected static function hydrateParameter(string $name, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value['@model']) && is_string($value['@model'])) {
            $class = static::expectedModelClass($name) ?? $value['@model'];

            abort_unless(is_a($class, Model::class, true) && is_a($value['@model'], $class, true), 400, 'Invalid Alfakit context parameters.');

            /** @var Model $prototype */
            $prototype = new $class;
            $model = $prototype->resolveRouteBinding($value['key'] ?? null);

            abort_if($model === null, 404);

            return $model;
        }

        if (isset($value['@enum']) && is_string($value['@enum']) && is_a($value['@enum'], BackedEnum::class, true)) {
            return $value['@enum']::from($value['value'] ?? null);
        }

        return array_map(static fn (mixed $item): mixed => static::hydrateParameter($name, $item), $value);
    }

    /**
     * The model class a public property of that name is typed with, so a token can only
     * ever resolve the kind of record the component declares.
     *
     * @return class-string<Model>|null
     */
    protected static function expectedModelClass(string $name): ?string
    {
        if (! property_exists(static::class, $name)) {
            return null;
        }

        $type = (new ReflectionProperty(static::class, $name))->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string $class */
        $class = $type->getName();

        // Only concrete model types constrain the token: `?Model` (the base record property)
        // accepts whatever model the token names.
        if (is_a($class, Model::class, true) && (new ReflectionClass($class))->isInstantiable()) {
            /** @var class-string<Model> $class */
            return $class;
        }

        return null;
    }

    // ---------------------------------------------------------------- Payload

    /**
     * What the renderer's `<AlfakitComponent>` needs, usable directly as an Inertia prop.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        if ($this->cachedPayload !== null) {
            return $this->cachedPayload;
        }

        $this->ensureCurrentPanel();

        $panel = $this->getPanel();
        $header = $this->serializeWidgets($this->getHeaderWidgets(), $this->getHeaderWidgetsColumns(), 'headerWidgets');
        $footer = $this->serializeWidgets($this->getFooterWidgets(), $this->getFooterWidgetsColumns(), 'footerWidgets');

        return $this->cachedPayload = Serializable::compact([
            'alfakit' => 'standalone',
            'class' => static::class,
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'headerActions' => $this->serializeActions($this->getCachedHeaderActions()) ?: null,
            'content' => array_values(array_filter([$header, ...$this->getContentBlocks(), $footer])),
            'token' => $this->getContextToken(),
            'record' => $this->getRecord()?->getKey(),
            'lazy' => $this->getLazyComponentTargets() ?: null,
            'endpoints' => $panel->getEndpoints(),
            'uploadChunkSize' => \AlfaSix\Alfakit\Support\ChunkedUpload::configuredChunkSize(),
            'localization' => $panel->getLocalizationSchema(),
            'translations' => $panel->getFrontendTranslations(),
            'csrf' => csrf_token(),
            'flashKey' => Notification::FLASH_KEY,
        ]);
    }

    /**
     * Lazy components are placeholders on the page render; the renderer fetches them from
     * the standalone components endpoint right after mounting.
     */
    public function rendersLazyPlaceholders(): bool
    {
        return ! request()->routeIs($this->getPanel()->generateRouteName('internal.standalone.components'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toPayload();
    }
}
