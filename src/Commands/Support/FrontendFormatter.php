<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Formats frontend files that generators write into an application (ejected components,
 * `make:alfakit-field|column|entry` components, plugin pages) with the application's own
 * formatter, so they pass its `vp check` / `prettier --check` as written.
 *
 * The formatter is the project's local binary with the project's configuration: Vite+
 * (`node_modules/.bin/vp fmt`, when `vite.config.*` has a `fmt` section), Oxfmt
 * (`.oxfmtrc.json(c)`) or Prettier (a Prettier config file or `"prettier"` in
 * package.json). Without one, or when it fails, the files are left as generated.
 * `config('alfakit.file_generation.format_frontend', true)` turns it off.
 */
final class FrontendFormatter
{
    public const EXTENSIONS = ['ts', 'tsx', 'js', 'jsx', 'mts', 'mjs', 'vue', 'svelte'];

    private const PRETTIER_CONFIGS = [
        '.prettierrc', '.prettierrc.json', '.prettierrc.json5', '.prettierrc.yaml', '.prettierrc.yml', '.prettierrc.toml',
        '.prettierrc.js', '.prettierrc.cjs', '.prettierrc.mjs', '.prettierrc.ts', '.prettierrc.cts', '.prettierrc.mts',
        'prettier.config.js', 'prettier.config.cjs', 'prettier.config.mjs', 'prettier.config.ts', 'prettier.config.cts', 'prettier.config.mts',
    ];

    /**
     * Format the given files in place; returns the formatter that ran (`vp`, `oxfmt`,
     * `prettier`) or null when none did.
     *
     * @param  array<int, string>  $paths  absolute paths
     */
    public static function format(array $paths, ?string $basePath = null): ?string
    {
        if (! (bool) config('alfakit.file_generation.format_frontend', true)) {
            return null;
        }

        $basePath = rtrim($basePath ?? base_path(), '/');
        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => is_file($path)
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true))));

        if ($paths === []) {
            return null;
        }

        $formatter = self::detect($basePath);

        if ($formatter === null) {
            return null;
        }

        [$name, $command] = $formatter;

        try {
            $result = Process::path($basePath)->timeout(60)->run([...$command, ...$paths]);

            return $result->successful() ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The application's formatter: its name and command (without the files).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function detect(string $basePath): ?array
    {
        $bin = static fn (string $name): ?string => collect(PHP_OS_FAMILY === 'Windows' ? ["{$name}.cmd", $name] : [$name])
            ->map(static fn (string $file): string => "{$basePath}/node_modules/.bin/{$file}")
            ->first(static fn (string $path): bool => is_file($path));

        if (($vp = $bin('vp')) !== null && self::viteConfigFormats($basePath)) {
            return ['vp', [$vp, 'fmt']];
        }

        if (($oxfmt = $bin('oxfmt')) !== null && (is_file("{$basePath}/.oxfmtrc.json") || is_file("{$basePath}/.oxfmtrc.jsonc"))) {
            return ['oxfmt', [$oxfmt]];
        }

        if (($prettier = $bin('prettier')) !== null && self::hasPrettierConfig($basePath)) {
            return ['prettier', [$prettier, '--write']];
        }

        return null;
    }

    private static function viteConfigFormats(string $basePath): bool
    {
        foreach (['ts', 'mts', 'js', 'mjs'] as $extension) {
            $config = "{$basePath}/vite.config.{$extension}";

            if (is_file($config)) {
                return preg_match('/\bfmt\s*:/', (string) file_get_contents($config)) === 1;
            }
        }

        return false;
    }

    private static function hasPrettierConfig(string $basePath): bool
    {
        foreach (self::PRETTIER_CONFIGS as $file) {
            if (is_file("{$basePath}/{$file}")) {
                return true;
            }
        }

        $package = is_file("{$basePath}/package.json") ? json_decode((string) file_get_contents("{$basePath}/package.json"), true) : null;

        return is_array($package) && isset($package['prettier']);
    }
}
