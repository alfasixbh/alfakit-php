<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Finds the Filament code in an application: the `app/Filament` and
 * `app/Providers/Filament` directories, every PHP file that references `Filament\`
 * (including Livewire components and tests), Blade views using Filament components,
 * theme CSS, `config/filament.php`, Filament packages and `filament:*` scripts in
 * composer.json, and the installed Filament major version.
 */
final class FilamentDiscovery
{
    /** @var array<int, string> */
    public array $directories = [];

    /** @var array<int, string> PHP files (absolute) that reference Filament, excluding Livewire components and tests */
    public array $phpFiles = [];

    /** @var array<int, string> Livewire components (in app/Livewire) that reference Filament */
    public array $livewireComponents = [];

    /** @var array<int, string> every Livewire component class in app/Livewire, Filament or not */
    public array $livewireClasses = [];

    /** @var array<int, string> */
    public array $tests = [];

    /** @var array<int, array{file: string, line: int, what: string}> */
    public array $bladeViews = [];

    /** @var array<int, string> */
    public array $themes = [];

    /** @var array<int, string> */
    public array $publishedViews = [];

    public ?string $configFile = null;

    /** @var array<string, string> package → constraint */
    public array $packages = [];

    /** @var array<string, string> package → constraint */
    public array $devPackages = [];

    /** @var array<int, array{script: string, command: string}> */
    public array $composerScripts = [];

    public string $version = 'unknown';

    public ?string $adapter = null;

    public bool $hasInertia = false;

    public bool $hasGit = false;

    public function __construct(private readonly string $basePath, private readonly Filesystem $files) {}

    public static function scan(string $basePath, ?Filesystem $files = null): self
    {
        $discovery = new self(rtrim($basePath, '/'), $files ?? new Filesystem);
        $discovery->run();

        return $discovery;
    }

    private function run(): void
    {
        $this->hasGit = is_dir($this->basePath.'/.git');
        $this->version = $this->detectVersion();
        $this->detectFrontend();

        foreach (['app/Filament', 'app/Providers/Filament'] as $directory) {
            if (is_dir($this->basePath.'/'.$directory)) {
                $this->directories[] = $directory;
            }
        }

        foreach ($this->phpFilesReferencingFilament() as $file) {
            $relative = $this->relative($file);

            if (str_starts_with($relative, 'app/Livewire/')) {
                $this->livewireComponents[] = $file;
            } elseif (str_starts_with($relative, 'tests/')) {
                $this->tests[] = $file;
            } else {
                $this->phpFiles[] = $file;
            }
        }

        $this->bladeViews = $this->bladeViewsUsingFilament();
        $this->livewireClasses = $this->livewireClasses();

        foreach (['resources/css/filament'] as $directory) {
            if (is_dir($this->basePath.'/'.$directory)) {
                foreach (Finder::create()->files()->in($this->basePath.'/'.$directory)->name(['*.css', '*.js'])->sortByName() as $file) {
                    $this->themes[] = $file->getPathname();
                }
            }
        }

        if (is_dir($this->basePath.'/resources/views/vendor')) {
            foreach (Finder::create()->directories()->in($this->basePath.'/resources/views/vendor')->depth(0)->name('filament*')->sortByName() as $directory) {
                $this->publishedViews[] = $directory->getPathname();
            }
        }

        if (is_file($this->basePath.'/config/filament.php')) {
            $this->configFile = $this->basePath.'/config/filament.php';
        }

        $this->readComposer();
    }

    /**
     * Nothing Filament-specific left to rewrite, move or report.
     */
    public function isEmpty(): bool
    {
        return $this->directories === []
            && $this->phpFiles === []
            && $this->livewireComponents === []
            && $this->tests === []
            && $this->bladeViews === []
            && $this->themes === []
            && $this->publishedViews === []
            && $this->configFile === null
            && $this->composerScripts === [];
    }

    /**
     * @return array<int, string>
     */
    public function allPackages(): array
    {
        return array_keys([...$this->packages, ...$this->devPackages]);
    }

    public function relative(string $path): string
    {
        return str_starts_with($path, $this->basePath.'/') ? substr($path, strlen($this->basePath) + 1) : $path;
    }

    private function detectVersion(): string
    {
        foreach (['vendor/filament/filament/composer.json', 'vendor/filament/support/composer.json'] as $candidate) {
            $json = $this->json($this->basePath.'/'.$candidate);

            if (isset($json['version']) && is_string($json['version'])) {
                return $json['version'];
            }
        }

        $lock = $this->json($this->basePath.'/composer.lock');

        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (is_array($package) && in_array($package['name'] ?? null, ['filament/filament', 'filament/support', 'filament/forms', 'filament/tables'], true) && isset($package['version'])) {
                return (string) $package['version'];
            }
        }

        $composer = $this->json($this->basePath.'/composer.json');

        foreach (['filament/filament', 'filament/forms', 'filament/tables', 'filament/support'] as $package) {
            foreach (['require', 'require-dev'] as $section) {
                if (isset($composer[$section][$package])) {
                    return (string) $composer[$section][$package];
                }
            }
        }

        return 'unknown';
    }

    /**
     * The major version (3, 4, 5) when it can be read from the detected version.
     */
    public function major(): ?int
    {
        return preg_match('/(\d+)/', $this->version, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function detectFrontend(): void
    {
        $package = $this->json($this->basePath.'/package.json');
        $dependencies = [...(array) ($package['dependencies'] ?? []), ...(array) ($package['devDependencies'] ?? [])];

        $this->adapter = match (true) {
            isset($dependencies['@alfakit/react'], $dependencies['@inertiajs/react']) => 'react',
            isset($dependencies['@alfakit/vue'], $dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@alfakit/svelte'], $dependencies['@inertiajs/svelte']) => 'svelte',
            isset($dependencies['@inertiajs/react']) => 'react',
            isset($dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@inertiajs/svelte']) => 'svelte',
            default => null,
        };

        $this->hasInertia = $this->adapter !== null;
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesReferencingFilament(): array
    {
        $roots = array_filter(
            array_map(fn (string $directory): string => $this->basePath.'/'.$directory, ['app', 'routes', 'config', 'database', 'tests', 'bootstrap']),
            is_dir(...),
        );

        if ($roots === []) {
            return [];
        }

        $finder = Finder::create()
            ->files()
            ->in(array_values($roots))
            ->name('*.php')
            ->notPath('#^cache/#')
            ->notPath('#^filament\.php$#')
            ->sortByName();

        $files = [];

        foreach ($finder as $file) {
            $path = $file->getPathname();

            if ($path === $this->configFile || str_ends_with($path, '/config/filament.php')) {
                continue;
            }

            $contents = $file->getContents();

            if (preg_match('/\bFilament\\\\|App\\\\Filament\b|App\\\\Providers\\\\Filament\b|\bfilament:[a-z]/', $contents) === 1 || self::mentionsPlugin($contents)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private static function mentionsPlugin(string $contents): bool
    {
        foreach (FilamentClassMap::pluginNamespaces() as $namespace) {
            if (str_contains($contents, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classes under app/Livewire that extend a Livewire component (see
     * `extendsLivewireComponent()`).
     *
     * @return array<int, string>
     */
    private function livewireClasses(): array
    {
        if (! is_dir($this->basePath.'/app/Livewire')) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->in($this->basePath.'/app/Livewire')->name('*.php')->sortByName() as $file) {
            $contents = $file->getContents();

            if ($this->extendsLivewireComponent($contents)) {
                $classes[] = $file->getPathname();
            }
        }

        return $classes;
    }

    /**
     * `extends \Livewire\Component` or `extends Livewire\Component`, or the short or aliased
     * name imported with `use Livewire\Component;` / `use Livewire\Component as Base;`.
     */
    private function extendsLivewireComponent(string $contents): bool
    {
        if (preg_match('/\bextends\s+\\\\?Livewire\\\\Component\b/', $contents) === 1) {
            return true;
        }

        if (preg_match('/^use\s+\\\\?Livewire\\\\Component(?:\s+as\s+(\w+))?\s*;/m', $contents, $import) !== 1) {
            return false;
        }

        $name = $import[1] ?? 'Component';

        return preg_match('/\bextends\s+'.preg_quote($name, '/').'\b/', $contents) === 1;
    }

    /**
     * @return array<int, array{file: string, line: int, what: string}>
     */
    private function bladeViewsUsingFilament(): array
    {
        if (! is_dir($this->basePath.'/resources/views')) {
            return [];
        }

        $views = [];

        foreach (Finder::create()->files()->in($this->basePath.'/resources/views')->name('*.blade.php')->notPath('#^vendor/#')->sortByName() as $file) {
            $lines = preg_split('/\R/', $file->getContents()) ?: [];
            $relative = $this->relative($file->getPathname());
            $pattern = str_starts_with($relative, 'resources/views/filament/') || str_starts_with($relative, 'resources/views/livewire/')
                ? '/<x-filament[\w:-]*|@livewire\(|filament::|wire:[a-z]+|x-data=/'
                : '/<x-filament[\w:-]*|filament::/';

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line, $matches) === 1) {
                    $views[] = ['file' => $file->getPathname(), 'line' => $index + 1, 'what' => trim($matches[0])];

                    break;
                }
            }
        }

        return $views;
    }

    private function readComposer(): void
    {
        $composer = $this->json($this->basePath.'/composer.json');

        foreach ((array) ($composer['require'] ?? []) as $package => $constraint) {
            if (self::isFilamentPackage((string) $package)) {
                $this->packages[(string) $package] = (string) $constraint;
            }
        }

        foreach ((array) ($composer['require-dev'] ?? []) as $package => $constraint) {
            if (self::isFilamentPackage((string) $package)) {
                $this->devPackages[(string) $package] = (string) $constraint;
            }
        }

        foreach ((array) ($composer['scripts'] ?? []) as $script => $commands) {
            foreach ((array) $commands as $command) {
                if (is_string($command) && preg_match('/\b(filament:[a-z:-]+|make:filament-[a-z-]+)/', $command, $matches) === 1) {
                    $this->composerScripts[] = ['script' => (string) $script, 'command' => $matches[1]];
                }
            }
        }
    }

    public static function isFilamentPackage(string $package): bool
    {
        return str_starts_with($package, 'filament/') || str_contains(strtolower($package), 'filament') || isset(FilamentClassMap::pluginPackages()[$package]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        if (! $this->files->exists($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
