<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesPerRelatedRecord;
use AlfaSix\Alfakit\Support\Concerns\HasEnumState;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;

class IconColumn extends Column
{
    use EvaluatesPerRelatedRecord;
    use HasColor;
    use HasEnumState;
    use HasIcon;

    protected bool|Closure $isBoolean = false;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $trueIcon = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $falseIcon = null;

    protected string|Closure|null $trueColor = null;

    protected string|Closure|null $falseColor = null;

    protected Size|string|Closure|null $size = null;

    protected bool|Closure $isWrapped = false;

    public function boolean(bool|Closure $condition = true): static
    {
        $this->isBoolean = $condition;

        return $this;
    }

    public function trueIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->trueIcon = $icon;

        return $this;
    }

    public function falseIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->falseIcon = $icon;

        return $this;
    }

    public function trueColor(string|Closure|null $color): static
    {
        $this->trueColor = $color;

        return $this;
    }

    public function falseColor(string|Closure|null $color): static
    {
        $this->falseColor = $color;

        return $this;
    }

    public function size(Size|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Let several icons (a list state) wrap onto more lines instead of one row.
     */
    public function wrap(bool|Closure $condition = true): static
    {
        $this->isWrapped = $condition;

        return $this;
    }

    public function isBoolean(): bool
    {
        return (bool) $this->evaluate($this->isBoolean);
    }

    protected function getFormattedState(): mixed
    {
        $state = $this->getState();

        if ($this->isListState($state)) {
            return array_values(array_map(fn (mixed $item): mixed => $this->isBoolean() ? (bool) $item : Serializable::value($item), $this->listItems($state)));
        }

        return $this->isBoolean() ? (bool) $state : $state;
    }

    protected function getSchemaProps(): array
    {
        return [
            'wrap' => $this->evaluate($this->isWrapped) ?: null,
        ];
    }

    /**
     * The icon and colour for one state value (a list state is resolved item by item;
     * `icon()` / `color()` closures receive the item as `$state`, and for a relationship
     * list such as `tags.icon` the item's model as `$relatedRecord`).
     *
     * @return array{icon: string|null, color: string|null}
     */
    public function getIconForState(mixed $state, ?Model $relatedRecord = null): array
    {
        $named = ['state' => $state, ...($relatedRecord !== null ? ['relatedRecord' => $relatedRecord] : [])];
        $typed = $relatedRecord !== null ? [$relatedRecord::class => $relatedRecord] : [];

        if ($this->isBoolean()) {
            $icon = $state
                ? ($this->evaluate($this->trueIcon, $named, $typed) ?? 'circle-check')
                : ($this->evaluate($this->falseIcon, $named, $typed) ?? 'circle-x');
            $color = $state
                ? ($this->evaluate($this->trueColor, $named, $typed) ?? 'success')
                : ($this->evaluate($this->falseColor, $named, $typed) ?? 'danger');
        } else {
            // An enum state implementing `HasIcon` / `HasColor` supplies its own.
            $case = $this->resolveEnumCase($state);
            $icon = $this->evaluate($this->icon, $named, $typed) ?? ($case !== null ? EnumState::icon($case) : (is_string($state) ? $state : null));
            $color = $this->evaluate($this->color, $named, $typed) ?? ($case !== null ? EnumState::color($case) : null);
        }

        if ($color instanceof \AlfaSix\Alfakit\Support\Enums\Color) {
            $color = $color->value;
        }

        return [
            // True/false icons and icons named by the state itself resolve aliases too.
            'icon' => Icons::resolve($icon),
            'color' => is_string($color) ? $color : null,
        ];
    }

    protected function getCellProps(): array
    {
        $state = $this->getState();
        $size = $this->evaluate($this->size);
        $size = $size instanceof Size ? $size->value : $size;

        // A list state (JSON array, collection, `tags.icon`) shows one icon per item.
        if ($this->isListState($state)) {
            $icons = [];
            $items = $this->listItems($state);
            $related = $this->getRelatedRecordItems();
            $relatedRecords = is_array($related) && count($related) === count($items) ? array_column($related, 1) : [];

            foreach ($items as $index => $item) {
                $resolved = $this->getIconForState($item, $relatedRecords[$index] ?? null);

                if ($resolved['icon'] !== null) {
                    $icons[] = $resolved;
                }
            }

            return ['icons' => $icons, 'size' => $size];
        }

        return [...$this->getIconForState($state), 'size' => $size];
    }

    private function isListState(mixed $state): bool
    {
        return is_array($state) || $state instanceof \Illuminate\Support\Collection;
    }

    /**
     * @return list<mixed>
     */
    private function listItems(mixed $state): array
    {
        $items = $state instanceof \Illuminate\Support\Collection ? $state->all() : (array) $state;

        return array_values(array_filter($items, static fn (mixed $item): bool => $item !== null && $item !== ''));
    }
}
