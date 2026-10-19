<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\Generators\ClassGenerator;
use AlfaSix\Alfakit\Commands\Support\GeneratedCode;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

trait CanGenerateFiles
{
    protected function stubPath(string $name): string
    {
        return app(ClassGenerator::class)->stubPath($name);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function writeStub(string $stub, string $path, array $replacements, bool $force = false): bool
    {
        $filesystem = app(Filesystem::class);

        if ($filesystem->exists($path) && ! $force) {
            $this->components->warn(sprintf('%s already exists, skipping. Use --force to overwrite.', Str::after($path, base_path().'/')));

            return false;
        }

        $filesystem->ensureDirectoryExists(dirname($path));

        // Replaceable through the container (`ClassGenerator` docs).
        $contents = app(ClassGenerator::class)->generate($stub, $replacements);

        // Sorted, used imports, so generated classes pass Pint's Laravel preset in the app.
        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            $contents = GeneratedCode::tidyImports($contents);
        }

        $filesystem->put($path, $contents);

        // Frontend files take the app's own formatter (`vp fmt`, Oxfmt or Prettier) when it has one.
        \AlfaSix\Alfakit\Commands\Support\FrontendFormatter::format([$path]);

        $this->components->info(sprintf('Created %s', Str::after($path, base_path().'/')));

        return true;
    }

    protected function getGenerationNamespace(): string
    {
        return rtrim((string) config('alfakit.file_generation.namespace', 'App\\Alfakit'), '\\');
    }

    protected function getGenerationPath(): string
    {
        return rtrim((string) config('alfakit.file_generation.path', app_path('Alfakit')), '/');
    }

    /**
     * The namespace segment of a panel's classes (`''` for `App\\Alfakit\\Resources`,
     * `'\\Shop'` for `App\\Alfakit\\Shop\\Resources`): taken from where the panel discovers its
     * resources, so adding a panel never moves an existing panel's generated classes. A
     * panel that isn't registered yet (or doesn't discover resources) gets its own segment
     * when the app already has another panel.
     */
    protected function panelNamespaceSuffix(?string $panelId): string
    {
        if ($panelId === null) {
            return '';
        }

        $base = $this->getGenerationNamespace();
        $panels = alfakit()->getPanels();
        $panel = $panels[$panelId] ?? null;

        foreach ($panel?->getResourceDiscovery() ?? [] as $discovery) {
            $namespace = trim($discovery['namespace'], '\\');

            if ($namespace === $base.'\\Resources') {
                return '';
            }

            if (str_starts_with($namespace, $base.'\\') && str_ends_with($namespace, '\\Resources')) {
                return '\\'.Str::beforeLast(Str::after($namespace, $base.'\\'), '\\Resources');
            }
        }

        $others = array_filter(array_keys($panels), static fn (int|string $id): bool => (string) $id !== $panelId);

        return $others === [] ? '' : '\\'.Str::studly($panelId);
    }
}
