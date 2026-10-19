<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-cluster', description: 'Create an Alfakit cluster that groups pages and resources')]
class MakeClusterCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-cluster {name? : The cluster class name} {--panel=} {--force}';

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Cluster name', 'Settings', required: true)));
        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix($panelId);
        $namespace = $this->getGenerationNamespace().$suffix.'\\Clusters';
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Clusters/{$name}.php";

        $this->writeStub('cluster', $path, [
            'namespace' => $namespace,
            'class' => $name,
            'label' => Str::headline(Str::beforeLast($name, 'Cluster') ?: $name),
        ], (bool) $this->option('force'));

        $directory = Str::after(dirname($path), app_path().'/');

        if ($panelId !== null && $this->registerOnPanel($panelId, $directory, $namespace)) {
            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            "Register it on the panel: ->discoverClusters(in: app_path('%s'), for: '%s') or ->pages([\\%s\\%s::class])",
            $directory,
            $namespace,
            $namespace,
            $name,
        ));

        return self::SUCCESS;
    }

    /**
     * Add `->discoverClusters(...)` for the clusters directory to the panel provider, unless
     * it already discovers it. False when the provider can't be edited (the caller prints
     * what to add), or when it discovers clusters somewhere else.
     */
    protected function registerOnPanel(string $panelId, string $directory, string $namespace): bool
    {
        $provider = PanelProviderEditor::path($panelId);

        if (! is_file($provider)) {
            return false;
        }

        $contents = (string) file_get_contents($provider);
        $for = str_replace('\\', '\\\\', $namespace);

        if (str_contains($contents, "'{$for}'") || str_contains($contents, "'{$namespace}'")) {
            $this->components->info('The panel already discovers this cluster.');

            return true;
        }

        if (PanelProviderEditor::hasCall($contents, 'discoverClusters')) {
            return false;
        }

        $updated = PanelProviderEditor::addCall($contents, "discoverClusters(in: app_path('{$directory}'), for: '{$for}')");

        if ($updated === null) {
            return false;
        }

        file_put_contents($provider, $updated);
        $this->components->info('Registered the clusters directory on the panel: ->discoverClusters(in: app_path(\''.$directory.'\'), …).');

        return true;
    }
}
