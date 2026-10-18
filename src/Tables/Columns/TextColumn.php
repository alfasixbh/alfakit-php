<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Concerns\CanFormatState;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesPerRelatedRecord;
use AlfaSix\Alfakit\Support\Concerns\HasDateTooltipsAndIsoFormats;
use AlfaSix\Alfakit\Support\Concerns\HasEnumState;
use AlfaSix\Alfakit\Support\Concerns\HasTextStyles;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Url;
use AlfaSix\Alfakit\Tables\Columns\Concerns\CanUpdateState;
use BackedEnum;
use Closure;
use DateTimeInterface;

class TextColumn extends Column
{
    use CanFormatState, HasDateTooltipsAndIsoFormats {
        HasDateTooltipsAndIsoFormats::formatDate insteadof CanFormatState;
        CanFormatState::formatDate as formatDateWithPhpFormat;
    }
    use CanUpdateState;
    use EvaluatesPerRelatedRecord;
    use HasColor;
    use HasEnumState;
    use HasIcon;
    use HasTextStyles;

    protected string|Closure|null $description = null;

    protected string|Closure $descriptionPosition = 'below';

    protected string|Closure|null $iconColor = null;

    public function description(string|Closure|null $description, string|Closure $position = 'below'): static
    {
        $this->description = $description;
        $this->descriptionPosition = $position;

        return $this;
    }

    public function iconColor(string|Closure|null $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    protected function getFormattedState(): mixed
    {
        return \AlfaSix\Alfakit\Support\Serializable::value($this->formatStateForExpandableDisplay($this->getState()));
    }

    protected function getSchemaProps(): array
    {
        return [
            ...$this->getFormattingSchemaProps(),
            ...$this->getTextStyleSchemaProps(),
            ...$this->getListLimitSchemaProps(),
            'descriptionPosition' => $this->description !== null ? $this->evaluate($this->descriptionPosition) : null,
        ];
    }

    /**
     * Colour and icon come from `color()` / `icon()`, else from an enum state implementing
     * `HasColor` / `HasIcon`. A list whose items differ also sends `itemColors` / `itemIcons`.
     */
    protected function getCellProps(): array
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
            ...$this->getSpreadsheetCellProps($state),
            // `dateTooltip()` & co.; an explicit `tooltip()` wins.
            ...($this->tooltip === null && ($dateTooltip = $this->getDateTooltip()) !== null ? ['tooltip' => $dateTooltip] : []),
            'color' => $color,
            'icon' => $icon,
            'iconPosition' => ($icon || $itemIcons) ? $this->getIconPosition()->value : null,
            'iconColor' => $this->evaluate($this->iconColor),
            'description' => $this->evaluate($this->description),
            'itemColors' => $itemColors,
            'itemIcons' => $itemIcons,
            'itemUrls' => $itemUrls,
        ];
    }

    /**
     * Text columns are read-only unless `editable()` (in spreadsheet mode). A dotted name
     * (`author.name`) has no attribute to save, so it needs `updateStateUsing()`.
     */
    protected function isEditableByDefault(): bool
    {
        return false;
    }

    public function isEditable(): bool
    {
        $condition = $this->evaluate($this->isEditable);

        return (bool) $condition && (! str_contains($this->getName(), '.') || $this->updateStateUsing !== null);
    }

    /**
     * The raw value a spreadsheet edits (the cell shows the formatted one).
     *
     * @return array<string, mixed>
     */
    protected function getSpreadsheetCellProps(mixed $state): array
    {
        if (! $this->isEditable() || ! $this->getTable()?->isSpreadsheet()) {
            return [];
        }

        $value = match (true) {
            $state instanceof BackedEnum => $state->value,
            $state instanceof DateTimeInterface => $state->format('Y-m-d H:i:s'),
            is_scalar($state) || $state === null => $state,
            default => null,
        };

        return ['editState' => $value, 'disabled' => $this->isDisabled() ?: null];
    }

    /**
     * A `url()` closure on a relationship list links each item (`itemUrls`) instead of
     * the whole cell.
     */
    public function getUrl(): ?string
    {
        if ($this->url instanceof Closure && $this->getRelatedRecordItems() !== null) {
            return null;
        }

        return parent::getUrl();
    }

    protected function getDisplayTimezone(): string
    {
        return $this->rememberForRows('timezone', static fn (): string => alfakit()->getTimezone());
    }

    private static function colorValue(mixed $color): ?string
    {
        if ($color instanceof \AlfaSix\Alfakit\Support\Enums\Color) {
            return $color->value;
        }

        return is_string($color) ? $color : null;
    }
}
