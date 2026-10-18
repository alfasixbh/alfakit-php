<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

use BackedEnum;
use Closure;
use InvalidArgumentException;

/**
 * A panel design. Beyond colours (in CSS), a theme decides the page structure (sidebar,
 * top navigation or an icon rail), how navigation, page headers, surfaces, inputs,
 * buttons and tables look, the density, corner radius and fonts.
 *
 * Built-in themes: `neutral`, `alfakit`, `minimal`, `soft`, `corporate` and `filament`.
 * Register your own, optionally on top of a built-in:
 *
 * ```php
 * Themes::register(
 *     Theme::make('acme')->extends('alfakit')->font('Sora')->sidebar(SidebarVariant::Flush)
 * );
 *
 * // resources/css/alfakit.css
 * [data-alfakit-theme~='acme'] { --primary: var(--alfakit-primary, oklch(0.6 0.2 30)); }
 * ```
 *
 * The `<html>` element carries `data-alfakit-theme="alfakit acme"` (the base first), so a
 * custom theme only needs CSS for what it changes.
 */
final class Theme
{
    /** Design dimensions and the enum each one takes. */
    public const DESIGN = [
        'layout' => Layout::class,
        'nav' => NavStyle::class,
        'header' => HeaderStyle::class,
        'surfaces' => SurfaceStyle::class,
        'inputs' => InputStyle::class,
        'buttons' => ButtonShape::class,
        'tables' => TableStyle::class,
        'mobile' => MobileNavigation::class,
    ];

    /** @var array<string, BackedEnum> */
    private array $design = [];

    private ?string $headingFont = null;

    private ?FontProvider $headingFontProvider = null;

    private string|Closure|null $label = null;

    private string|Closure|null $description = null;

    private ?string $extends = null;

    private ?SidebarVariant $sidebar = null;

    private ?Density $density = null;

    private ?string $radius = null;

    private ?string $font = null;

    private ?FontProvider $fontProvider = null;

    /** @var array<string, string|array<int, string>> */
    private array $colors = [];

    public function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** A closure is resolved when the label is read, e.g. to translate it per request. */
    public function label(string|Closure|null $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function description(string|Closure|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Start from another theme's tokens and settings. */
    public function extends(?string $theme): self
    {
        $this->extends = $theme;

        return $this;
    }

    public function sidebar(SidebarVariant|string|null $variant): self
    {
        $this->sidebar = is_string($variant) ? SidebarVariant::from($variant) : $variant;

        return $this;
    }

    public function density(Density|string|null $density): self
    {
        $this->density = is_string($density) ? Density::from($density) : $density;

        return $this;
    }

    /** Base corner radius, any CSS length: `0.5rem`, `12px`, `0`. */
    public function radius(?string $radius): self
    {
        $this->radius = $radius;

        return $this;
    }

    /**
     * Font family, loaded automatically from Bunny Fonts unless another provider is given.
     * Use `system-ui` (or any CSS generic family) to load nothing.
     */
    public function font(?string $family, FontProvider|string|null $provider = null): self
    {
        $this->font = $family;
        $this->fontProvider = is_string($provider) ? FontProvider::from($provider) : $provider;

        return $this;
    }

    /**
     * Semantic colours as defaults for this theme (`primary`, `danger`, …). Colours set on
     * the panel with `->colors()` still win. Values are CSS colours or palettes
     * (`Color::Indigo`, `Color::hex('#…')`).
     *
     * @param  array<string, string|array<int, string>>  $colors
     */
    public function colors(array $colors): self
    {
        $this->colors = $colors;

        return $this;
    }

    public function layout(Layout|string $layout): self
    {
        return $this->setDesign('layout', $layout);
    }

    public function nav(NavStyle|string $style): self
    {
        return $this->setDesign('nav', $style);
    }

    public function header(HeaderStyle|string $style): self
    {
        return $this->setDesign('header', $style);
    }

    public function surfaces(SurfaceStyle|string $style): self
    {
        return $this->setDesign('surfaces', $style);
    }

    public function inputs(InputStyle|string $style): self
    {
        return $this->setDesign('inputs', $style);
    }

    public function buttons(ButtonShape|string $shape): self
    {
        return $this->setDesign('buttons', $shape);
    }

    public function tables(TableStyle|string $style): self
    {
        return $this->setDesign('tables', $style);
    }

    /** Below the `md` breakpoint: a drawer (default) or the `bottom` mobile preset. */
    public function mobile(MobileNavigation|string $navigation): self
    {
        return $this->setDesign('mobile', $navigation);
    }

    /** A display font for page titles and section headings (body text keeps `font()`). */
    public function headingFont(?string $family, FontProvider|string|null $provider = null): self
    {
        $this->headingFont = $family;
        $this->headingFontProvider = is_string($provider) ? FontProvider::from($provider) : $provider;

        return $this;
    }

    /**
     * @return array<string, BackedEnum>
     */
    public function getDesign(): array
    {
        return $this->design;
    }

    public function getHeadingFont(): ?string
    {
        return $this->headingFont;
    }

    public function getHeadingFontProvider(): ?FontProvider
    {
        return $this->headingFontProvider;
    }

    /**
     * Turn a design value into its enum, validating the dimension.
     */
    public static function designValue(string $dimension, BackedEnum|string $value): BackedEnum
    {
        $enum = self::DESIGN[$dimension] ?? throw new InvalidArgumentException("Unknown design dimension [{$dimension}]. Use: ".implode(', ', array_keys(self::DESIGN)).'.');

        if ($value instanceof BackedEnum) {
            if (! $value instanceof $enum) {
                throw new InvalidArgumentException("[{$dimension}] expects a {$enum} value.");
            }

            return $value;
        }

        return $enum::tryFrom($value) ?? throw new InvalidArgumentException(
            "[{$value}] is not a valid {$dimension}. Use: ".implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases())).'.',
        );
    }

    private function setDesign(string $dimension, BackedEnum|string $value): self
    {
        $this->design[$dimension] = self::designValue($dimension, $value);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        $label = $this->label instanceof Closure ? ($this->label)() : $this->label;

        return is_string($label) && $label !== '' ? $label : ucfirst($this->name);
    }

    public function getDescription(): ?string
    {
        $description = $this->description instanceof Closure ? ($this->description)() : $this->description;

        return is_string($description) ? $description : null;
    }

    public function getExtends(): ?string
    {
        return $this->extends;
    }

    public function getSidebar(): ?SidebarVariant
    {
        return $this->sidebar;
    }

    public function getDensity(): ?Density
    {
        return $this->density;
    }

    public function getRadius(): ?string
    {
        return $this->radius;
    }

    public function getFont(): ?string
    {
        return $this->font;
    }

    public function getFontProvider(): ?FontProvider
    {
        return $this->fontProvider;
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function getColors(): array
    {
        return $this->colors;
    }
}
