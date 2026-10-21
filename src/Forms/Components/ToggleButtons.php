<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\HasOptionsGrid;
use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use AlfaSix\Alfakit\Support\Enums\Size;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * A group of buttons acting as a radio (or, with `multiple()`, a checkbox list). Options
 * may carry colours and icons, set with `colors()` and `icons()`.
 */
class ToggleButtons extends Field
{
    use HasOptions;
    use HasOptionsGrid;

    protected bool|Closure $isInline = false;

    protected bool|Closure $isGrouped = false;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isBoolean = false;

    protected bool|Closure $areIconsOnly = false;

    /** @var array<string|int, string>|Closure */
    protected array|Closure $tooltips = [];

    protected Size|string|Closure|null $size = null;

    protected bool|Closure $isFullWidth = false;

    /**
     * A tooltip per option: `['draft' => 'Set as a draft before publishing.']`.
     *
     * @param  array<string|int, string>|Closure  $tooltips
     */
    public function tooltips(array|Closure $tooltips): static
    {
        $this->tooltips = $tooltips;

        return $this;
    }

    /**
     * Button size: `Size::ExtraSmall` … `Size::ExtraLarge` (or `'xs'` … `'xl'`); any other
     * string is added to the buttons as a CSS class. `null` is the default (medium).
     */
    public function size(Size|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Stretch the buttons to fill the field's width (stacked, inline or grouped).
     */
    public function fullWidth(bool|Closure $condition = true): static
    {
        $this->isFullWidth = $condition;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getTooltips(): array
    {
        $tooltips = [];

        foreach ($this->evaluate($this->tooltips) ?? [] as $value => $tooltip) {
            if (is_scalar($tooltip) && (string) $tooltip !== '') {
                $tooltips[(string) $value] = (string) $tooltip;
            }
        }

        return $tooltips;
    }

    public function getSize(): ?string
    {
        $size = $this->evaluate($this->size);
        $size = $size instanceof Size ? $size->value : $size;

        return is_string($size) && $size !== '' && $size !== Size::Medium->value ? $size : null;
    }

    public function inline(bool|Closure $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    /**
     * Join the buttons into one segmented control (always laid out inline).
     */
    public function grouped(bool|Closure $condition = true): static
    {
        $this->isGrouped = $condition;

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    /**
     * Show only the option icons; labels become the buttons' accessible names.
     */
    public function hiddenButtonLabels(bool|Closure $condition = true): static
    {
        $this->areIconsOnly = $condition;

        return $this;
    }

    /**
     * Yes / No buttons (stored as booleans), coloured success / danger with check / x
     * icons unless `colors()` / `icons()` say otherwise.
     */
    public function boolean(?string $trueLabel = null, ?string $falseLabel = null): static
    {
        $this->isBoolean = true;

        $this->options([
            1 => $trueLabel ?? __('alfakit::forms.select.boolean.true'),
            0 => $falseLabel ?? __('alfakit::forms.select.boolean.false'),
        ]);

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return $this->isMultiple() ? [] : null;
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($this->isMultiple()) {
            return array_values(Arr::wrap($state ?? []));
        }

        if ($state === '' || $state === null) {
            return null;
        }

        return $this->evaluate($this->isBoolean) ? (bool) $state : $state;
    }

    /**
     * Option values the user may pick: every option except disabled ones.
     *
     * @return array<int, string>
     */
    protected function getSelectableOptionValues(): array
    {
        return $this->getEnabledOptionValues();
    }

    protected function getTypeValidationRules(): array
    {
        if ($this->isMultiple()) {
            return ['array'];
        }

        if ($this->evaluate($this->isBoolean)) {
            return ['boolean'];
        }

        $options = $this->getSelectableOptionValues();

        return $options === [] ? [] : [Rule::in($options)];
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        if ($rules === [] || ! $this->isMultiple()) {
            return $rules;
        }

        $options = $this->getSelectableOptionValues();

        if ($options !== []) {
            $rules[$this->getStatePath().'.*'] = ['distinct', Rule::in($options)];
        }

        return $rules;
    }

    /**
     * Buttons built from an enum take its `HasColor` colours and `HasIcon` icons.
     */
    protected function getEnumOptionDetails(): array
    {
        return ['color', 'icon'];
    }

    protected function getSchemaProps(): array
    {
        $grouped = (bool) $this->evaluate($this->isGrouped);

        $options = $this->getSerializedOptions();

        if ($this->evaluate($this->isBoolean)) {
            $defaults = ['1' => ['color' => 'success', 'icon' => 'check'], '0' => ['color' => 'danger', 'icon' => 'x']];

            $options = array_map(static fn (array $option): array => isset($option['value'], $defaults[$option['value']])
                ? [...$defaults[$option['value']], ...$option]
                : $option, $options);
        }

        return [
            'options' => $options,
            'multiple' => $this->isMultiple() ?: null,
            'inline' => ($grouped || $this->evaluate($this->isInline)) ?: null,
            'grouped' => $grouped ?: null,
            'boolean' => $this->evaluate($this->isBoolean) ?: null,
            'iconsOnly' => $this->evaluate($this->areIconsOnly) ?: null,
            'tooltips' => $this->getTooltips() ?: null,
            'size' => $this->getSize(),
            'fullWidth' => $this->evaluate($this->isFullWidth) ?: null,
            // `columns()`: a grid of buttons (ignored when inline or grouped).
            'gridColumns' => $this->getGridColumns(),
            'gridDirection' => $this->getGridDirection(),
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
