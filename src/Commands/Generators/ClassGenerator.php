<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Generators;

use Illuminate\Filesystem\Filesystem;

/**
 * Turns a stub into the contents of a generated file for every `make:alfakit-*` command.
 * Bind a subclass in a service provider to change what the generators write, for every
 * file or per stub:
 *
 * ```php
 * $this->app->bind(ClassGenerator::class, AppClassGenerator::class);
 *
 * class AppClassGenerator extends ClassGenerator
 * {
 *     protected function modify(string $stub, string $contents, array $replacements): string
 *     {
 *         return $stub === 'resource'
 *             ? str_replace('class ', "#[\App\Attributes\Audited]\nclass ", $contents)
 *             : $contents;
 *     }
 * }
 * ```
 *
 * Published stubs (`php artisan vendor:publish --tag=alfakit-stubs`, in `stubs/alfakit/`)
 * still win over the package stubs; `modify()` runs after the placeholders are replaced.
 */
class ClassGenerator
{
    public function __construct(protected Filesystem $filesystem) {}

    /**
     * The generated contents of `$stub` (`resource`, `pages/edit`…).
     *
     * @param  array<string, string>  $replacements  `{{ key }}` placeholders and their values
     */
    public function generate(string $stub, array $replacements): string
    {
        $contents = $this->filesystem->get($this->stubPath($stub));

        foreach ($replacements as $key => $value) {
            $contents = str_replace("{{ {$key} }}", $value, $contents);
        }

        return $this->modify($stub, $contents, $replacements);
    }

    /**
     * The stub file: the app's published copy, or the package's.
     */
    public function stubPath(string $stub): string
    {
        $published = base_path("stubs/alfakit/{$stub}.stub");

        return file_exists($published) ? $published : dirname(__DIR__, 3)."/stubs/{$stub}.stub";
    }

    /**
     * Change the generated contents: add imports, attributes, properties or methods.
     *
     * @param  array<string, string>  $replacements
     */
    protected function modify(string $stub, string $contents, array $replacements): string
    {
        return $contents;
    }
}
