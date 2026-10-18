<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\BelongsToContainer;
use AlfaSix\Alfakit\Schemas\Components\Concerns\CanBeDisabled;
use AlfaSix\Alfakit\Schemas\Components\Concerns\CanBeHidden;
use AlfaSix\Alfakit\Schemas\Components\Concerns\CanGrow;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasChildComponents;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColumns;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColumnSpan;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasFrontendContext;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasGap;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasId;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasInlineLabel;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasKey;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasLabel;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasMeta;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Concerns\HasRootHeadingLevel;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasExtraAttributes;
use AlfaSix\Alfakit\Support\Serializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;

/**
 * Base class for everything that can appear inside a Schema: form fields, infolist
 * entries, layout containers and prime components.
 *
 * A component is configured fluently in PHP and serialised to a plain array via
 * `toSchema()`. That array is the contract consumed by the `@alfakit/*` renderers.
 */
abstract class Component
{
    use BelongsToContainer;
    use CanBeDisabled;
    use CanBeHidden;
    use CanGrow;
    use Configurable;
    use EvaluatesClosures;
    use HasChildComponents;
    use HasColumns;
    use HasColumnSpan;
    use HasExtraAttributes;
    use HasFrontendContext;
    use HasGap;
    use HasId;
    use HasInlineLabel;
    use HasKey;
    use HasLabel;
    use HasMeta;
    use HasRootHeadingLevel;
    use Macroable;

    /**
     * The renderer type name. Defaults to the short class name (e.g. `TextInput`).
     */
    protected ?string $type = null;

    public static function make(mixed ...$arguments): static
    {
        $static = new static(...$arguments);
        $static->configure();

        return $static;
    }

    public function getType(): string
    {
        return $this->type ?? class_basename(static::class);
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1];
    }

    /**
     * Serialise the component (and its visible children) for the frontend.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $schema = [
            'type' => $this->getType(),
            'key' => $this->getKey(),
            'id' => $this->getId(),
            'label' => $this->isLabelHidden() ? null : $this->getLabel(),
            'disabled' => $this->isDisabled() ?: null,
            'columns' => $this->hasChildComponents() ? $this->getColumns() : null,
            'columnSpan' => $this->getColumnSpan(),
            'columnStart' => $this->getColumnStart(),
            'columnOrder' => $this->getColumnOrder(),
            'grow' => $this->getGrowSchema(),
            'extraAttributes' => $this->getExtraAttributes() ?: null,
            'meta' => $this->getMeta() ?: null,
        ];

        if ($this->hasChildComponents()) {
            $deferred = $this->shouldRenderDeferredPlaceholder();
            // `deferLoading()`: the children arrive in a follow-up request; the renderer needs
            // the paths of the fields inside to load them early when one has an error.
            $schema['schema'] = $deferred ? [] : $this->getChildSchema()->toSchema();
            $schema['deferred'] = $deferred ?: null;
            $schema['deferredPaths'] = $deferred ? array_keys($this->getChildSchema()->getFlatFields()) : null;
            $schema['dense'] = $this->isDense() ?: null;
            $schema['gap'] = $this->hasGap() ? null : false;
        }

        $schema['context'] = $this->getFrontendContext();

        return Serializable::compact([
            ...$schema,
            ...$this->getSchemaProps(),
        ]);
    }

    /**
     * Whether this render sends the component without its children (`deferLoading()`).
     */
    public function shouldRenderDeferredPlaceholder(): bool
    {
        return false;
    }

    /**
     * Component-specific serialised properties. Override in subclasses.
     *
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
            'get' => [$this->makeGetUtility()],
            'set' => [$this->makeSetUtility()],
            'model' => [$this->getModel()],
            'operation', 'context' => [$this->getOperation()],
            'record' => [$this->getRecord()],
            'schema', 'container' => [$this->getContainer()],
            'state' => [method_exists($this, 'getState') ? $this->getState() : null],
            'rawState' => [method_exists($this, 'getRawState') ? $this->getRawState() : null],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        $record = $this->getRecord();

        return match ($parameterType) {
            Model::class => [$record],
            Schema::class => [$this->getContainer()],
            Get::class => [$this->makeGetUtility()],
            Set::class => [$this->makeSetUtility()],
            default => ($record instanceof $parameterType) ? [$record] : [],
        };
    }

    public function makeGetUtility(): Get
    {
        return new Get($this);
    }

    public function makeSetUtility(): Set
    {
        return new Set($this);
    }

    /**
     * Component-level state path used for relative `$get('../other')` resolution. Layout
     * components return their container's path; fields override with their own.
     */
    public function getStatePath(bool $isAbsolute = true): ?string
    {
        return $this->getContainer()->getStatePath();
    }

    /**
     * Prepare the component after the container has been attached. Hook for children.
     */
    public function prepare(): void {}

    /**
     * Whether this component hydrates and dehydrates its child fields itself, per item
     * (as a Repeater does), instead of letting the root schema handle them.
     */
    public function managesChildFieldState(): bool
    {
        return false;
    }

    /**
     * For components that manage child state: find a nested field by absolute path.
     */
    public function getNestedFieldByStatePath(string $path): ?\AlfaSix\Alfakit\Forms\Components\Field
    {
        return null;
    }

    public function __clone(): void
    {
        $this->container = null;
        $this->childSchema = null;

        if (is_array($this->childComponents)) {
            $this->childComponents = array_map(static fn (Component $component): Component => clone $component, $this->childComponents);
        }
    }
}
