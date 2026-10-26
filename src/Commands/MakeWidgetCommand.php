<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
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

        $adapter = null;

        if ($type === 'custom' && ! $this->option('no-frontend')) {
            $adapter = $this->resolveAdapter();

            if ($adapter === null) {
                return self::FAILURE;
            }

            $this->writeFrontendComponent($adapter, 'widget', $name, $namespace.'\\'.$name, $name, ['heading' => Str::headline($name)], (bool) $this->option('force'));
        }

        if ($resource !== null) {
            $this->components->info("List it in {$resource}::getWidgets() and return it from a page's getHeaderWidgets() or getFooterWidgets(): Widgets\\{$name}::class. On record pages it receives the \$record.");
        } elseif (($hint = $this->starterKitDashboardHint($panelId, $adapter === null ? null : $name, $adapter)) !== null) {
            $this->components->info($hint);
        }

        return self::SUCCESS;
    }

    /**
     * `starterKit()` panels have no dashboard of their own: their widgets appear on the
     * host's dashboard through `<AlfakitWidgets />`. A custom widget's frontend component
     * (`$component`) is only auto-registered for AlfaKit pages, so the host's dashboard page
     * must import it and pass it to `<AlfakitWidgets components>` (W-S3-04).
     */
    protected function starterKitDashboardHint(?string $panelId, ?string $component = null, ?string $adapter = null): ?string
    {
        $panel = $panelId === null ? null : (alfakit()->getPanels()[$panelId] ?? null);

        if ($panel === null || ! $panel->isStarterKit()) {
            return null;
        }

        $routes = $panel->getStarterKitDashboardRoutes();

        if ($routes === null) {
            return 'This panel shows no widgets on your app\'s pages (starterKitDashboard(false)): return the widget from a page\'s getHeaderWidgets() or getFooterWidgets().';
        }

        $route = $routes[0];
        $where = ! str_contains($route, '*') && Route::has($route) ? parse_url(route($route), PHP_URL_PATH) : null;
        $where = is_string($where) && $where !== '' ? $where : "the {$route} route";

        $hint = "It appears on your app's dashboard ({$where}) through <AlfakitWidgets /> (php artisan alfakit:install --starter-kit adds it to the dashboard page). Sort it with \$sort and size it with \$columnSpan.";

        if ($component === null || $adapter === null) {
            return $hint;
        }

        $dashboard = StarterKitInstaller::files($adapter)['dashboard'];
        $import = $adapter === 'react'
            ? "import {$component} from '@/alfakit/widgets/{$component}'"
            : "import {$component} from '@/alfakit/widgets/{$component}.".($adapter === 'vue' ? 'vue' : 'svelte')."'";
        $tag = $adapter === 'vue' ? "<AlfakitWidgets :components=\"{ {$component} }\">" : "<AlfakitWidgets components={{ {$component} }}>";

        return $hint." Your dashboard page doesn't load resources/js/alfakit, so pass the component there: add `{$import}` to {$dashboard} and render {$tag}.";
    }
}
