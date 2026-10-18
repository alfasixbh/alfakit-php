<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\MorphToSelect;

use AlfaSix\Alfakit\Support\Labels;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One model a `MorphToSelect` can point at. Its options come from the model's table
 * (tenant scoped), labelled by `titleAttribute()` or `getOptionLabelFromRecordUsing()`.
 */
class Type
{
    protected string|Closure|null $label = null;

    protected string|Closure|null $titleAttribute = null;

    protected ?Closure $getOptionLabelFromRecordUsing = null;

    protected ?Closure $modifyOptionsQueryUsing = null;

    protected ?Closure $modifyKeySelectUsing = null;

    /** @var array<int, string>|null */
    protected ?array $searchColumns = null;

    /**
     * @param  class-string<Model>  $model
     */
    final public function __construct(protected string $model) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function make(string $model): static
    {
        return new static($model);
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function titleAttribute(string|Closure|null $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * `fn (Model $record): string` (the closure may type-hint the model class).
     */
    public function getOptionLabelFromRecordUsing(?Closure $callback): static
    {
        $this->getOptionLabelFromRecordUsing = $callback;

        return $this;
    }

    /**
     * `fn (Builder $query) => $query->where(...)`: narrows the options and the records a
     * submitted key may point at.
     */
    public function modifyOptionsQueryUsing(?Closure $callback): static
    {
        $this->modifyOptionsQueryUsing = $callback;

        return $this;
    }

    /**
     * `fn (Select $select): Select`: customises the key select while this type is chosen.
     */
    public function modifyKeySelectUsing(?Closure $callback): static
    {
        $this->modifyKeySelectUsing = $callback;

        return $this;
    }

    /**
     * Columns searched by a searchable key select (defaults to the title attribute).
     *
     * @param  array<int, string>|null  $columns
     */
    public function searchColumns(?array $columns): static
    {
        $this->searchColumns = $columns;

        return $this;
    }

    /**
     * @return class-string<Model>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * The value stored in the morph type column: the morph map alias when the model has
     * one, else the class name.
     */
    public function getAlias(): string
    {
        return (new $this->model)->getMorphClass();
    }

    public function getLabelUsing(Closure $evaluate): string
    {
        $label = $evaluate($this->label);

        return is_string($label) && $label !== '' ? $label : Str::ucfirst(Labels::fromClass($this->model));
    }

    public function getTitleAttributeUsing(Closure $evaluate): ?string
    {
        $attribute = $evaluate($this->titleAttribute);

        return is_string($attribute) && $attribute !== '' ? $attribute : null;
    }

    public function getOptionLabelFromRecordCallback(): ?Closure
    {
        return $this->getOptionLabelFromRecordUsing;
    }

    public function getModifyOptionsQueryCallback(): ?Closure
    {
        return $this->modifyOptionsQueryUsing;
    }

    public function getModifyKeySelectCallback(): ?Closure
    {
        return $this->modifyKeySelectUsing;
    }

    /**
     * @return array<int, string>|null
     */
    public function getSearchColumns(): ?array
    {
        return $this->searchColumns;
    }
}
