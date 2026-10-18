<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use AlfaSix\Alfakit\Tables\Filters\Support\EmptyOptionSelect;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SelectFilter extends BaseFilter
{
    use HasOptions;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isSearchable = false;

    protected bool|Closure $isNative = false;

    protected bool|Closure $isPreloaded = false;

    protected string|Closure|null $attribute = null;

    protected string|Closure|null $placeholder = null;

    protected bool|Closure $canSelectPlaceholder = true;

    protected string|Closure|null $relationshipName = null;

    protected string|Closure|null $relationshipTitleAttribute = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    protected int|Closure $optionsLimit = 50;

    protected bool|Closure $hasEmptyOption = false;

    protected string|Closure|null $emptyRelationshipOptionLabel = null;

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function native(bool|Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    public function preload(bool|Closure $condition = true): static
    {
        $this->isPreloaded = $condition;

        return $this;
    }

    public function attribute(string|Closure|null $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function selectablePlaceholder(bool|Closure $condition = true): static
    {
        $this->canSelectPlaceholder = $condition;

        return $this;
    }

    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    /**
     * Options from a relationship. `hasEmptyOption: true` adds a "None" option matching
     * records without a related record.
     */
    public function relationship(string|Closure $name, string|Closure $titleAttribute = 'name', ?Closure $modifyQueryUsing = null, bool|Closure $hasEmptyOption = false): static
    {
        $this->relationshipName = $name;
        $this->relationshipTitleAttribute = $titleAttribute;
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;
        $this->hasEmptyOption = $hasEmptyOption;

        return $this;
    }

    /**
     * Add a "None" option (relationship filters only) for records without a related record.
     */
    public function hasEmptyOption(bool|Closure $condition = true): static
    {
        $this->hasEmptyOption = $condition;

        return $this;
    }

    public function emptyRelationshipOptionLabel(string|Closure|null $label): static
    {
        $this->emptyRelationshipOptionLabel = $label;

        return $this;
    }

    public function hasEmptyRelationshipOption(): bool
    {
        return $this->relationshipName !== null && (bool) $this->evaluate($this->hasEmptyOption);
    }

    public function hasRelationship(): bool
    {
        return $this->relationshipName !== null;
    }

    public function getRelationshipName(): ?string
    {
        $name = $this->evaluate($this->relationshipName);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function getAttribute(): string
    {
        return $this->evaluate($this->attribute) ?? $this->getName();
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getFormComponents(): array
    {
        $select = ($this->hasEmptyRelationshipOption()
            ? EmptyOptionSelect::make($this->isMultiple() ? 'values' : 'value')->emptyOptionLabel($this->emptyRelationshipOptionLabel)
            : Select::make($this->isMultiple() ? 'values' : 'value'))
            ->label($this->getLabel())
            ->multiple($this->isMultiple())
            ->searchable($this->isSearchable)
            ->native($this->isNative)
            ->preload($this->isPreloaded)
            ->optionsLimit($this->optionsLimit)
            ->placeholder($this->evaluate($this->placeholder) ?? __('alfakit::tables.filters.select.placeholder'))
            ->selectablePlaceholder($this->canSelectPlaceholder);

        if ($this->relationshipName !== null) {
            $select->relationship($this->relationshipName, $this->relationshipTitleAttribute, $this->modifyRelationshipQueryUsing);
        } else {
            $select->options(fn (): array => $this->getOptions());
        }

        return [$select];
    }

    /**
     * @return array<mixed>
     */
    protected function getSelectedValues(): array
    {
        $values = $this->isMultiple() ? ($this->state['values'] ?? []) : ($this->state['value'] ?? null);

        return array_values(array_filter(Arr::wrap($values), static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function isActive(): bool
    {
        return $this->getSelectedValues() !== [];
    }

    protected function applyToQuery(Builder $query): Builder
    {
        $values = $this->getSelectedValues();
        $relationship = $this->evaluate($this->relationshipName);

        if ($relationship !== null) {
            $related = $query->getModel()->{$relationship}();
            $keyName = method_exists($related, 'getOwnerKeyName') ? $related->getOwnerKeyName() : $related->getRelated()->getKeyName();

            if ($this->hasEmptyRelationshipOption() && in_array(EmptyOptionSelect::EMPTY_VALUE, array_map(strval(...), $values), true)) {
                $values = array_values(array_filter($values, static fn (mixed $value): bool => (string) $value !== EmptyOptionSelect::EMPTY_VALUE));

                return $query->where(static function (Builder $query) use ($relationship, $keyName, $values): void {
                    $query->whereDoesntHave($relationship);

                    if ($values !== []) {
                        $query->orWhereHas($relationship, static fn (Builder $q) => $q->whereIn($q->qualifyColumn($keyName), $values));
                    }
                });
            }

            return $query->whereHas($relationship, static fn (Builder $q) => $q->whereIn($q->qualifyColumn($keyName), $values));
        }

        return $this->isMultiple()
            ? $query->whereIn($query->qualifyColumn($this->getAttribute()), $values)
            : $query->where($query->qualifyColumn($this->getAttribute()), $values[0]);
    }

    /**
     * Custom-data rows whose attribute matches a selected value (compared as strings).
     * Relationship filters need a query, so they leave the rows alone.
     */
    protected function applyToRecordsByDefault(Collection $records): Collection
    {
        if ($this->evaluate($this->relationshipName) !== null) {
            return $records;
        }

        $values = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $this->getSelectedValues());
        $attribute = $this->getAttribute();

        return $records->filter(static function (mixed $row) use ($attribute, $values): bool {
            $value = data_get($row, $attribute);

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            return is_scalar($value) && in_array((string) $value, $values, true);
        });
    }

    protected function getDefaultIndicator(): ?string
    {
        $select = $this->getForm()[0];
        $select->container($this->getFormSchema());
        $labels = $select instanceof Select ? $select->getOptionLabelsFor($this->getSelectedValues()) : [];

        return $this->getLabel().': '.implode(', ', $labels ?: $this->getSelectedValues());
    }

    protected function getSchemaProps(): array
    {
        return ['multiple' => $this->isMultiple() ?: null];
    }
}
