<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use AlfaSix\Alfakit\Theming\Themes;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-theme', description: 'Create a custom Alfakit theme: a Theme class, its CSS, and the panel registration', aliases: ['alfakit:make-theme'])]
class MakeThemeCommand extends Command
{
    use CanGenerateFiles;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-theme {name? : The theme name (e.g. acme)} {--extends= : The built-in or registered theme it builds on} {--panel= : Use the theme on this panel} {--pm= : Build the assets with npm, pnpm, yarn or bun after generating} {--force}';

    /** @var array<int, string> */
    protected $aliases = ['alfakit:make-theme'];

    public function handle(Filesystem $filesystem): int
    {
        $name = Str::kebab((string) ($this->argument('name') ?? text('Theme name', 'acme', required: true)));
        $name = (string) preg_replace('/-theme$/', '', $name);
        $extends = (string) ($this->option('extends') ?? ($this->input->isInteractive()
            ? select('Build on which theme?', array_map(static fn ($theme): string => $theme->getLabel(), Themes::all()), Themes::DEFAULT)
            : Themes::DEFAULT));

        if (! Themes::has($extends)) {
            $this->components->error("Theme [{$extends}] is not registered. Available: ".implode(', ', array_keys(Themes::all())).'.');

            return self::FAILURE;
        }

        if ($name === '' || in_array($name, ['neutral', 'alfakit', 'minimal', 'soft', 'corporate', 'filament'], true)) {
            $this->components->error("[{$name}] is a built-in theme name. Choose another one.");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $class = Str::studly($name).'Theme';
        $namespace = $this->getGenerationNamespace().'\\Themes';
        $label = Str::headline($name);

        $this->writeStub('theme/theme', $this->getGenerationPath()."/Themes/{$class}.php", [
            'namespace' => $namespace,
            'class' => $class,
            'name' => $name,
            'label' => $label,
            'extends' => $extends,
        ], $force);

        $cssPath = resource_path("css/alfakit/themes/{$name}.css");

        $this->writeStub('theme/css', $cssPath, ['name' => $name, 'label' => $label, 'extends' => $extends], $force);

        $this->importStylesheet($filesystem, "./alfakit/themes/{$name}.css");
        $this->registerOnPanel($filesystem, "\\{$namespace}\\{$class}");

        return $this->buildAssets();
    }

    /**
     * `--pm=pnpm` runs `pnpm run build`; without it the command prints the build step for
     * the package manager the app uses (detected from its lockfile).
     */
    protected function buildAssets(): int
    {
        $pm = $this->option('pm');

        if (! is_string($pm) || $pm === '') {
            $this->components->info('Rebuild your assets to see the theme: '.self::detectPackageManager().' run build');

            return self::SUCCESS;
        }

        if (! in_array($pm, ['npm', 'pnpm', 'yarn', 'bun'], true)) {
            $this->components->error("Unknown package manager [{$pm}]: use npm, pnpm, yarn or bun.");

            return self::FAILURE;
        }

        $result = Process::path(base_path())->timeout(600)->run([$pm, 'run', 'build'], function (string $type, string $output): void {
            $this->output->write($output);
        });

        if ($result->failed()) {
            $this->components->error("{$pm} run build failed; fix the error above and run it again.");

            return self::FAILURE;
        }

        $this->components->info('Built the assets with '.$pm.'.');

        return self::SUCCESS;
    }

    public static function detectPackageManager(?string $path = null): string
    {
        $path ??= base_path();

        return match (true) {
            is_file($path.'/pnpm-lock.yaml') => 'pnpm',
            is_file($path.'/yarn.lock') => 'yarn',
            is_file($path.'/bun.lockb') || is_file($path.'/bun.lock') => 'bun',
            default => 'npm',
        };
    }

    /**
     * Import the theme CSS into resources/css/alfakit.css, after its other imports.
     */
    protected function importStylesheet(Filesystem $filesystem, string $import): void
    {
        $stylesheet = resource_path('css/alfakit.css');
        $line = "@import '{$import}';";

        if (! $filesystem->exists($stylesheet)) {
            $this->components->warn("Add {$line} to the stylesheet your panel loads.");

            return;
        }

        $contents = $filesystem->get($stylesheet);

        if (str_contains($contents, $import)) {
            return;
        }

        $updated = preg_match_all('/^@import [^;]+;[ \t]*$/m', $contents, $matches, PREG_OFFSET_CAPTURE) > 0
            ? substr_replace($contents, "\n{$line}", (int) end($matches[0])[1] + strlen(end($matches[0])[0]), 0)
            : "{$line}\n{$contents}";

        $filesystem->put($stylesheet, $updated);
        $this->components->task('Imported the theme in resources/css/alfakit.css');
    }

    /**
     * Use the theme on the panel (`->theme(AcmeTheme::make())`, which also registers it).
     */
    protected function registerOnPanel(Filesystem $filesystem, string $class): void
    {
        $snippet = "->theme({$class}::make())";
        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $provider = $panelId === null ? null : app_path('Providers/Alfakit/'.Str::studly($panelId).'PanelProvider.php');

        if ($provider === null || ! $filesystem->exists($provider)) {
            $this->components->warn("Use the theme on a panel: \$panel{$snippet}");

            return;
        }

        $contents = $filesystem->get($provider);

        if (str_contains($contents, $snippet)) {
            return;
        }

        if (str_contains($contents, '->theme(')) {
            $this->components->warn("The panel already sets a theme. Replace it with {$snippet} in ".Str::after($provider, base_path().'/'));

            return;
        }

        $updated = preg_replace('/return \$panel\b/', "return \$panel\n            {$snippet}", $contents, 1, $count);

        if (! is_string($updated) || $count !== 1) {
            $this->components->warn("Use the theme on the panel: \$panel{$snippet}");

            return;
        }

        $filesystem->put($provider, $updated);
        $this->components->task('Set the theme on '.Str::after($provider, base_path().'/'));
    }
}
