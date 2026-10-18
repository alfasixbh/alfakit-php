<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use AlfaSix\Alfakit\Support\VersionConstraint;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * A Composer package skeleton for an Alfakit plugin: the Plugin class, a service
 * provider that registers its translations and assets, composer.json and a README,
 * plus (optionally) a frontend package exporting renderer components.
 */
#[AsCommand(name: 'make:alfakit-plugin', description: 'Create an Alfakit plugin package skeleton', aliases: ['alfakit:make-plugin'])]
class MakePluginCommand extends Command
{
    use CanGenerateFiles;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-plugin {name? : The plugin name (e.g. Blog)} {--vendor= : Composer vendor (e.g. acme)} {--path=packages : Directory the package is created in} {--frontend= : react, vue, svelte or none} {--force}';

    /** @var array<int, string> */
    protected $aliases = ['alfakit:make-plugin'];

    public function handle(Filesystem $filesystem): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Plugin name', 'Blog', required: true)));
        $vendor = Str::kebab((string) ($this->option('vendor') ?? text('Composer vendor', 'acme', 'acme', required: true)));
        $frontend = (string) ($this->option('frontend') ?? ($this->input->isInteractive()
            ? select('Frontend components', ['none' => 'None (PHP only)', 'react' => 'React', 'vue' => 'Vue', 'svelte' => 'Svelte'], 'none')
            : 'none'));

        if (! in_array($frontend, ['none', 'react', 'vue', 'svelte'], true)) {
            $this->components->error("Unknown frontend [{$frontend}]. Use react, vue, svelte or none.");

            return self::FAILURE;
        }

        $id = Str::kebab($name);
        $package = "{$vendor}/{$id}";
        $namespace = Str::studly($vendor).'\\'.$name;
        $pluginClass = "{$name}Plugin";
        $providerClass = "{$name}ServiceProvider";
        $directory = base_path(trim((string) $this->option('path'), '/')."/{$id}");
        $force = (bool) $this->option('force');
        $title = Str::headline($name);
        $npmPackage = "@{$vendor}/{$id}";

        $this->writeStub('plugin/plugin', "{$directory}/src/{$pluginClass}.php", ['namespace' => $namespace, 'class' => $pluginClass, 'id' => $id], $force);
        $this->writeStub('plugin/service-provider', "{$directory}/src/{$providerClass}.php", ['namespace' => $namespace, 'class' => $providerClass, 'id' => $id, 'package' => $package], $force);
        $this->writeStub('plugin/composer', "{$directory}/composer.json", [
            'package' => $package,
            'title' => $title,
            'composerNamespace' => str_replace('\\', '\\\\', $namespace),
            'provider' => $providerClass,
            'alfakitConstraint' => $this->alfakitConstraint(),
            'frontendPackage' => $frontend === 'none' ? 'null' : (string) json_encode($npmPackage),
        ], $force);
        $this->writeStub('plugin/css', "{$directory}/resources/dist/{$id}.css", ['title' => $title], $force);
        $this->writeStub('plugin/lang', "{$directory}/resources/lang/en/{$id}.php", ['id' => $id, 'title' => $title], $force);

        $frontendReadme = '';

        if ($frontend !== 'none') {
            $extension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$frontend];
            $component = $frontend === 'react' ? 'example' : 'Example';

            $this->writeStub('plugin/package', "{$directory}/package.json", ['npmPackage' => $npmPackage, 'adapter' => $frontend], $force);
            $this->writeStub("plugin/{$frontend}/index", "{$directory}/resources/js/index.ts", ['id' => $id], $force);
            $this->writeStub("plugin/{$frontend}/example", "{$directory}/resources/js/components/{$component}.{$extension}", ['id' => $id], $force);

            $frontendReadme = $this->renderStub('plugin/frontend-readme', [
                'id' => $id,
                'npmPackage' => $npmPackage,
                'jsName' => Str::camel($name),
            ]);
        }

        $this->writeStub('plugin/readme', "{$directory}/README.md", [
            'title' => $title,
            'package' => $package,
            'namespace' => $namespace,
            'pluginClass' => $pluginClass,
            'frontendReadme' => $frontendReadme,
        ], $force);

        $relative = Str::after($directory, base_path().'/');

        $this->components->info("Created the {$package} plugin in {$relative}.");
        $this->components->bulletList(array_values(array_filter([
            "Add a path repository to composer.json: {\"type\": \"path\", \"url\": \"{$relative}\"}",
            "composer require {$package}:@dev",
            "Register it on a panel: ->plugin(\\{$namespace}\\{$pluginClass}::make())",
            $frontend !== 'none' ? "Add \"{$npmPackage}\": \"file:{$relative}\" to package.json and spread its components into createAlfakit()" : null,
        ])));

        return self::SUCCESS;
    }

    /**
     * The `extra.alfakit.version` constraint for the installed Alfakit release: `^1.2` for
     * 1.2.x, `^0.3` for 0.3.x, or `*` when Alfakit is a development version.
     */
    protected function alfakitConstraint(): string
    {
        $version = InstalledVersions::isInstalled('alfasix/alfakit') ? InstalledVersions::getPrettyVersion('alfasix/alfakit') : null;
        $parsed = $version === null ? null : VersionConstraint::parse($version);

        return $parsed === null ? '*' : "^{$parsed[0]}.{$parsed[1]}";
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function renderStub(string $stub, array $replacements): string
    {
        $contents = (string) file_get_contents($this->stubPath($stub));

        foreach ($replacements as $key => $value) {
            $contents = str_replace("{{ {$key} }}", $value, $contents);
        }

        return $contents;
    }
}
