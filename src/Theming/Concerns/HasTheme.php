<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming\Concerns;

use AlfaSix\Alfakit\Support\ColorContrast;
use AlfaSix\Alfakit\Support\Colors\Color;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Theming\ButtonShape;
use AlfaSix\Alfakit\Theming\Density;
use AlfaSix\Alfakit\Theming\FontProvider;
use AlfaSix\Alfakit\Theming\HeaderStyle;
use AlfaSix\Alfakit\Theming\InputStyle;
use AlfaSix\Alfakit\Theming\Layout;
use AlfaSix\Alfakit\Theming\MobileNavigation;
use AlfaSix\Alfakit\Theming\NavStyle;
use AlfaSix\Alfakit\Theming\SidebarVariant;
use AlfaSix\Alfakit\Theming\SurfaceStyle;
use AlfaSix\Alfakit\Theming\TableStyle;
use AlfaSix\Alfakit\Theming\Theme;
use AlfaSix\Alfakit\Theming\Themes;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Look and feel of a panel: theme, sidebar style, density, radius, font and stylesheet.
 *
 * ```php
 * $panel
 *     ->theme('alfakit')                  // neutral, alfakit, minimal, soft, corporate, filament
 *     ->sidebarVariant('flush')           // override what the theme chose
 *     ->density('compact')
 *     ->font('Sora')                      // loaded from Bunny Fonts
 *     ->viteTheme('resources/css/admin.css'); // this panel's own stylesheet
 * ```
 */
trait HasTheme
{
    /** Colour names every renderer maps to its own tokens. */
    public const BUILT_IN_COLORS = ['primary', 'secondary', 'success', 'warning', 'danger', 'info', 'gray'];

    protected string|Theme|Closure|null $theme = null;

    protected SidebarVariant|string|Closure|null $sidebarVariant = null;

    protected Density|string|Closure|null $density = null;

    protected string|Closure|null $radius = null;

    protected string|Closure|null $font = null;

    protected FontProvider|string|null $fontProvider = null;

    protected string|Closure|null $fontStylesheetUrl = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $fontPreload = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $headingFontPreload = null;

    protected string|Closure|null $headingFontStylesheetUrl = null;

    /** @var array<int, string>|string|Closure|null */
    protected array|string|Closure|null $viteTheme = null;

    /** @var array<string, BackedEnum|string|Closure> */
    protected array $designOverrides = [];

    /**
     * Theme values resolved during the current request. Closures (per user, tenant or page)
     * run once per request: the root view, the shared `alfakit` prop and the Vite entries
     * all read the same result. Flushed by `SetUpPanel` and whenever a setter runs.
     *
     * @var array<string, mixed>
     */
    protected array $resolvedThemeValues = [];

    protected string|Closure|null $headingFont = null;

    protected FontProvider|string|null $headingFontProvider = null;

    /**
     * A theme name, or a `Theme` (registered on the fly). Closures may pick per user or
     * tenant.
     */
    public function theme(string|Theme|Closure|null $theme): static
    {
        $this->theme = $theme;

        return $this->flushThemeCache();
    }

    /**
     * Override parts of the theme's design. Pass only what you want to change:
     * `->design(layout: 'topbar', nav: 'underline', inputs: 'filled', mobile: 'bottom')`.
     *
     * Every dimension accepts a closure, evaluated once per request with `$user`, `$panel`,
     * `$page` (the current page class), `$route`, `$request` and `$tenant` injectable, so one
     * panel can look different per role, page or tenant:
     * `->design(layout: fn ($user) => $user?->isAdmin() ? 'sidebar' : 'topbar')`.
     */
    public function design(
        Layout|string|Closure|null $layout = null,
        NavStyle|string|Closure|null $nav = null,
        HeaderStyle|string|Closure|null $header = null,
        SurfaceStyle|string|Closure|null $surfaces = null,
        InputStyle|string|Closure|null $inputs = null,
        ButtonShape|string|Closure|null $buttons = null,
        TableStyle|string|Closure|null $tables = null,
        MobileNavigation|string|Closure|null $mobile = null,
    ): static {
        foreach (compact('layout', 'nav', 'header', 'surfaces', 'inputs', 'buttons', 'tables', 'mobile') as $dimension => $value) {
            if ($value !== null) {
                // Static values are validated now; closures when they are evaluated.
                $this->designOverrides[$dimension] = $value instanceof Closure ? $value : Theme::designValue($dimension, $value);
            }
        }

        return $this->flushThemeCache();
    }

    /**
     * The panel layout: `sidebar`, `topbar` or `rail`. Shorthand for `design(layout: …)`.
     */
    public function layout(Layout|string|Closure|null $layout): static
    {
        return $this->design(layout: $layout);
    }

    /**
     * Forget theme values resolved for the previous request.
     */
    public function flushThemeCache(): static
    {
        $this->resolvedThemeValues = [];

        return $this;
    }

    /**
     * Resolve a theme value once per request.
     */
    protected function rememberThemeValue(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->resolvedThemeValues)) {
            $this->resolvedThemeValues[$key] = $resolve();
        }

        return $this->resolvedThemeValues[$key];
    }

    /**
     * The mobile preset. Below the `md` breakpoint the panel gets a bottom navigation bar
     * with the top-level items (the rest in a sheet), tables rendered as stacked cards,
     * slide-over modals, 44px touch targets on primary controls and sticky form actions.
     * Shorthand for `->design(mobile: MobileNavigation::Bottom)`.
     */
    public function mobile(bool $condition = true): static
    {
        $this->designOverrides['mobile'] = $condition ? MobileNavigation::Bottom : MobileNavigation::Drawer;

        return $this->flushThemeCache();
    }

    public function getMobileNavigation(): MobileNavigation
    {
        return MobileNavigation::from($this->getDesign()['mobile']);
    }

    /**
     * The resolved design: theme lineage, then `design()` overrides. `topNavigation()`
     * switches the layout to `topbar`.
     *
     * @return array<string, string>
     */
    public function getDesign(): array
    {
        /** @var array<string, string> */
        return $this->rememberThemeValue('design', function (): array {
            $design = [
                'layout' => Layout::Sidebar,
                'nav' => NavStyle::Soft,
                'header' => HeaderStyle::Plain,
                'surfaces' => SurfaceStyle::Bordered,
                'inputs' => InputStyle::Outline,
                'buttons' => ButtonShape::Rounded,
                'tables' => TableStyle::Lined,
                'mobile' => MobileNavigation::Drawer,
            ];

            foreach (Themes::lineage($this->getTheme()->getName()) as $theme) {
                $design = [...$design, ...$theme->getDesign()];
            }

            foreach ($this->designOverrides as $dimension => $value) {
                $resolved = $this->evaluate($value);

                if ($resolved !== null) {
                    $design[$dimension] = Theme::designValue($dimension, $resolved);
                }
            }

            if ($this->hasTopNavigation) {
                $design['layout'] = Layout::Topbar;
            }

            return array_map(static fn (BackedEnum $value): string => (string) $value->value, $design);
        });
    }

    /** Display font for page titles and section headings. */
    /**
     * @param  array<string>|Closure|null  $preload  Font file URLs to preload (see `font()`).
     */
    public function headingFont(string|Closure|null $family, FontProvider|string|null $provider = null, string|Closure|null $url = null, array|Closure|null $preload = null): static
    {
        $this->headingFont = $family;
        $this->headingFontProvider = $provider;
        $this->headingFontStylesheetUrl = $url;
        $this->headingFontPreload = $preload;

        return $this->flushThemeCache();
    }

    public function getHeadingFont(): ?string
    {
        /** @var string|null */
        return $this->rememberThemeValue('headingFont', fn (): ?string => $this->evaluate($this->headingFont) ?? $this->fromLineage(static fn (Theme $theme) => $theme->getHeadingFont()));
    }

    public function sidebarVariant(SidebarVariant|string|Closure|null $variant): static
    {
        $this->sidebarVariant = $variant;

        return $this->flushThemeCache();
    }

    public function density(Density|string|Closure|null $density): static
    {
        $this->density = $density;

        return $this->flushThemeCache();
    }

    /** Base corner radius, any CSS length. */
    public function radius(string|Closure|null $radius): static
    {
        $this->radius = $radius;

        return $this->flushThemeCache();
    }

    /**
     * Font family for the whole panel. Loaded from Bunny Fonts by default; pass
     * `FontProvider::Google`, or `FontProvider::None` for self-hosted and system fonts.
     * `url:` loads the font from your own stylesheet instead (Filament's
     * `LocalFontProvider`): `->font('Inter', url: asset('fonts/inter.css'))`.
     *
     * `preload:` lists font files (usually `.woff2`) the browser fetches right away with
     * `<link rel="preload" as="font">`, so text doesn't flash in a fallback font:
     * `->font('Inter', url: asset('fonts/inter.css'), preload: [asset('fonts/inter-latin.woff2')])`.
     *
     * @param  array<string>|Closure|null  $preload
     */
    public function font(string|Closure|null $family, FontProvider|string|null $provider = null, string|Closure|null $url = null, array|Closure|null $preload = null): static
    {
        $this->font = $family;
        $this->fontProvider = $provider;
        $this->fontStylesheetUrl = $url;
        $this->fontPreload = $preload;

        return $this->flushThemeCache();
    }

    /**
     * Use your own Vite stylesheet (and optionally script) for this panel instead of
     * `config('alfakit.inertia.vite.css')`, e.g. to give a customer panel its own design.
     *
     * A closure may pick the stylesheet per user, tenant or page.
     *
     * @param  array<int, string>|string|Closure|null  $entries
     */
    public function viteTheme(array|string|Closure|null $entries): static
    {
        $this->viteTheme = $entries;

        return $this->flushThemeCache();
    }

    public function getTheme(): Theme
    {
        /** @var Theme */
        return $this->rememberThemeValue('theme', function (): Theme {
            $theme = $this->evaluate($this->theme) ?? config('alfakit.theme', Themes::DEFAULT);

            if ($theme instanceof Theme) {
                if (! Themes::has($theme->getName())) {
                    Themes::register($theme);
                }

                return $theme;
            }

            return Themes::get((string) $theme);
        });
    }

    /**
     * Names for `data-alfakit-theme`, base theme first: `"alfakit acme"`.
     */
    public function getThemeAttribute(): string
    {
        return implode(' ', array_map(static fn (Theme $theme): string => $theme->getName(), Themes::lineage($this->getTheme()->getName())));
    }

    public function getSidebarVariant(): SidebarVariant
    {
        /** @var SidebarVariant */
        return $this->rememberThemeValue('sidebar', function (): SidebarVariant {
            $variant = $this->evaluate($this->sidebarVariant) ?? $this->fromLineage(static fn (Theme $theme) => $theme->getSidebar());

            return is_string($variant) ? SidebarVariant::from($variant) : ($variant ?? SidebarVariant::Inset);
        });
    }

    public function getDensity(): Density
    {
        /** @var Density */
        return $this->rememberThemeValue('density', function (): Density {
            $density = $this->evaluate($this->density) ?? $this->fromLineage(static fn (Theme $theme) => $theme->getDensity());

            return is_string($density) ? Density::from($density) : ($density ?? Density::Comfortable);
        });
    }

    public function getRadius(): ?string
    {
        /** @var string|null */
        return $this->rememberThemeValue('radius', fn (): ?string => $this->evaluate($this->radius) ?? $this->fromLineage(static fn (Theme $theme) => $theme->getRadius()));
    }

    public function getFont(): ?string
    {
        /** @var string|null */
        return $this->rememberThemeValue('font', fn (): ?string => $this->evaluate($this->font) ?? $this->fromLineage(static fn (Theme $theme) => $theme->getFont()));
    }

    public function getFontProvider(): FontProvider
    {
        if ($this->evaluate($this->font) !== null) {
            $provider = $this->fontProvider ?? ($this->evaluate($this->fontStylesheetUrl) !== null ? FontProvider::Local : null);
        } else {
            $provider = $this->fromLineage(static fn (Theme $theme) => $theme->getFont() !== null ? $theme->getFontProvider() : null);
        }

        $provider = is_string($provider) ? FontProvider::from($provider) : $provider;

        return $this->isGenericFontFamily($this->getFont()) ? FontProvider::None : ($provider ?? FontProvider::Bunny);
    }

    /** The CSS value for `--alfakit-font` (quoted family, or a generic keyword). */
    public function getFontCssValue(): ?string
    {
        return $this->fontCss($this->getFont());
    }

    public function getHeadingFontCssValue(): ?string
    {
        return $this->fontCss($this->getHeadingFont());
    }

    /** Stylesheet URL that loads the panel font, or null when nothing should be loaded. */
    public function getFontUrl(): ?string
    {
        return $this->fontUrl($this->getFont(), $this->getFontProvider(), $this->evaluate($this->fontStylesheetUrl));
    }

    /**
     * Every font stylesheet to load (body and heading fonts).
     *
     * @return array<int, string>
     */
    public function getFontUrls(): array
    {
        $heading = $this->getHeadingFont();

        $headingUrl = $this->evaluate($this->headingFontStylesheetUrl);

        if ($this->evaluate($this->headingFont) !== null) {
            $provider = $this->headingFontProvider ?? ($headingUrl !== null ? FontProvider::Local : null);
        } else {
            $provider = $this->fromLineage(static fn (Theme $theme) => $theme->getHeadingFont() !== null ? $theme->getHeadingFontProvider() : null);
        }

        $provider = is_string($provider) ? FontProvider::from($provider) : $provider;

        return array_values(array_unique(array_filter([
            $this->getFontUrl(),
            $this->isGenericFontFamily($heading) ? null : $this->fontUrl($heading, $provider ?? FontProvider::Bunny, $headingUrl),
        ])));
    }

    /**
     * The font files to preload (`font(preload: [...])`, `headingFont(preload: [...])`),
     * without unsafe URLs.
     *
     * @return array<int, string>
     */
    public function getFontPreload(): array
    {
        $urls = [];

        foreach ([$this->fontPreload, $this->headingFontPreload] as $preload) {
            foreach ((array) ($this->evaluate($preload) ?? []) as $url) {
                $url = \AlfaSix\Alfakit\Support\Url::sanitize($url, ['http', 'https']);

                if ($url !== null) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * `<link rel="preload" as="font">` tags for {@see getFontPreload()}.
     */
    public function getFontPreloadHtml(): Htmlable
    {
        $tags = array_map(static function (string $url): string {
            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $type = match ($extension) {
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
                'ttf' => 'font/ttf',
                'otf' => 'font/otf',
                default => null,
            };

            return '<link rel="preload" href="'.e($url).'" as="font"'.($type !== null ? ' type="'.$type.'"' : '').' crossorigin>';
        }, $this->getFontPreload());

        return new HtmlString(implode("\n", $tags));
    }

    private function fontCss(?string $font): ?string
    {
        if ($font === null) {
            return null;
        }

        return $this->isGenericFontFamily($font) ? $font : "'".str_replace("'", '', $font)."'";
    }

    private function fontUrl(?string $font, FontProvider $provider, ?string $url = null): ?string
    {
        if ($font === null || $this->isGenericFontFamily($font)) {
            return null;
        }

        if ($provider === FontProvider::Local) {
            // Only URLs and paths: the value ends up in `<link href>`.
            return $url !== null && preg_match('#^(?:https?://|//|/)#i', $url) === 1 ? $url : null;
        }

        return match ($provider) {
            FontProvider::Bunny => 'https://fonts.bunny.net/css?family='.Str::slug($font).':400,500,600,700&display=swap',
            FontProvider::Google => 'https://fonts.googleapis.com/css2?family='.str_replace(' ', '+', $font).':wght@400;500;600;700&display=swap',
            FontProvider::None => null,
        };
    }

    /**
     * Theme colours with the panel's own `colors()` on top, one CSS value per name. A
     * palette (`Color::Indigo`) contributes its base shade (`Color::getBaseShade()`).
     *
     * @return array<string, string>
     */
    public function getThemeColors(): array
    {
        $colors = [];

        foreach ($this->getRawThemeColors() as $name => $value) {
            if (Color::isPalette($value)) {
                /** @var array<int, string> $value */
                $colors[$name] = $value[Color::getBaseShade($value)];
            } elseif (is_string($value)) {
                $colors[$name] = $value;
            }
        }

        return $colors;
    }

    /**
     * The colours set as palettes (`Color::Indigo`, `Color::hex('#…')`), keyed by name.
     *
     * @return array<string, array<int, string>>
     */
    public function getThemeColorPalettes(): array
    {
        $palettes = [];

        foreach ($this->getRawThemeColors() as $name => $value) {
            if (Color::isPalette($value)) {
                /** @var array<int, string> $value */
                $palettes[$name] = $value;
            }
        }

        return $palettes;
    }

    /**
     * Every colour custom property for `<html>`, without the `--alfakit-` prefix: the
     * colour (`primary`), its readable text colour (`primary-foreground`) and, for
     * palettes, every shade (`primary-50` … `primary-950`).
     *
     * @return array<string, string>
     */
    public function getThemeColorVariables(): array
    {
        $variables = [];
        $palettes = $this->getThemeColorPalettes();

        foreach ($this->getThemeColors() as $name => $value) {
            // Names become CSS custom properties: anything else would break the style.
            if (! self::isValidColorName((string) $name)) {
                continue;
            }

            $variables[$name] = $value;

            // Readable text on the colour (dark on pale brand colours, light on deep ones),
            // for the built-in names and any custom one.
            if (! in_array($name, ['gray', 'secondary'], true) && ($foreground = ColorContrast::foreground($value)) !== null) {
                $variables[$name.'-foreground'] = $foreground;
            }

            foreach ($palettes[$name] ?? [] as $shade => $shadeValue) {
                $variables[$name.'-'.$shade] = $shadeValue;
            }
        }

        return array_map(static fn (string $value): string => str_replace([';', '"', '<', '>', '{', '}'], '', $value), $variables);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRawThemeColors(): array
    {
        $colors = [];

        foreach (Themes::lineage($this->getTheme()->getName()) as $theme) {
            $colors = [...$colors, ...$theme->getColors()];
        }

        return [...$colors, ...$this->getColors()];
    }

    /**
     * @return array<int, string>
     */
    public function getViteEntries(): array
    {
        $entries = $this->rememberThemeValue('viteTheme', fn (): mixed => $this->evaluate($this->viteTheme)) ?? config('alfakit.inertia.vite.css');
        $entries = array_values(array_filter((array) $entries));

        $script = (string) config('alfakit.inertia.vite.js');

        foreach ($entries as $entry) {
            if (preg_match('/\.(t|j)sx?$/', $entry) === 1) {
                return $entries;
            }
        }

        return [...$entries, $script];
    }

    /**
     * The panel's `@vite` tags. A build without the panel entries (a starter kit's build
     * from before AlfaKit was installed) fails with a hint to rebuild, not Vite's bare
     * "Unable to locate file in Vite manifest".
     */
    public function renderViteTags(): Htmlable
    {
        try {
            $vite = app(Vite::class);
            $entries = $this->getViteEntries();
            $tags = $vite($entries);

            // Under `npm run dev`, @vitejs/plugin-react needs its preamble before any JSX
            // module (a generated field, `withApp`), or the page stays blank. Null when built.
            $usesJsx = collect($entries)->contains(fn (string $entry): bool => preg_match('/\.[jt]sx$/', $entry) === 1);
            $refresh = $usesJsx ? $vite->reactRefresh() : null;

            // Strings, not toHtml(): tests swap Vite for a fake that returns plain strings.
            return new HtmlString(($refresh === null ? '' : (string) $refresh).$tags);
        } catch (ViteException $exception) {
            throw new ViteException($exception->getMessage().' Build the frontend with `npm run build` (or keep `npm run dev` running while developing); `php artisan alfakit:doctor` checks the setup.', previous: $exception);
        }
    }

    /**
     * Attributes for the `<html>` element, so the first paint is already themed.
     *
     * @return array<string, string>
     */
    public function getHtmlThemeAttributes(): array
    {
        $attributes = [
            'data-panel' => $this->getId(),
            'data-alfakit-theme' => $this->getThemeAttribute(),
            'data-sidebar-variant' => $this->getSidebarVariant()->value,
            'data-density' => $this->getDensity()->value,
        ];

        foreach ($this->getDesign() as $dimension => $value) {
            $attributes['data-'.$dimension] = $value;
        }

        // `colors(['gray' => …])` tints the neutral tokens (themes.css).
        if (isset($this->getThemeColors()['gray'])) {
            $attributes['data-alfakit-gray'] = 'true';
        }

        return array_filter($attributes);
    }

    /** Inline CSS variables for the `<html>` element (radius, font, colours). */
    public function getHtmlThemeStyle(): string
    {
        $variables = [];

        if (($radius = $this->getRadius()) !== null) {
            $variables['--radius'] = $radius;
        }

        if (($font = $this->getFontCssValue()) !== null) {
            $variables['--alfakit-font'] = $font;
        }

        if (($heading = $this->getHeadingFontCssValue()) !== null) {
            $variables['--alfakit-heading-font'] = $heading;
        }

        foreach ($this->getThemeColorVariables() as $name => $value) {
            $variables['--alfakit-'.$name] = $value;
        }

        return implode('; ', array_map(
            static fn (string $name, string $value): string => $name.': '.str_replace([';', '"', '<', '>'], '', $value),
            array_keys($variables),
            $variables,
        ));
    }

    public static function isValidColorName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9-]*$/i', $name) === 1;
    }

    /**
     * Custom colour names from `colors(['brand' => '#…'])`: each gets a class that points
     * the renderers' generic colour utilities (`alfakit-solid`, `alfakit-soft`, …, in
     * themes.css) at `--alfakit-{name}`, so `->color('brand')` works on buttons and badges.
     */
    public function getCustomColorCss(): ?string
    {
        $rules = [];

        foreach ($this->getThemeColors() as $name => $value) {
            $name = (string) $name;

            if (in_array($name, self::BUILT_IN_COLORS, true) || ! self::isValidColorName($name)) {
                continue;
            }

            $foreground = ColorContrast::foreground((string) $value) ?? ColorContrast::LIGHT;

            $rules[] = '.alfakit-color-'.$name.'{--alfakit-c:var(--alfakit-'.$name.');--alfakit-c-foreground:var(--alfakit-'.$name.'-foreground,'.$foreground.')}';
        }

        return $rules === [] ? null : implode("\n", $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function getThemeSchema(): array
    {
        return [
            'name' => $this->getTheme()->getName(),
            'attribute' => $this->getThemeAttribute(),
            'sidebar' => $this->getSidebarVariant()->value,
            'density' => $this->getDensity()->value,
            'radius' => $this->getRadius(),
            'font' => $this->getFont(),
            'fontCss' => $this->getFontCssValue(),
            'headingFontCss' => $this->getHeadingFontCssValue(),
            'fontUrl' => $this->getFontUrl(),
            'fontUrls' => $this->getFontUrls(),
            'design' => $this->getDesign(),
            // `Icons::register(['alfakit::panels.…' => …])` for the renderer chrome.
            'icons' => Icons::getAliasOverrides() ?: null,
        ];
    }

    private function fromLineage(Closure $read): mixed
    {
        $value = null;

        foreach (Themes::lineage($this->getTheme()->getName()) as $theme) {
            $value = $read($theme) ?? $value;
        }

        return $value;
    }

    private function isGenericFontFamily(?string $font): bool
    {
        return in_array($font, ['system-ui', 'ui-sans-serif', 'ui-serif', 'ui-monospace', 'ui-rounded', 'sans-serif', 'serif', 'monospace'], true);
    }
}
