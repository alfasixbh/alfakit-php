<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Layout;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasExtraAttributes;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;

/**
 * Base class for table content layouts (`Split`, `Stack`, `Panel`, `Grid`). A table whose
 * columns contain a layout renders each record as a block arranged by the layout instead
 * of a row of cells. Layouts are static: they arrange columns, the columns produce the
 * per-record cells.
 */
abstract class Component
{
    use \AlfaSix\Alfakit\Schemas\Components\Concerns\HasColumnSpan;
    use Configurable;
    use EvaluatesClosures;
    use HasExtraAttributes;
    use Macroable;

    /** @var array<Column|Component>|Closure */
    protected array|Closure $schema = [];

    protected ?Table $table = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    protected string|Closure|null $visibleFrom = null;

    protected string|Closure|null $hiddenFrom = null;

    protected bool|Closure $canGrow = true;

    /** @var array<Column|Component>|null */
    protected ?array $resolvedSchema = null;

    /**
     * @param  array<Column|Component>|Closure  $schema
     */
    final public function __construct(array|Closure $schema = [])
    {
        $this->schema = $schema;
    }

    /**
     * @param  array<Column|Component>|Closure  $schema
     */
    public static function make(array|Closure $schema = []): static
    {
        $static = new static($schema);
        $static->configure();

        return $static;
    }

    protected function setUp(): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'layout';
    }

    public function getType(): string
    {
        return class_basename(static::class);
    }

    /**
     * @param  array<Column|Component>|Closure  $schema
     */
    public function schema(array|Closure $schema): static
    {
        $this->schema = $schema;
        $this->resolvedSchema = null;

        return $this;
    }

    /**
     * @return array<Column|Component>
     */
    public function getSchema(): array
    {
        if ($this->resolvedSchema !== null) {
            return $this->resolvedSchema;
        }

        $schema = [];

        foreach ($this->evaluate($this->schema) ?? [] as $item) {
            if (! $item instanceof Column && ! $item instanceof self) {
                throw new InvalidArgumentException('Table layouts accept columns and layout components only.');
            }

            $schema[] = $item;
        }

        return $this->resolvedSchema = $schema;
    }

    /**
     * Every column inside this layout, depth first.
     *
     * @return array<Column>
     */
    public function getColumns(): array
    {
        $columns = [];

        foreach ($this->getSchema() as $item) {
            array_push($columns, ...($item instanceof self ? $item->getColumns() : [$item]));
        }

        return $columns;
    }

    public function table(?Table $table): static
    {
        $this->table = $table;

        foreach ($this->getSchema() as $item) {
            if ($item instanceof self) {
                $item->table($table);
            }
        }

        return $this;
    }

    public function getTable(): ?Table
    {
        return $this->table;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->isHidden) || ! $this->evaluate($this->isVisible);
    }

    /**
     * Show the layout only from this breakpoint up (`sm`, `md`, `lg`, `xl`, `2xl`).
     */
    public function visibleFrom(string|Closure|null $breakpoint): static
    {
        $this->visibleFrom = $breakpoint;

        return $this;
    }

    /**
     * Hide the layout from this breakpoint up.
     */
    public function hiddenFrom(string|Closure|null $breakpoint): static
    {
        $this->hiddenFrom = $breakpoint;

        return $this;
    }

    /**
     * Inside a `Split`, whether this layout takes the remaining width (default) or only
     * the width of its content.
     */
    public function grow(bool|Closure $condition = true): static
    {
        $this->canGrow = $condition;

        return $this;
    }

    /**
     * Static layout tree for the renderer. Columns are referenced by name; hidden columns
     * and hidden layouts are left out.
     *
     * @return array<string, mixed>|null
     */
    public function toSchema(): ?array
    {
        if ($this->isHidden()) {
            return null;
        }

        $children = [];

        foreach ($this->getSchema() as $item) {
            $child = $item instanceof self ? $item->toSchema() : self::columnReference($item);

            if ($child !== null) {
                $children[] = $child;
            }
        }

        return Serializable::compact([
            'type' => $this->getType(),
            'schema' => $children,
            'visibleFrom' => self::breakpoint($this->evaluate($this->visibleFrom)),
            'hiddenFrom' => self::breakpoint($this->evaluate($this->hiddenFrom)),
            'grow' => $this->evaluate($this->canGrow) ? null : false,
            'extraAttributes' => $this->getExtraAttributes() ?: null,
            // Inside a `Grid`: `columnSpan()` / `columnStart()` (a number applies from `lg` up).
            'columnSpan' => $this->getColumnSpan(),
            'columnStart' => $this->getColumnStart(),
            ...$this->getSchemaProps(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function columnReference(Column $column): ?array
    {
        if ($column->isHidden()) {
            return null;
        }

        return Serializable::compact([
            'type' => 'Column',
            'name' => $column->getName(),
            // Inside a `Grid`: `columnSpan()` / `columnStart()` (a number applies from `lg` up).
            'columnSpan' => $column->getColumnSpan(),
            'columnStart' => $column->getColumnStart(),
        ]);
    }

    protected static function breakpoint(mixed $breakpoint): ?string
    {
        return in_array($breakpoint, ['sm', 'md', 'lg', 'xl', '2xl'], true) ? $breakpoint : null;
    }

    /**
     * Layout-specific props.
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
            'table' => [$this->table],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return match (true) {
            $parameterType === Table::class => [$this->table],
            default => [],
        };
    }
}
