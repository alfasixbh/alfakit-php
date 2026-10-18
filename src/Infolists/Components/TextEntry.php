<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Concerns\CanFormatState;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesPerRelatedRecord;
use AlfaSix\Alfakit\Support\Concerns\HasDateTooltipsAndIsoFormats;
use AlfaSix\Alfakit\Support\Concerns\HasEnumState;
use AlfaSix\Alfakit\Support\Concerns\HasTextStyles;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Support\Url;
use AlfaSix\Alfakit\Tables\Columns\Concerns\HasRelationshipAggregates;
use Closure;
use Illuminate\Database\Eloquent\Model;

class TextEntry extends Entry
{
    use CanFormatState, HasDateTooltipsAndIsoFormats {
        CanFormatState::placeholder as formatPlaceholder;
        HasDateTooltipsAndIsoFormats::formatDate insteadof CanFormatState;
        CanFormatState::formatDate as formatDateWithPhpFormat;
    }
    use EvaluatesPerRelatedRecord;
    use HasColor;
    use HasEnumState;
    use HasIcon;
    use HasRelationshipAggregates;
    use HasTextStyles;

    protected string|Closure|null $iconColor = null;

    protected bool|Closure $isProse = false;

    public function iconColor(string|Closure|null $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    /**
     * Apply typographic defaults to rendered HTML/markdown.
     */
    public function prose(bool|Closure $condition = true): static
    {
        $this->isProse = $condition;

        return $this;
    }

    /**
     * With `counts()`, `sum()`, `avg()`, `min()`, `max()` or `exists()` the aggregate is
     * loaded onto the record (`loadCount()` and friends) the first time it is read; name the
     * entry after the attribute Eloquent produces (`comments_count`). Constraint closures
     * receive the relationship query, so tenant and other global scopes apply.
     */
    public function getState(): mixed
    {
        $this->loadRelationshipAggregate();

        return parent::getState();
    }

    protected function loadRelationshipAggregate(): void
    {
        $record = $this->getRecord();

        if ($this->relationshipAggregate === null || ! $record instanceof Model || array_key_exists($this->getName(), $record->getAttributes())) {
            return;
        }

        ['function' => $function, 'relationships' => $relationships, 'column' => $column] = $this->relationshipAggregate;

        match ($function) {
            'count' => $record->loadCount($relationships),
            'exists' => $record->loadExists($relationships),
            default => $record->loadAggregate($relationships, $column ?? '*', $function),
        };
    }

    /**
     * A relationship list (`tags.name`) with a `url()` closure links each item instead.
     */
    public function getUrl(): ?string
    {
        if ($this->evaluatePerRelatedRecord($this->url) !== null) {
            return null;
        }

        return parent::getUrl();
    }

    protected function getFormattedState(): mixed
    {
        return Serializable::value($this->formatStateForExpandableDisplay($this->getState()));
    }

    /**
     * Colour and icon come from `color()` / `icon()`, else from an enum state implementing
     * `HasColor` / `HasIcon`. A list whose items differ also sends `itemColors` / `itemIcons`.
     */
    protected function getSchemaProps(): array
    {
        $state = $this->getState();
        $itemColors = $itemIcons = $itemUrls = null;

        // `tags.name`: closures run per related record (`$relatedRecord`, `$state` = the item).
        if (($perItem = $this->evaluatePerRelatedRecord($this->color)) !== null) {
            $color = self::colorValue($perItem[0]);
            $itemColors = $perItem[1] === null ? null : array_map(self::colorValue(...), $perItem[1]);
        } else {
            $color = $this->getColor();
        }

        if (($perItem = $this->evaluatePerRelatedRecord($this->icon)) !== null) {
            $icon = Icons::resolve($perItem[0]);
            $itemIcons = $perItem[1] === null ? null : array_map(static fn (mixed $icon): ?string => Icons::resolve($icon), $perItem[1]);
        } else {
            $icon = $this->getIcon();
        }

        if (($perItem = $this->evaluatePerRelatedRecord($this->url)) !== null) {
            $itemUrls = array_map(
                static fn (mixed $url): ?string => is_string($url) ? Url::sanitize($url) : null,
                $perItem[1] ?? array_fill(0, count($this->getRelatedRecordItems() ?? []), $perItem[0]),
            );
        }

        if ($color === null && $itemColors === null) {
            [$color, $itemColors] = $this->getEnumColor($state);
        }

        if ($icon === null && $itemIcons === null) {
            [$icon, $itemIcons] = $this->getEnumIcon($state);
        }

        return [
            ...$this->getFormattingSchemaProps(),
            ...$this->getTextStyleSchemaProps(),
            ...$this->getListLimitSchemaProps(),
            'color' => $color,
            'icon' => $icon,
            'iconPosition' => ($icon || $itemIcons) ? $this->getIconPosition()->value : null,
            'iconColor' => $this->evaluate($this->iconColor),
            'prose' => $this->evaluate($this->isProse) ?: null,
            'itemColors' => $itemColors,
            'itemIcons' => $itemIcons,
            'itemUrls' => $itemUrls,
        ];
    }

    /**
     * `dateTooltip()` & co. fill the tooltip when no `tooltip()` is set.
     */
    public function toSchema(): array
    {
        $schema = parent::toSchema();

        if (! isset($schema['tooltip']) && ($tooltip = $this->getDateTooltip()) !== null) {
            $schema['tooltip'] = $tooltip;
        }

        return $schema;
    }

    private static function colorValue(mixed $color): ?string
    {
        if ($color instanceof \AlfaSix\Alfakit\Support\Enums\Color) {
            return $color->value;
        }

        return is_string($color) ? $color : null;
    }
}
