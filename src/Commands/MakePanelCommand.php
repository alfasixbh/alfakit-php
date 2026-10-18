<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-panel', description: 'Create a new Alfakit panel provider')]
class MakePanelCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-panel {id? : The panel id (e.g. admin)} {--path= : URL path, defaults to the id} {--force}';

    public function handle(Filesystem $filesystem): int
    {
        $id = Str::lower((string) ($this->argument('id') ?? text('Panel id', 'admin', 'admin', required: true)));
        $path = (string) ($this->option('path') ?? $id);
        $class = Str::studly($id).'PanelProvider';
        $providerPath = app_path("Providers/Alfakit/{$class}.php");

        $written = $this->writeStub('panel-provider', $providerPath, [
            'class' => $class,
            'id' => $id,
            'path' => $path,
            'namespaceSuffix' => $this->panelNamespaceSuffix($id),
            'directorySuffix' => $this->panelNamespaceSuffix($id) === '' ? '' : '/'.Str::studly($id),
        ], (bool) $this->option('force'));

        if ($written) {
            $this->registerProvider($filesystem, "App\\Providers\\Alfakit\\{$class}");
        }

        return self::SUCCESS;
    }

    protected function registerProvider(Filesystem $filesystem, string $provider): void
    {
        $providersPath = base_path('bootstrap/providers.php');

        if (! $filesystem->exists($providersPath)) {
            $this->components->warn("Register {$provider} in your providers list.");

            return;
        }

        $contents = $filesystem->get($providersPath);

        if (str_contains($contents, $provider)) {
            return;
        }

        $updated = preg_replace('/\];\s*$/', "    {$provider}::class,\n];\n", $contents, 1);

        if ($updated !== null) {
            $filesystem->put($providersPath, $updated);
            $this->components->task("Registered {$provider} in bootstrap/providers.php");
        }
    }
}
