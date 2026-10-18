<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Summarizers;

use AlfaSix\Alfakit\Support\Concerns\CanFormatState;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\NumberFormat;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Columns\Column;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Summarises a column over a set of records with one aggregate query: the current page,
 * every record matching the filters, or one row group.
 *
 * The base class is the custom summarizer:
 *
 * ```php
 * TextColumn::make('price')->summarize([
 *     Sum::make()->money('EUR'),
 *     Summarizer::make()->label('Median')->using(fn (QueryBuilder $query, string $attribute) => …),
 * ])
 * ```
 *
 * Values are formatted on the server with the same helpers as text columns (`numeric()`,
 * `money()`, `date()`, `prefix()`, `formatStateUsing()`); without any of them numbers are
 * shown with up to two decimals in the panel's locale.
 */
class Summarizer
{
    use CanFormatState;
    use Configurable;
    use EvaluatesClosures;

    protected ?string $id;

    protected string|Closure|null $label = null;

    protected ?Closure $using = null;

    protected ?Closure $modifyQueryUsing = null;

    protected string|Closure|null $attribute = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    protected bool|Closure $isLabelHidden = false;

    protected ?Column $column = null;

    final public function __construct(?string $id = null)
    {
        $this->id = $id;
    }

    public static function make(?string $id = null): static
    {
        $static = new static($id);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'summarizer';
    }

    public function getId(): string
    {
        return $this->id ?? strtolower(class_basename(static::class));
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Hide the label visually; screen readers still announce it.
     */
    public function hiddenLabel(bool|Closure $condition = true): static
    {
        $this->isLabelHidden = $condition;

        return $this;
    }

    public function isLabelHidden(): bool
    {
        return (bool) $this->evaluate($this->isLabelHidden);
    }

    public function getLabel(): ?string
    {
        return $this->evaluate($this->label) ?? $this->getDefaultLabel();
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }

    /**
     * Compute the summary yourself: `fn (QueryBuilder $query, string $attribute): mixed`,
     * where `$query` selects from the records being summarised (a sub-select aliased
     * `alfakit_summary`) and `$attribute` is the column name.
     */
    public function using(?Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    /**
     * Narrow the records before summarising: `fn (Builder $query) => $query->where(…)`.
     */
    public function query(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * Summarise another database column than the column's name.
     */
    public function attribute(string|Closure|null $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
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

    public function column(?Column $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function getColumn(): ?Column
    {
        return $this->column;
    }

    public function getAttribute(): ?string
    {
        return $this->evaluate($this->attribute) ?? $this->column?->getName();
    }

    /**
     * The raw summary of the records in `$query` (an Eloquent query with filters applied).
     */
    public function getState(Builder $query): mixed
    {
        $query = (clone $query)->reorder();

        if ($this->modifyQueryUsing !== null) {
            $query = $this->evaluate($this->modifyQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        $attribute = $this->getAttribute();

        // The records are wrapped in a sub-select so selected aliases (relationship
        // aggregates such as `comments_count`) can be summarised like real columns.
        $base = $query->getQuery()->newQuery()->fromSub($query->toBase(), 'alfakit_summary');

        if ($this->using !== null) {
            return $this->evaluate($this->using, ['query' => $base, 'attribute' => $attribute], [QueryBuilder::class => $base, Builder::class => $query]);
        }

        // Relationship columns (`author.name`) have no column on this table to aggregate;
        // they are only summarised by a custom `using()` callback.
        if ($attribute === null || str_contains($attribute, '.')) {
            return null;
        }

        return $this->summarize($base, $attribute);
    }

    /**
     * The aggregate itself. The base summarizer only runs `using()`.
     */
    protected function summarize(QueryBuilder $query, string $attribute): mixed
    {
        return null;
    }

    /**
     * Format a summary value for display.
     */
    public function formatSummaryState(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (! $this->hasFormatting() && is_numeric($state)) {
            return NumberFormat::format((float) $state, maxPrecision: 2) ?: (string) $state;
        }

        $formatted = Serializable::value($this->formatState($state));

        return is_scalar($formatted) ? (string) $formatted : null;
    }

    protected function hasFormatting(): bool
    {
        return $this->formatStateUsing !== null
            || $this->isMoney
            || (bool) $this->evaluate($this->isNumeric)
            || $this->dateFormat !== null
            || (bool) $this->evaluate($this->isSince)
            || $this->prefix !== null
            || $this->suffix !== null;
    }

    /**
     * @return array{label?: string, hiddenLabel?: true, value: string|null, icons?: list<array{icon: string, color: string|null, count: int}>}
     */
    public function toSummary(Builder $query): array
    {
        return $this->withLabel(['value' => $this->formatSummaryState($this->getState($query))]);
    }

    /**
     * @param  array{value: string|null, icons?: list<array{icon: string, color: string|null, count: int}>}  $summary
     * @return array{label?: string, hiddenLabel?: true, value: string|null, icons?: list<array{icon: string, color: string|null, count: int}>}
     */
    protected function withLabel(array $summary): array
    {
        $label = $this->getLabel();

        if ($label === null) {
            return $summary;
        }

        return $this->isLabelHidden()
            ? ['label' => $label, 'hiddenLabel' => true, ...$summary]
            : ['label' => $label, ...$summary];
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'column' => [$this->column],
            'table' => [$this->column?->getTable()],
            default => [],
        };
    }
}
