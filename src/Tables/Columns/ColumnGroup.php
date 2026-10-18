<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasExtraAttributes;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Support\Traits\Macroable;

/**
 * A shared header above several adjacent columns.
 *
 * ```php
 * ColumnGroup::make('Visibility', [
 *     IconColumn::make('is_published')->boolean(),
 *     TextColumn::make('published_at')->date(),
 * ])->alignCenter()
 * ```
 */
class ColumnGroup
{
    use Configurable;
    use EvaluatesClosures;
    use HasExtraAttributes;
    use Macroable;

    protected string|Closure $label;

    /** @var array<Column>|Closure */
    protected array|Closure $columns = [];

    protected Alignment|Closure|null $alignment = null;

    protected bool|Closure $isHeaderWrapped = false;

    protected ?Table $table = null;

    /**
     * @param  array<Column>|Closure  $columns
     */
    final public function __construct(string|Closure $label, array|Closure $columns = [])
    {
        $this->label = $label;
        $this->columns = $columns;
    }

    /**
     * @param  array<Column>|Closure  $columns
     */
    public static function make(string|Closure $label, array|Closure $columns = []): static
    {
        $static = new static($label, $columns);
        $static->configure();

        return $static;
    }

    protected function setUp(): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'group';
    }

    /**
     * @param  array<Column>|Closure  $columns
     */
    public function columns(array|Closure $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @return array<Column>
     */
    public function getColumns(): array
    {
        return array_values(array_filter($this->evaluate($this->columns) ?? [], static fn (mixed $column): bool => $column instanceof Column));
    }

    public function label(string|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return (string) $this->evaluate($this->label);
    }

    public function alignment(Alignment|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(): static
    {
        return $this->alignment(Alignment::Start);
    }

    public function alignCenter(): static
    {
        return $this->alignment(Alignment::Center);
    }

    public function alignEnd(): static
    {
        return $this->alignment(Alignment::End);
    }

    public function wrapHeader(bool|Closure $condition = true): static
    {
        $this->isHeaderWrapped = $condition;

        return $this;
    }

    public function table(?Table $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toSchema(): ?array
    {
        $columns = array_values(array_map(
            static fn (Column $column): string => $column->getName(),
            array_filter($this->getColumns(), static fn (Column $column): bool => ! $column->isHidden()),
        ));

        if ($columns === []) {
            return null;
        }

        return Serializable::compact([
            'label' => $this->getLabel(),
            'columns' => $columns,
            'alignment' => $this->evaluate($this->alignment)?->value,
            'wrapHeader' => $this->evaluate($this->isHeaderWrapped) ?: null,
            'extraHeaderAttributes' => $this->getExtraAttributes() ?: null,
        ]);
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
}
