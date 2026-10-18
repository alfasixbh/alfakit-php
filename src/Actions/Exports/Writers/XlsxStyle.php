<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Writers;

/**
 * The look of XLSX cells: font, colours, alignment, wrapping and number format. Method
 * names follow OpenSpout's `Style`, so Filament exporters port over mostly unchanged.
 *
 * ```php
 * public function getXlsxCellStyle(): ?XlsxStyle
 * {
 *     return (new XlsxStyle)->setFontSize(12)->setFontName('Consolas');
 * }
 * ```
 */
final class XlsxStyle
{
    public ?bool $bold = null;

    public ?bool $italic = null;

    public ?bool $underline = null;

    public ?bool $strikethrough = null;

    public ?float $fontSize = null;

    public ?string $fontName = null;

    /** `RRGGBB` */
    public ?string $fontColor = null;

    /** `RRGGBB` */
    public ?string $backgroundColor = null;

    /** `left`, `center`, `right` */
    public ?string $alignment = null;

    public ?bool $wrapText = null;

    /** An Excel number format code, e.g. `#,##0.00` or `yyyy-mm-dd`. */
    public ?string $format = null;

    public function setFontBold(bool $bold = true): self
    {
        $this->bold = $bold;

        return $this;
    }

    public function setFontItalic(bool $italic = true): self
    {
        $this->italic = $italic;

        return $this;
    }

    public function setFontUnderline(bool $underline = true): self
    {
        $this->underline = $underline;

        return $this;
    }

    public function setFontStrikethrough(bool $strikethrough = true): self
    {
        $this->strikethrough = $strikethrough;

        return $this;
    }

    public function setFontSize(float $size): self
    {
        $this->fontSize = $size;

        return $this;
    }

    public function setFontName(string $name): self
    {
        $this->fontName = $name;

        return $this;
    }

    /**
     * @param  string  $color  `#RRGGBB` or `RRGGBB`
     */
    public function setFontColor(string $color): self
    {
        $this->fontColor = self::color($color);

        return $this;
    }

    /**
     * @param  string  $color  `#RRGGBB` or `RRGGBB`
     */
    public function setBackgroundColor(string $color): self
    {
        $this->backgroundColor = self::color($color);

        return $this;
    }

    /**
     * @param  string  $alignment  `left`, `center` or `right`
     */
    public function setCellAlignment(string $alignment): self
    {
        $this->alignment = in_array($alignment, ['left', 'center', 'right'], true) ? $alignment : null;

        return $this;
    }

    public function setShouldWrapText(bool $wrap = true): self
    {
        $this->wrapText = $wrap;

        return $this;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * This style with `$other`'s properties on top (properties `$other` leaves unset keep
     * this style's values).
     */
    public function mergeWith(?self $other): self
    {
        $merged = clone $this;

        if ($other === null) {
            return $merged;
        }

        foreach (get_object_vars($other) as $property => $value) {
            if ($value !== null) {
                $merged->{$property} = $value;
            }
        }

        return $merged;
    }

    public function isEmpty(): bool
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null) === [];
    }

    /**
     * A key identifying the style, to share one style record between equal cells.
     */
    public function getKey(): string
    {
        return md5(serialize(get_object_vars($this)));
    }

    private static function color(string $color): ?string
    {
        $color = strtoupper(ltrim(trim($color), '#'));

        return preg_match('/^[0-9A-F]{6}$/', $color) === 1 ? $color : null;
    }
}
