<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
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

        $this->components->info(sprintf(
            "Register it on the panel: ->discoverClusters(in: app_path('%s'), for: '%s') or ->pages([\\%s\\%s::class])",
            Str::after(dirname($path), app_path().'/'),
            $namespace,
            $namespace,
            $name,
        ));

        return self::SUCCESS;
    }
}
