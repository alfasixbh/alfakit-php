<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Assets;

use InvalidArgumentException;

/**
 * Stylesheets and scripts that plugins (or the application) add to every panel page's
 * `<head>`, next to the Vite entries:
 *
 * ```php
 * // A plugin's service provider, in boot()
 * Assets::register([
 *     Css::make('blog', __DIR__.'/../resources/dist/blog.css'),
 *     Js::make('blog', __DIR__.'/../resources/dist/blog.js')->module(),
 * ], package: 'acme/blog');
 * ```
 *
 * Local files are copied to `public/vendor/alfakit/{package}/` by `php artisan
 * alfakit:assets` (run by `alfakit:upgrade`); URLs are linked as they are. Pass `panel:`
 * to load the assets on one panel only.
 *
 * `->loadedOnRequest()` keeps an asset out of `<head>`: pass `Assets::getStyleHref('id',
 * 'acme/blog')` / `getScriptSrc()` to the component that needs it and load it there with
 * `loadStyle()` / `loadScript()` from `@alfakit/core`. `registerCssVariables()` adds CSS
 * custom properties to `:root` and `registerScriptData()` exposes data to scripts as
 * `window.alfakitData` (`getScriptData()` in `@alfakit/core`).
 */
final class Assets
{
    /** @var array<string, array{asset: Asset, panels: array<int, string>|null}> */
    private static array $assets = [];

    /** @var array<string, array{value: string, panels: array<int, string>|null}> */
    private static array $cssVariables = [];

    /** @var array<string, array{value: mixed, panels: array<int, string>|null}> */
    private static array $scriptData = [];

    /**
     * @param  array<Asset>  $assets
     * @param  string|array<int, string>|null  $panel  Panel id(s); null for every panel
     */
    public static function register(array $assets, ?string $package = null, string|array|null $panel = null): void
    {
        if ($package !== null && preg_match('#^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)?$#i', $package) !== 1) {
            throw new InvalidArgumentException("Alfakit asset packages look like [vendor/name]; [{$package}] given.");
        }

        foreach ($assets as $asset) {
            $asset->package($package);

            // Registering again (a plugin booted on every request) replaces the entry.
            self::$assets[$asset->getPackage().'/'.$asset->getExtension().'/'.$asset->getId()] = [
                'asset' => $asset,
                'panels' => $panel === null ? null : array_values((array) $panel),
            ];
        }
    }

    /**
     * @return array<int, Asset>
     */
    public static function all(): array
    {
        return array_values(array_map(static fn (array $entry): Asset => $entry['asset'], self::$assets));
    }

    /**
     * @return array<int, Css>
     */
    public static function getStyles(?string $panel = null): array
    {
        return array_values(array_filter(self::forPanel($panel), static fn (Asset $asset): bool => $asset instanceof Css));
    }

    /**
     * @return array<int, Js>
     */
    public static function getScripts(?string $panel = null): array
    {
        return array_values(array_filter(self::forPanel($panel), static fn (Asset $asset): bool => $asset instanceof Js));
    }

    /**
     * CSS custom properties for `:root` (names with or without the leading `--`):
     *
     * ```php
     * Assets::registerCssVariables(['blog-banner' => 'url('.asset('img/banner.jpg').')'], package: 'acme/blog');
     * ```
     *
     * Values containing `;`, `{`, `}`, `<` or `>` are dropped, so they can't leave the
     * declaration.
     *
     * @param  array<string, string|int|float>  $variables
     * @param  string|array<int, string>|null  $panel  Panel id(s); null for every panel
     */
    public static function registerCssVariables(array $variables, ?string $package = null, string|array|null $panel = null): void
    {
        foreach ($variables as $name => $value) {
            $name = ltrim((string) $name, '-');

            if (preg_match('/^[a-z0-9_-]+$/i', $name) !== 1) {
                throw new InvalidArgumentException("CSS variable names may only contain letters, numbers, dashes and underscores; [{$name}] given.");
            }

            self::$cssVariables[$name] = [
                'value' => (string) $value,
                'panels' => $panel === null ? null : array_values((array) $panel),
            ];
        }
    }

    /**
     * Data for scripts, merged into `window.alfakitData` before any registered script
     * runs (read it with `getScriptData()` from `@alfakit/core`). Keys are top-level
     * properties; registering a key again replaces it. Never put secrets here: it is part
     * of the page.
     *
     * @param  array<string, mixed>  $data
     * @param  string|array<int, string>|null  $panel  Panel id(s); null for every panel
     */
    public static function registerScriptData(array $data, ?string $package = null, string|array|null $panel = null): void
    {
        foreach ($data as $key => $value) {
            self::$scriptData[(string) $key] = [
                'value' => $value,
                'panels' => $panel === null ? null : array_values((array) $panel),
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    public static function getCssVariables(?string $panel = null): array
    {
        $variables = [];

        foreach (self::$cssVariables as $name => $entry) {
            if (self::appliesTo($entry['panels'], $panel) && preg_match('/[;{}<>]/', $entry['value']) !== 1) {
                $variables[$name] = $entry['value'];
            }
        }

        return $variables;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getScriptData(?string $panel = null): array
    {
        $data = [];

        foreach (self::$scriptData as $key => $entry) {
            if (self::appliesTo($entry['panels'], $panel)) {
                $data[$key] = $entry['value'];
            }
        }

        return $data;
    }

    /**
     * The URL of a registered stylesheet, for one loaded on request; null if unknown.
     */
    public static function getStyleHref(string $id, ?string $package = null): ?string
    {
        return self::find('css', $id, $package)?->getUrl();
    }

    /**
     * The URL of a registered script, for one loaded on request; null if unknown.
     */
    public static function getScriptSrc(string $id, ?string $package = null): ?string
    {
        return self::find('js', $id, $package)?->getUrl();
    }

    public static function find(string $extension, string $id, ?string $package = null): ?Asset
    {
        return (self::$assets[($package ?? 'app').'/'.$extension.'/'.$id] ?? null)['asset'] ?? null;
    }

    /**
     * The `<link>` and `<script>` tags for a panel's `<head>`.
     */
    public static function renderTags(?string $panel = null, ?string $nonce = null): string
    {
        $html = '';
        $nonceAttribute = $nonce !== null && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '';

        if (($variables = self::getCssVariables($panel)) !== []) {
            $declarations = implode('', array_map(
                static fn (string $name, string $value): string => '--'.$name.':'.$value.';',
                array_keys($variables),
                $variables,
            ));

            $html .= '<style data-alfakit-css-variables'.$nonceAttribute.'>:root{'.$declarations."}</style>\n";
        }

        if (($data = self::getScriptData($panel)) !== []) {
            $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

            $html .= '<script data-alfakit-script-data'.$nonceAttribute.'>window.alfakitData=Object.assign(window.alfakitData||{},'.$json.");</script>\n";
        }

        $assets = array_filter([...self::getStyles($panel), ...self::getScripts($panel)], static fn (Asset $asset): bool => ! $asset->isLoadedOnRequest());

        foreach ($assets as $asset) {
            if ($asset instanceof Js && $asset->isInline()) {
                $html .= $asset->getInlineHtml($nonce)."\n";

                continue;
            }

            $attributes = $asset->getHtmlAttributes();

            if ($nonce !== null && $nonce !== '') {
                $attributes['nonce'] = $nonce;
            }

            $html .= $asset instanceof Css
                ? '<link'.self::attributes($attributes).'>'
                : '<script'.self::attributes($attributes).'></script>';
            $html .= "\n";
        }

        return $html;
    }

    /** @internal Tests and long-running workers that rebuild the registry. */
    public static function flush(): void
    {
        self::$assets = [];
        self::$cssVariables = [];
        self::$scriptData = [];
    }

    /**
     * @return array<int, Asset>
     */
    private static function forPanel(?string $panel): array
    {
        $assets = [];

        foreach (self::$assets as $entry) {
            if (self::appliesTo($entry['panels'], $panel)) {
                $assets[] = $entry['asset'];
            }
        }

        return $assets;
    }

    /**
     * @param  array<int, string>|null  $panels
     */
    private static function appliesTo(?array $panels, ?string $panel): bool
    {
        return $panels === null || ($panel !== null && in_array($panel, $panels, true));
    }

    /**
     * @param  array<string, string|bool>  $attributes
     */
    private static function attributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= $value === true ? ' '.e($name) : ' '.e($name).'="'.e((string) $value).'"';
        }

        return $html;
    }
}
