<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use AlfaSix\Alfakit\Forms\Components\Select;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Yes / No / All filter. By default it filters a boolean column; use `nullable()` for a
 * nullable column, or `queries()` for custom behaviour.
 */
class TernaryFilter extends BaseFilter
{
    protected string|Closure|null $attribute = null;

    protected string|Closure|null $trueLabel = null;

    protected string|Closure|null $falseLabel = null;

    protected string|Closure|null $placeholder = null;

    protected ?Closure $trueQuery = null;

    protected ?Closure $falseQuery = null;

    protected ?Closure $blankQuery = null;

    protected bool|Closure $isNullable = false;

    protected bool|Closure $isNative = false;

    public function attribute(string|Closure|null $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function trueLabel(string|Closure|null $label): static
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(string|Closure|null $label): static
    {
        $this->falseLabel = $label;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function queries(?Closure $true = null, ?Closure $false = null, ?Closure $blank = null): static
    {
        $this->trueQuery = $true;
        $this->falseQuery = $false;
        $this->blankQuery = $blank;

        return $this;
    }

    public function nullable(bool|Closure $condition = true): static
    {
        $this->isNullable = $condition;

        return $this;
    }

    public function boolean(): static
    {
        $this->isNullable = false;

        return $this;
    }

    public function native(bool|Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    public function getAttribute(): string
    {
        return $this->evaluate($this->attribute) ?? $this->getName();
    }

    public function getFormComponents(): array
    {
        return [
            Select::make('value')
                ->label($this->getLabel())
                ->native($this->isNative)
                ->placeholder($this->evaluate($this->placeholder) ?? __('alfakit::tables.filters.ternary.all'))
                ->options([
                    '1' => $this->evaluate($this->trueLabel) ?? __('alfakit::tables.filters.ternary.true'),
                    '0' => $this->evaluate($this->falseLabel) ?? __('alfakit::tables.filters.ternary.false'),
                ]),
        ];
    }

    public function isActive(): bool
    {
        $value = $this->state['value'] ?? null;

        return $value !== null && $value !== '';
    }

    public function apply(Builder $query): Builder
    {
        $value = $this->state['value'] ?? null;

        if ($value === null || $value === '') {
            if ($this->blankQuery !== null) {
                $this->evaluate($this->blankQuery, ['query' => $query], [Builder::class => $query]);
            }

            return $query;
        }

        $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $customQuery = $isTrue ? $this->trueQuery : $this->falseQuery;

        if ($customQuery !== null) {
            $result = $this->evaluate($customQuery, ['query' => $query], [Builder::class => $query]);

            return $result instanceof Builder ? $result : $query;
        }

        $column = $query->qualifyColumn($this->getAttribute());

        if ($this->evaluate($this->isNullable)) {
            return $isTrue ? $query->whereNotNull($column) : $query->whereNull($column);
        }

        return $query->where($column, $isTrue);
    }

    /**
     * Custom-data rows: `queries()` closures get the rows (see `applyToRecords()`); by
     * default the attribute is compared as a boolean, or checked for null with `nullable()`.
     */
    public function applyToRecords(Collection $records): Collection
    {
        $value = $this->state['value'] ?? null;

        if ($value === null || $value === '') {
            return $records;
        }

        $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $customQuery = $isTrue ? $this->trueQuery : $this->falseQuery;

        if ($customQuery !== null) {
            return $this->applyClosureToRecords($customQuery, $records);
        }

        $attribute = $this->getAttribute();
        $nullable = (bool) $this->evaluate($this->isNullable);

        return $records->filter(static function (mixed $row) use ($attribute, $isTrue, $nullable): bool {
            $state = data_get($row, $attribute);

            if ($nullable) {
                return $isTrue ? $state !== null : $state === null;
            }

            return filter_var($state, FILTER_VALIDATE_BOOLEAN) === $isTrue;
        });
    }

    protected function getDefaultIndicator(): ?string
    {
        $value = filter_var($this->state['value'] ?? null, FILTER_VALIDATE_BOOLEAN);
        $label = $value
            ? ($this->evaluate($this->trueLabel) ?? __('alfakit::tables.filters.ternary.true'))
            : ($this->evaluate($this->falseLabel) ?? __('alfakit::tables.filters.ternary.false'));

        return $this->getLabel().': '.$label;
    }
}
