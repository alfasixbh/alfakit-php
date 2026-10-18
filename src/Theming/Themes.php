<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * Registry of panel themes. The six built-ins ship with matching CSS in every renderer.
 */
final class Themes
{
    public const DEFAULT = 'neutral';

    /** @var array<string, Theme>|null */
    private static ?array $themes = null;

    public static function register(Theme $theme): void
    {
        self::all();
        self::$themes[$theme->getName()] = $theme;
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    public static function get(string $name): Theme
    {
        return self::all()[$name] ?? throw new InvalidArgumentException(
            "Alfakit theme [{$name}] is not registered. Available: ".implode(', ', array_keys(self::all())).'.',
        );
    }

    /**
     * @return array<string, Theme>
     */
    public static function all(): array
    {
        return self::$themes ??= self::builtIn();
    }

    /**
     * Resolve a theme with everything it inherits: `[base, …, theme]`.
     *
     * @return array<int, Theme>
     */
    public static function lineage(string $name): array
    {
        $chain = [];
        $seen = [];

        while ($name !== null && ! isset($seen[$name])) {
            $seen[$name] = true;
            $theme = self::get($name);
            array_unshift($chain, $theme);
            $name = $theme->getExtends();
        }

        return $chain;
    }

    /**
     * A built-in theme's label or description in the current language (`alfakit::panel.themes.*`),
     * English when the key is missing (e.g. an outdated published language file).
     */
    private static function translate(string $key, string $english): string
    {
        $key = 'alfakit::panel.themes.'.$key;

        return Lang::has($key) ? (string) __($key) : $english;
    }

    /** @internal */
    public static function flush(): void
    {
        self::$themes = null;
    }

    /**
     * @return array<string, Theme>
     */
    private static function builtIn(): array
    {
        $themes = [
            Theme::make('neutral')
                ->label(static fn (): string => self::translate('neutral.label', 'Neutral'))
                ->description(static fn (): string => self::translate('neutral.description', 'The classic: inset content beside a sidebar, plain headers and bordered cards.'))
                ->layout(Layout::Sidebar)->sidebar(SidebarVariant::Inset)
                ->nav(NavStyle::Soft)->header(HeaderStyle::Plain)->surfaces(SurfaceStyle::Bordered)
                ->inputs(InputStyle::Outline)->buttons(ButtonShape::Rounded)->tables(TableStyle::Lined)
                ->density(Density::Comfortable)->radius('0.625rem')
                ->font('Instrument Sans'),

            Theme::make('alfakit')
                ->label(static fn (): string => self::translate('alfakit.label', 'AlfaKit'))
                ->description(static fn (): string => self::translate('alfakit.description', 'The signature design in the AlfaKit brand: Alfa Navy and Fox Orange on warm cream, Montserrat titles and Inter text, a floating sidebar with pill navigation, a hero banner on every page, elevated cards, filled inputs and pill buttons.'))
                ->layout(Layout::Sidebar)->sidebar(SidebarVariant::Floating)
                ->nav(NavStyle::Pill)->header(HeaderStyle::Banner)->surfaces(SurfaceStyle::Elevated)
                ->inputs(InputStyle::Filled)->buttons(ButtonShape::Pill)->tables(TableStyle::Minimal)
                ->density(Density::Comfortable)->radius('0.875rem')
                ->font('Inter')->headingFont('Montserrat'),

            Theme::make('minimal')
                ->label(static fn (): string => self::translate('minimal.label', 'Minimal'))
                ->description(static fn (): string => self::translate('minimal.description', 'No sidebar: navigation runs across the top as underlined tabs. Flat hairline surfaces, underlined inputs, square buttons, compact.'))
                ->layout(Layout::Topbar)->sidebar(SidebarVariant::Flush)
                ->nav(NavStyle::Underline)->header(HeaderStyle::Compact)->surfaces(SurfaceStyle::Flat)
                ->inputs(InputStyle::Underline)->buttons(ButtonShape::Square)->tables(TableStyle::Minimal)
                ->density(Density::Compact)->radius('0.25rem')
                ->font('system-ui'),

            Theme::make('soft')
                ->label(static fn (): string => self::translate('soft.label', 'Soft'))
                ->description(static fn (): string => self::translate('soft.description', 'A slim icon rail, large serif titles, tinted borderless cards, filled rounded inputs and striped tables.'))
                ->layout(Layout::Rail)->sidebar(SidebarVariant::Inset)
                ->nav(NavStyle::Pill)->header(HeaderStyle::Plain)->surfaces(SurfaceStyle::Flat)
                ->inputs(InputStyle::Filled)->buttons(ButtonShape::Pill)->tables(TableStyle::Striped)
                ->density(Density::Comfortable)->radius('1rem')
                ->font('Nunito')->headingFont('Fraunces'),

            Theme::make('corporate')
                ->label(static fn (): string => self::translate('corporate.label', 'Corporate'))
                ->description(static fn (): string => self::translate('corporate.description', 'Navy sidebar with accent-bar navigation, a full-width title band, square bordered surfaces and dense striped tables.'))
                ->layout(Layout::Sidebar)->sidebar(SidebarVariant::Flush)
                ->nav(NavStyle::Bar)->header(HeaderStyle::Band)->surfaces(SurfaceStyle::Bordered)
                ->inputs(InputStyle::Outline)->buttons(ButtonShape::Square)->tables(TableStyle::Striped)
                ->density(Density::Compact)->radius('0.375rem')
                ->font('IBM Plex Sans'),

            Theme::make('filament')
                ->label(static fn (): string => self::translate('filament.label', 'Filament style'))
                ->description(static fn (): string => self::translate('filament.description', 'Grey canvas, transparent sidebar, white ringed cards, amber accents and Inter, like FilamentPHP.'))
                ->layout(Layout::Sidebar)->sidebar(SidebarVariant::Flush)
                ->nav(NavStyle::Soft)->header(HeaderStyle::Plain)->surfaces(SurfaceStyle::Ring)
                ->inputs(InputStyle::Outline)->buttons(ButtonShape::Rounded)->tables(TableStyle::Lined)
                ->density(Density::Comfortable)->radius('0.75rem')
                ->font('Inter'),
        ];

        $registry = [];

        foreach ($themes as $theme) {
            $registry[$theme->getName()] = $theme;
        }

        return $registry;
    }
}
