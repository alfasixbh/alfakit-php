<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use AlfaSix\Alfakit\Commands\MakeThemeCommand;
use Composer\InstalledVersions;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * What the setup commands need to know about, and do to, the application: installed
 * Composer and npm packages, `.env` keys, and running composer, npm and artisan in a
 * subprocess (so packages required a moment ago are autoloaded). Subprocesses go through
 * the `Process` facade, so tests fake them with `Process::fake()`.
 */
final class AppEnvironment
{
    /**
     * Whether a Composer package is installed, read from `vendor/composer/installed.json`
     * on every call (a `composer require` earlier in the same command counts).
     */
    public static function hasComposerPackage(string $package): bool
    {
        $installed = base_path('vendor/composer/installed.json');

        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $packages = is_array($data) ? ($data['packages'] ?? $data) : [];

            foreach (is_array($packages) ? $packages : [] as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $package) {
                    return true;
                }
            }

            return false;
        }

        $composer = base_path('composer.json');

        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);

            if (is_array($data) && (isset($data['require'][$package]) || isset($data['require-dev'][$package]))) {
                return true;
            }
        }

        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    /**
     * @return array<string, mixed>
     */
    public static function packageJson(): array
    {
        $path = base_path('package.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    public static function hasNpmPackage(string $package): bool
    {
        $json = self::packageJson();

        return isset($json['dependencies'][$package]) || isset($json['devDependencies'][$package]);
    }

    /**
     * The Inertia adapter the app uses: react, vue, svelte or null.
     */
    public static function adapter(): ?string
    {
        return match (true) {
            self::hasNpmPackage('@inertiajs/react') => 'react',
            self::hasNpmPackage('@inertiajs/vue3') => 'vue',
            self::hasNpmPackage('@inertiajs/svelte') => 'svelte',
            default => null,
        };
    }

    /**
     * A Laravel starter kit's app layout (`resources/js/layouts/app-layout.*`).
     */
    public static function starterKitLayout(): ?string
    {
        foreach (['tsx', 'jsx', 'vue', 'svelte'] as $extension) {
            foreach (['layouts/app-layout', 'layouts/AppLayout', 'layouts/app-layout/index'] as $name) {
                $path = resource_path("js/{$name}.{$extension}");

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    public static function hasFortify(): bool
    {
        return self::hasComposerPackage('laravel/fortify') || is_file(config_path('fortify.php'));
    }

    /**
     * @param  array<int, string>  $packages
     */
    public static function composerRequire(array $packages, bool $dev = false, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            ['composer', 'require', ...$packages, ...($dev ? ['--dev'] : []), '--no-interaction'],
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * @param  array<int, string>  $packages
     */
    public static function composerRemove(array $packages, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            ['composer', 'remove', ...$packages, '--no-interaction'],
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * @param  array<int, string>  $packages
     * @return array<int, string>
     */
    public static function npmInstallCommand(array $packages, bool $dev = false): array
    {
        $manager = MakeThemeCommand::detectPackageManager();

        return match ($manager) {
            'npm' => ['npm', 'install', ...($dev ? ['--save-dev'] : []), ...$packages],
            default => [$manager, 'add', ...($dev ? ['-D'] : []), ...$packages],
        };
    }

    /**
     * @param  array<int, string>  $packages
     */
    public static function npmInstall(array $packages, bool $dev = false, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            self::npmInstallCommand($packages, $dev),
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * Run `php artisan …` in a fresh process.
     *
     * @param  array<int, string>  $arguments
     */
    public static function artisan(array $arguments, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            [PHP_BINARY, 'artisan', ...$arguments],
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * The raw value of a `.env` key, or null when the key is absent.
     */
    public static function envValue(string $key, ?string $file = null): ?string
    {
        $path = $file ?? base_path('.env');

        if (! is_file($path)) {
            return null;
        }

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($path), $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Set `.env`-style keys. Existing keys keep their value unless `$overwrite`; a missing
     * file is created only when `$create`. `$raw` writes values as given (for
     * `"${OTHER_KEY}"` references).
     *
     * @param  array<string, string>  $variables
     * @return array<int, string> The keys that were written.
     */
    public static function writeEnv(array $variables, ?string $file = null, bool $overwrite = false, bool $create = false, bool $raw = false): array
    {
        $path = $file ?? base_path('.env');

        if (! is_file($path)) {
            if (! $create) {
                return [];
            }

            file_put_contents($path, '');
        }

        $contents = (string) file_get_contents($path);
        $written = [];
        $appended = [];

        foreach ($variables as $key => $value) {
            $line = $key.'='.($raw ? $value : self::quoteEnvValue($value));
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                if ($overwrite) {
                    $contents = (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
                    $written[] = $key;
                }

                continue;
            }

            $appended[] = $line;
            $written[] = $key;
        }

        if ($appended !== []) {
            $contents = rtrim($contents)."\n\n".implode("\n", $appended)."\n";
            $contents = ltrim($contents, "\n");
        }

        file_put_contents($path, $contents);

        return $written;
    }

    /**
     * Quote a value so phpdotenv reads it back verbatim (no `${…}` interpolation).
     */
    public static function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-.:\/+=@,]+$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
