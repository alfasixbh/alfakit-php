<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-widget', description: 'Create an Alfakit dashboard widget')]
class MakeWidgetCommand extends Command
{
    use GeneratesFrontendComponents;

    protected $signature = 'make:alfakit-widget {name?} {--type= : stats, chart, table or custom} {--resource= : Generate the widget inside a resource (PostResource)} {--panel=} {--adapter= : For custom widgets: react, vue or svelte (detected from package.json)} {--no-frontend : For custom widgets: only create the PHP class} {--force}';

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Widget name', 'StatsOverview', required: true)));
        $type = $this->option('type') ?? select('Widget type', ['stats' => 'Stats overview', 'chart' => 'Chart', 'table' => 'Table', 'custom' => 'Custom (your own frontend component)'], 'stats');

        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix($panelId);
        $resource = $this->option('resource');
        $resource = is_string($resource) && $resource !== '' ? Str::studly($resource) : null;
        $resource = $resource === null || Str::endsWith($resource, 'Resource') ? $resource : "{$resource}Resource";

        $namespace = $this->getGenerationNamespace().$suffix.($resource === null ? '\\Widgets' : "\\Resources\\{$resource}\\Widgets");
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix).($resource === null ? '' : "/Resources/{$resource}")."/Widgets/{$name}.php";

        $this->writeStub("widgets/{$type}", $path, [
            'namespace' => $namespace,
            'class' => $name,
            'heading' => Str::headline($name),
            // `widgets/RevenueChart.tsx` auto-registers under the class name.
            'component' => $name,
        ], (bool) $this->option('force'));

        if ($type === 'custom' && ! $this->option('no-frontend')) {
            $adapter = $this->resolveAdapter();

            if ($adapter === null) {
                return self::FAILURE;
            }

            $this->writeFrontendComponent($adapter, 'widget', $name, $namespace.'\\'.$name, $name, ['heading' => Str::headline($name)], (bool) $this->option('force'));
        }

        if ($resource !== null) {
            $this->components->info("List it in {$resource}::getWidgets() and return it from a page's getHeaderWidgets() or getFooterWidgets(): Widgets\\{$name}::class. On record pages it receives the \$record.");
        }

        return self::SUCCESS;
    }
}
