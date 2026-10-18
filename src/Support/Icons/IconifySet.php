<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Icons;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * An Iconify icon set (`@iconify-json/{set}`'s `icons.json`), registered with
 * `Icons::iconify()`. Icons are turned into sanitised `<svg>` markup on the server.
 *
 * The JSON file can be megabytes, so it is only decoded when an icon isn't cached yet:
 * resolved icons and the name list are kept in Laravel's cache under keys that include the
 * file's modification time (updating the package invalidates them), plus a per-request memo.
 */
final class IconifySet
{
    /** @var array<string, array<mixed>> decoded JSON files, most recent last (at most two are kept in memory) */
    private static array $data = [];

    /** @var array<string, array<string, string>> per-request memo: cache key prefix → name → SVG ('' = missing) */
    private static array $svgs = [];

    /** @var array<string, array{names: array<string, string>, label: string}> */
    private static array $catalogs = [];

    public function __construct(
        public readonly string $prefix,
        public readonly string $path,
    ) {}

    /**
     * The sanitised `<svg>` for an icon (or alias) of this set, or null when it has none.
     */
    public function svg(string $name): ?string
    {
        $key = $this->cacheKey();

        if ($key === null) {
            return null;
        }

        if (! isset(self::$svgs[$key][$name])) {
            if (count(self::$svgs[$key] ?? []) > 2000) {
                self::$svgs[$key] = [];
            }

            self::$svgs[$key][$name] = (string) $this->remember("{$key}.svg.{$name}", fn (): string => $this->build($name) ?? '');
        }

        return self::$svgs[$key][$name] === '' ? null : self::$svgs[$key][$name];
    }

    public function has(string $name): bool
    {
        return isset($this->names()[$name]) || $this->svg($name) !== null;
    }

    /**
     * The visible icons (no hidden icons, no aliases), mapped to their alias names joined by
     * spaces: the picker searches both.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return $this->catalog()['names'];
    }

    /**
     * The set's name from its `info` (`Material Design Icons`), or the prefix.
     */
    public function label(): string
    {
        return $this->catalog()['label'];
    }

    /**
     * Whether the JSON file exists (the npm package is installed).
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Forget the per-request memo (tests, `Icons::flush()`).
     */
    public static function flushMemo(): void
    {
        self::$data = [];
        self::$svgs = [];
        self::$catalogs = [];
    }

    /**
     * @return array{names: array<string, string>, label: string}
     */
    private function catalog(): array
    {
        $key = $this->cacheKey();

        if ($key === null) {
            return ['names' => [], 'label' => $this->prefix];
        }

        return self::$catalogs[$key] ??= (array) $this->remember("{$key}.catalog", function (): array {
            $data = $this->data();
            $names = [];

            foreach ((array) ($data['icons'] ?? []) as $name => $icon) {
                if (is_array($icon) && empty($icon['hidden'])) {
                    $names[(string) $name] = [];
                }
            }

            foreach ((array) ($data['aliases'] ?? []) as $alias => $definition) {
                $parent = is_array($definition) ? ($definition['parent'] ?? null) : null;

                // Only plain aliases are search keywords; transformed ones are other icons.
                if (is_string($parent) && isset($names[$parent]) && array_diff(array_keys($definition), ['parent']) === [] && empty($definition['hidden'])) {
                    $names[$parent][] = (string) $alias;
                }
            }

            ksort($names);
            $info = $data['info'] ?? null;

            return [
                'names' => array_map(static fn (array $aliases): string => implode(' ', $aliases), $names),
                'label' => is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : $this->prefix,
            ];
        });
    }

    /**
     * Iconify's `iconToSVG()`: merge the alias chain's and set's defaults, apply flips and
     * rotation, wrap the body in an `<svg>` with the icon's viewBox and sanitise it.
     */
    private function build(string $name): ?string
    {
        $data = $this->data();
        $icons = (array) ($data['icons'] ?? []);
        $aliases = (array) ($data['aliases'] ?? []);
        $props = ['rotate' => 0, 'hFlip' => false, 'vFlip' => false];
        $overrides = [];

        for ($depth = 0; ! isset($icons[$name]) && $depth < 6; $depth++) {
            $alias = $aliases[$name] ?? null;

            if (! is_array($alias) || ! is_string($alias['parent'] ?? null)) {
                return null;
            }

            $props = self::mergeTransforms($props, $alias);

            foreach (['left', 'top', 'width', 'height'] as $dimension) {
                if (isset($alias[$dimension]) && ! isset($overrides[$dimension])) {
                    $overrides[$dimension] = $alias[$dimension];
                }
            }

            $name = $alias['parent'];
        }

        $icon = $icons[$name] ?? null;

        if (! is_array($icon) || ! is_string($icon['body'] ?? null)) {
            return null;
        }

        $props = self::mergeTransforms($props, $icon);
        $box = [];

        foreach (['left' => 0, 'top' => 0, 'width' => 16, 'height' => 16] as $dimension => $default) {
            $box[$dimension] = (float) ($overrides[$dimension] ?? $icon[$dimension] ?? $data[$dimension] ?? $default);
        }

        $body = $icon['body'];
        $transforms = [];
        $rotate = $props['rotate'];

        if ($props['hFlip']) {
            if ($props['vFlip']) {
                $rotate += 2;
            } else {
                $transforms[] = 'translate('.self::number($box['width'] + $box['left']).' '.self::number(0 - $box['top']).')';
                $transforms[] = 'scale(-1 1)';
                $box['top'] = $box['left'] = 0.0;
            }
        } elseif ($props['vFlip']) {
            $transforms[] = 'translate('.self::number(0 - $box['left']).' '.self::number($box['height'] + $box['top']).')';
            $transforms[] = 'scale(1 -1)';
            $box['top'] = $box['left'] = 0.0;
        }

        $rotate = (($rotate % 4) + 4) % 4;

        if ($rotate === 1) {
            $center = $box['height'] / 2 + $box['top'];
            array_unshift($transforms, 'rotate(90 '.self::number($center).' '.self::number($center).')');
        } elseif ($rotate === 2) {
            array_unshift($transforms, 'rotate(180 '.self::number($box['width'] / 2 + $box['left']).' '.self::number($box['height'] / 2 + $box['top']).')');
        } elseif ($rotate === 3) {
            $center = $box['width'] / 2 + $box['left'];
            array_unshift($transforms, 'rotate(-90 '.self::number($center).' '.self::number($center).')');
        }

        if ($rotate % 2 === 1) {
            [$box['left'], $box['top']] = [$box['top'], $box['left']];
            [$box['width'], $box['height']] = [$box['height'], $box['width']];
        }

        if ($transforms !== []) {
            $body = '<g transform="'.implode(' ', $transforms).'">'.$body.'</g>';
        }

        $viewBox = implode(' ', array_map(self::number(...), [$box['left'], $box['top'], $box['width'], $box['height']]));

        return SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="'.$viewBox.'" aria-hidden="true">'.$body.'</svg>');
    }

    /**
     * @param  array{rotate: int, hFlip: bool, vFlip: bool}  $props
     * @param  array<mixed>  $source
     * @return array{rotate: int, hFlip: bool, vFlip: bool}
     */
    private static function mergeTransforms(array $props, array $source): array
    {
        return [
            'rotate' => $props['rotate'] + (int) ($source['rotate'] ?? 0),
            'hFlip' => $props['hFlip'] !== (bool) ($source['hFlip'] ?? false),
            'vFlip' => $props['vFlip'] !== (bool) ($source['vFlip'] ?? false),
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * The decoded JSON file (only on a cache miss).
     *
     * @return array<mixed>
     */
    private function data(): array
    {
        $key = $this->cacheKey() ?? '';

        if (! isset(self::$data[$key])) {
            $decoded = json_decode((string) @file_get_contents($this->path), true);
            self::$data = array_slice(self::$data, -1, 1, true);
            self::$data[$key] = is_array($decoded) ? $decoded : [];
        }

        return self::$data[$key];
    }

    /**
     * `alfakit.iconify.{prefix}.{hash of the path}.{mtime}`, or null when the file is missing.
     */
    private function cacheKey(): ?string
    {
        $mtime = @filemtime($this->path);

        if ($mtime === false) {
            return null;
        }

        return 'alfakit.iconify.'.$this->prefix.'.'.substr(md5($this->path), 0, 12).'.'.$mtime;
    }

    private function remember(string $key, Closure $callback): mixed
    {
        $store = self::store();

        if ($store === null) {
            return $callback();
        }

        try {
            return $store->rememberForever($key, $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    private static function store(): ?Repository
    {
        $config = function_exists('config') && app()->bound('config') ? config('alfakit.icons.cache', true) : false;

        if ($config === false || ! app()->bound('cache')) {
            return null;
        }

        return Cache::store(is_string($config) ? $config : null);
    }
}
