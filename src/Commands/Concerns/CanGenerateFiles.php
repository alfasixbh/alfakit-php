<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\Generators\ClassGenerator;
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
        $filesystem->put($path, app(ClassGenerator::class)->generate($stub, $replacements));

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

    protected function panelNamespaceSuffix(?string $panelId): string
    {
        $panels = alfakit()->getPanels();

        if ($panelId === null || count($panels) <= 1) {
            return '';
        }

        return '\\'.Str::studly($panelId);
    }
}
