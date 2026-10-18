<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\Support;

use AlfaSix\Alfakit\Forms\Components\Select;
use Closure;

/**
 * The select of a relationship `SelectFilter` with `hasEmptyOption()`: the relationship's
 * options plus a "None" option (`EMPTY_VALUE`) for records without a related record. It
 * renders as a plain `Select`.
 */
class EmptyOptionSelect extends Select
{
    public const EMPTY_VALUE = '__empty';

    protected ?string $type = 'Select';

    protected string|Closure|null $emptyOptionLabel = null;

    public function emptyOptionLabel(string|Closure|null $label): static
    {
        $this->emptyOptionLabel = $label;

        return $this;
    }

    public function getEmptyOptionLabel(): string
    {
        return (string) ($this->evaluate($this->emptyOptionLabel) ?? __('alfakit::tables.filters.select.empty'));
    }

    public function getOptions(): array
    {
        $options = parent::getOptions();

        // A searchable select without preloading searches the server: "None" comes with the results.
        if ($options === [] && $this->isSearchable() && ! $this->evaluate($this->isPreloaded)) {
            return [];
        }

        return [self::EMPTY_VALUE => $this->getEmptyOptionLabel()] + $options;
    }

    public function getSearchResults(string $search): array
    {
        $results = parent::getSearchResults($search);
        $label = $this->getEmptyOptionLabel();

        return str_contains(mb_strtolower($label), mb_strtolower($search)) ? [self::EMPTY_VALUE => $label] + $results : $results;
    }

    public function getOptionLabelsFor(array $values): array
    {
        $empty = in_array(self::EMPTY_VALUE, array_map(strval(...), array_filter($values, is_scalar(...))), true);
        $labels = parent::getOptionLabelsFor(array_values(array_filter($values, static fn (mixed $value): bool => (string) $value !== self::EMPTY_VALUE)));

        return $empty ? [self::EMPTY_VALUE => $this->getEmptyOptionLabel()] + $labels : $labels;
    }
}
