<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets;

use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Realtime\Concerns\HasLiveUpdates;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionProperty;
use UnitEnum;

abstract class Widget
{
    use Configurable;
    use EvaluatesClosures;
    use HasLiveUpdates;

    protected static ?int $sort = null;

    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = 1;

    protected static bool $isDiscovered = true;

    protected static bool $isLazy = true;

    /**
     * Customizable dashboards: spans the user may choose (see `allowedColumnSpans()`).
     *
     * @var array<int|string>|null
     */
    protected static ?array $columnSpanOptions = null;

    /** @var array<int|string>|null */
    protected ?array $allowedColumnSpans = null;

    /** Customizable dashboards: the widget's name in the widget library. */
    protected static ?string $libraryLabel = null;

    protected static ?string $libraryDescription = null;

    protected ?Page $page = null;

    protected ?Model $record = null;

    /** The Inertia component for custom widgets (registered in the renderer). */
    protected static ?string $component = null;

    /**
     * Properties given to `make()`, kept to tell two instances of one widget class apart.
     *
     * @var array<string, mixed>
     */
    protected array $makeProperties = [];

    /**
     * Create the widget, optionally setting its public properties:
     * `OrdersOverview::make(['status' => 'pending'])` with `public ?string $status = null;`
     * on the widget. The same class may appear several times with different properties.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function make(array $properties = []): static
    {
        $static = app(static::class);
        $static->fillMakeProperties($properties);
        $static->configure();

        return $static;
    }

    /**
     * Set public, non-static properties declared on the widget class.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function fillMakeProperties(array $properties): static
    {
        foreach ($properties as $name => $value) {
            if (! is_string($name) || ! property_exists($this, $name)) {
                throw new InvalidArgumentException(sprintf('Widget [%s] has no property [%s]; declare it as a public property to pass it to make().', static::class, $name));
            }

            $property = new ReflectionProperty($this, $name);

            if (! $property->isPublic() || $property->isStatic() || $property->isReadOnly()) {
                throw new InvalidArgumentException(sprintf('Property [%s] of widget [%s] must be public, non-static and writable to be passed to make().', $name, static::class));
            }

            $this->{$name} = $value;
            $this->makeProperties[$name] = $value;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMakeProperties(): array
    {
        return $this->makeProperties;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'widget';
    }

    public static function canView(): bool
    {
        return true;
    }

    public static function isDiscovered(): bool
    {
        return static::$isDiscovered;
    }

    /**
     * Lazy widgets are left out of the first page response: the page renders a skeleton
     * in their place and loads them with an Inertia deferred prop right after.
     */
    public static function isLazy(): bool
    {
        return static::$isLazy;
    }

    public static function idFromClass(string $class): string
    {
        return Str::of($class)->replace('\\', ' ')->kebab()->toString();
    }

    /**
     * The class-derived id; a widget made with properties gets a suffix derived from them,
     * so `Stats::make(['status' => 'a'])` and `Stats::make(['status' => 'b'])` stay apart
     * (and are found again on polls, lazy loads and actions, which rebuild the same list).
     */
    public function getId(): string
    {
        $id = static::idFromClass(static::class);

        if ($this->makeProperties === []) {
            return $id;
        }

        return $id.'--'.substr(md5((string) json_encode(self::fingerprint($this->makeProperties))), 0, 8);
    }

    /**
     * A stable, JSON-safe stand-in for property values (models by key, enums by case).
     */
    protected static function fingerprint(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::fingerprint(...), $value),
            $value instanceof Model => [$value::class, $value->getKey()],
            $value instanceof UnitEnum => [$value::class, $value->name],
            $value instanceof Closure => 'closure',
            is_object($value) => $value::class,
            default => $value,
        };
    }

    public function getSort(): int
    {
        return static::$sort ?? -1;
    }

    public function page(?Page $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function record(?Model $record): static
    {
        $this->record = $record;

        return $this;
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }

    /**
     * @param  int|string|array<string, int|string|null>  $span
     */
    public function columnSpan(int|string|array $span): static
    {
        $this->columnSpan = $span;

        return $this;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function getColumnSpan(): array
    {
        return is_array($this->columnSpan) ? $this->columnSpan : ['default' => $this->columnSpan];
    }

    /**
     * Customizable dashboards: the column spans the user may pick for this widget (numbers
     * of columns, `'full'` for the whole row). Null offers every span from 1 to the grid's
     * column count.
     *
     * @param  array<int|string>|null  $spans
     */
    public function allowedColumnSpans(?array $spans): static
    {
        $this->allowedColumnSpans = $spans;

        return $this;
    }

    /**
     * @return array<int|string>|null
     */
    public function getAllowedColumnSpans(): ?array
    {
        return $this->allowedColumnSpans ?? static::$columnSpanOptions;
    }

    /**
     * The name of the widget in a customizable dashboard's widget library: `$libraryLabel`,
     * else the widget's heading, else its class name.
     */
    public function getLibraryLabel(): string
    {
        if (static::$libraryLabel !== null) {
            return static::$libraryLabel;
        }

        $heading = method_exists($this, 'getHeading') ? $this->getHeading() : null;

        return is_string($heading) && $heading !== '' ? \AlfaSix\Alfakit\Support\Html::toText($heading) : Str::headline(class_basename(static::class));
    }

    public function getLibraryDescription(): ?string
    {
        if (static::$libraryDescription !== null) {
            return static::$libraryDescription;
        }

        $description = method_exists($this, 'getDescription') ? $this->getDescription() : null;

        return is_string($description) && $description !== '' ? \AlfaSix\Alfakit\Support\Html::toText($description) : null;
    }

    /**
     * Refresh the widget when records of `$models` (models or resources; by default the
     * widget's record, or its page's resource model) change on `realtime()` panels, with
     * polling at `fallbackPollingInterval` without Echo. Also set as properties:
     * `protected bool|Closure $hasLiveUpdates = true; protected array|Closure|null $liveModels = [Order::class];`
     *
     * @param  array<class-string>|class-string|Closure|null  $models
     */
    public function live(array|string|Closure|null $models = null, bool|Closure $condition = true, int|Closure $debounce = 1000, string|Closure|null $fallbackPollingInterval = null): static
    {
        $this->configureLiveUpdates($condition, $debounce, $fallbackPollingInterval, is_string($models) ? [$models] : $models);

        return $this;
    }

    protected function getDefaultLiveModels(): array
    {
        if ($this->record !== null) {
            return [$this->record::class];
        }

        $resource = $this->page instanceof HasResource ? $this->page->getResourceClass() : null;

        return $resource === null ? [] : [$resource::getModel()];
    }

    public function getType(): string
    {
        return static::$component !== null ? 'View' : class_basename(static::class);
    }

    /**
     * Data for custom widgets rendered by a registered frontend component.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return Serializable::compact([
            'type' => $this->getType(),
            'key' => $this->getId(),
            'id' => $this->getId(),
            'component' => static::$component,
            'props' => static::$component !== null ? $this->getViewData() : null,
            'columnSpan' => $this->getColumnSpan(),
            'live' => $this->getLiveUpdatesSchema(),
            ...$this->getSchemaProps(),
        ]);
    }

    /**
     * The stand-in sent in place of a lazy widget until its schema arrives: enough to
     * size its grid cell and match it to the loaded schema (`widget:<id>`).
     *
     * @return array<string, mixed>
     */
    public function toLazySchema(): array
    {
        return Serializable::compact([
            'type' => $this->getType(),
            'key' => $this->getId(),
            'id' => $this->getId(),
            'component' => static::$component,
            'columnSpan' => $this->getColumnSpan(),
            'lazy' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSchemaProps(): array
    {
        return [];
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'record' => [$this->record],
            'page' => [$this->page],
            default => [],
        };
    }
}
