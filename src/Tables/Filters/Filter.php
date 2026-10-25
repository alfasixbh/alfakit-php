<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Component;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A custom filter: either a simple on/off toggle, or a form whose data is passed to
 * `query()`.
 *
 * ```php
 * Filter::make('is_featured')->query(fn (Builder $q) => $q->where('is_featured', true))
 *
 * Filter::make('created')->form([DatePicker::make('from'), DatePicker::make('until')])
 *     ->query(fn (Builder $q, array $data) => $q
 *         ->when($data['from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
 *         ->when($data['until'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
 * ```
 */
class Filter extends BaseFilter
{
    /** @var array<Component>|Closure|null */
    protected array|Closure|null $formComponents = null;

    protected bool $isToggle = false;

    /**
     * @param  array<Component>|Closure|null  $components
     */
    public function form(array|Closure|null $components): static
    {
        $this->formComponents = $components;

        return $this;
    }

    /**
     * @param  array<Component>|Closure|null  $components
     */
    public function schema(array|Closure|null $components): static
    {
        return $this->form($components);
    }

    public function toggle(): static
    {
        $this->isToggle = true;

        return $this;
    }

    public function checkbox(): static
    {
        $this->isToggle = false;

        return $this;
    }

    protected function hasBuiltInFormField(): bool
    {
        return ! $this->hasCustomForm();
    }

    public function hasCustomForm(): bool
    {
        return $this->formComponents !== null;
    }

    public function getFormComponents(): array
    {
        if ($this->hasCustomForm()) {
            return $this->evaluate($this->formComponents) ?? [];
        }

        $component = $this->isToggle ? Toggle::make('isActive') : Checkbox::make('isActive');

        return [$component->label($this->getLabel())];
    }

    /**
     * A custom form's `$data` holds every one of its fields, `null` when not filled, so
     * `$data['until']` exists while only `from` is set (as in Filament).
     *
     * @param  array<string, mixed>  $state
     */
    public function state(array $state): static
    {
        if ($this->hasCustomForm()) {
            $state = [...$this->getBlankFormState(), ...$state];
        }

        return parent::state($state);
    }

    /**
     * @return array<string, null>
     */
    protected function getBlankFormState(): array
    {
        $prefix = $this->getName().'.';
        $blank = [];

        foreach (array_keys($this->getFormSchema()->getFlatFields(withHidden: true)) as $path) {
            $key = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
            $blank[explode('.', $key)[0]] = null;
        }

        return $blank;
    }

    public function isActive(): bool
    {
        if (! $this->hasCustomForm()) {
            return (bool) ($this->state['isActive'] ?? false);
        }

        return parent::isActive();
    }

    protected function applyToQuery(Builder $query): Builder
    {
        return $query;
    }
}
