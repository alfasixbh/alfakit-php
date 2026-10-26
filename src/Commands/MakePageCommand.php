<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use AlfaSix\Alfakit\Commands\Concerns\GeneratesStandaloneComponents;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-page', description: 'Create a custom Alfakit page')]
class MakePageCommand extends Command
{
    use GeneratesFrontendComponents;
    use GeneratesStandaloneComponents;

    protected $signature = 'make:alfakit-page {name? : The page class name}
        {--resource= : Attach the page to a resource}
        {--type= : custom (default), ViewRecord, EditRecord or ManageRelatedRecords (with --resource)}
        {--relationship= : The relationship, for --type=ManageRelatedRecords}
        {--record-title-attribute= : The related record title attribute, for --type=ManageRelatedRecords}
        {--component : Render your own frontend component (an app page with #[Expose] methods)}
        {--adapter= : react, vue or svelte (detected from package.json)}
        {--fullscreen : Use the fullscreen layout (no sidebar or topbar)}
        {--no-frontend : With --component or --standalone, write only the PHP class}
        {--standalone : A page of your own app outside panels: a standalone component plus the Inertia page that renders it}
        {--model= : With --standalone, a model the component works with}
        {--panel=}
        {--force}';

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Page name', 'Settings', required: true)));

        if ($this->option('standalone')) {
            return $this->generateStandalonePage($name);
        }
        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix($panelId);
        $force = (bool) $this->option('force');
        $isApp = (bool) $this->option('component');
        $component = "Pages/{$name}";
        $layout = $this->option('fullscreen') ? "\n    protected static string \$layout = 'fullscreen';\n" : '';

        $type = $this->pageType();

        if ($type === null) {
            return self::FAILURE;
        }

        if ($type !== 'custom' && ! $this->option('resource')) {
            $this->components->error("--type={$type} needs --resource=.");

            return self::FAILURE;
        }

        if ($resource = $this->option('resource')) {
            $resource = Str::studly($resource);
            $resource = Str::endsWith($resource, 'Resource') ? $resource : "{$resource}Resource";

            if ($type !== 'custom') {
                return $this->generateRecordPage($type, $name, $resource, $suffix, $force);
            }
            $namespace = $this->getGenerationNamespace().$suffix."\\Resources\\{$resource}\\Pages";
            $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Resources/{$resource}/Pages/{$name}.php";

            $this->writeStub($isApp ? 'pages/resource-app' : 'pages/resource-custom', $path, [
                'namespace' => $namespace,
                'class' => $name,
                'resource' => $resource,
                'resourceNamespace' => $this->getGenerationNamespace().$suffix.'\\Resources',
                'slug' => Str::kebab($name),
                'component' => $component,
                'layout' => $layout,
            ], $force);

            $this->components->info("Register it in {$resource}::getPages(): '".Str::kebab($name)."' => Pages\\{$name}::route('/".Str::kebab($name)."'),");
        } else {
            $namespace = $this->getGenerationNamespace().$suffix.'\\Pages';
            $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Pages/{$name}.php";

            $this->writeStub($isApp ? 'pages/app' : 'page', $path, [
                'namespace' => $namespace,
                'class' => $name,
                'title' => Str::headline($name),
                'component' => $component,
                'layout' => $layout,
            ], $force);
        }

        if (! $isApp) {
            return self::SUCCESS;
        }

        if ($this->option('no-frontend')) {
            $this->components->info("Register a component for it under '{$component}' in createAlfakit({ pages }).");

            return self::SUCCESS;
        }

        return $this->generatePageComponent($name, $component, $namespace.'\\'.$name, $force);
    }

    /**
     * `--type`, normalised: `custom`, `view`, `edit` or `related`; null (with an error) for
     * anything else.
     */
    protected function pageType(): ?string
    {
        $type = Str::lower(Str::kebab((string) ($this->option('type') ?? 'custom')));

        $normalized = match ($type) {
            '', 'custom', 'page' => 'custom',
            'view', 'view-record' => 'view',
            'edit', 'edit-record' => 'edit',
            'manage-related-records', 'related', 'related-records' => 'related',
            default => null,
        };

        if ($normalized === null) {
            $this->components->error("Unknown page type [{$this->option('type')}]: use custom, ViewRecord, EditRecord or ManageRelatedRecords.");
        }

        return $normalized;
    }

    /**
     * Another View or Edit page for a resource record, or a ManageRelatedRecords page.
     */
    protected function generateRecordPage(string $type, string $name, string $resource, string $suffix, bool $force): int
    {
        if ($type === 'related') {
            $relationship = (string) ($this->option('relationship') ?? text('Relationship name (e.g. comments)', required: true));

            return $this->call('make:alfakit-related-records-page', array_filter([
                'resource' => $resource,
                'relationship' => $relationship,
                'recordTitleAttribute' => $this->option('record-title-attribute'),
                '--panel' => $this->option('panel'),
                '--force' => $force,
            ]));
        }

        $slug = Str::kebab($name);
        $namespace = $this->getGenerationNamespace().$suffix."\\Resources\\{$resource}\\Pages";
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Resources/{$resource}/Pages/{$name}.php";

        $this->writeStub($type === 'view' ? 'pages/record-view' : 'pages/record-edit', $path, [
            'namespace' => $namespace,
            'class' => $name,
            'resource' => $resource,
            'resourceNamespace' => $this->getGenerationNamespace().$suffix.'\\Resources',
            'slug' => $slug,
        ], $force);

        $this->components->info("Register it in {$resource}::getPages(): '{$slug}' => Pages\\{$name}::route('/{record}/{$slug}'),");

        return self::SUCCESS;
    }

    /**
     * The frontend component of an app page in `resources/js/alfakit/pages`: auto-registered,
     * or registered under `pages` in the app and SSR entries when they don't `discover`.
     */
    protected function generatePageComponent(string $name, string $component, string $phpClass, bool $force): int
    {
        $adapter = $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        // `pages/Settings.tsx` auto-registers as `Pages/Settings`, this page's `$component`.
        $this->writeFrontendComponent($adapter, 'app-page', $name, $phpClass, $component, ['title' => Str::headline($name)], $force, 'pages');

        return self::SUCCESS;
    }

    /**
     * `--standalone`: a `Standalone\Component` in `app/Alfakit/Standalone` and the app's
     * Inertia page that renders it (`resources/js/pages`), plus the route to add.
     */
    protected function generateStandalonePage(string $name): int
    {
        $status = $this->generateStandaloneComponent('component', $name);

        if ($status !== self::SUCCESS || $this->option('no-frontend')) {
            return $status;
        }

        $adapter = $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        // The starter kits' conventions: `pages/orders.tsx`, `pages/Orders.vue`, `pages/Orders.svelte`.
        $page = $adapter === 'react' ? Str::kebab($name) : $name;
        $prop = Str::camel($name);
        $extension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$adapter];
        $class = $this->getGenerationNamespace().'\\Standalone\\'.$name;

        $this->writeStub("standalone/pages/page-{$adapter}", resource_path("js/pages/{$page}.{$extension}"), [
            'class' => $name,
            'component' => $name,
            'prop' => $prop,
            'title' => Str::headline($name),
        ], (bool) $this->option('force'));

        $model = $this->option('model');
        $model = is_string($model) && $model !== '' ? Str::studly($model) : null;
        $route = '/'.Str::kebab($name);

        if ($model === null) {
            $this->components->info("Add a route: Route::get('{$route}', fn () => \\AlfaSix\\Alfakit\\Facades\\Alfakit::render('{$page}', ['{$prop}' => \\{$class}::class]));");
        } else {
            // The component has a `public {Model} ${model}` to fill: bind it from the route (W-S3-08).
            $modelClass = str_contains($model, '\\') ? $model : 'App\\Models\\'.$model;
            $variable = Str::camel(class_basename($modelClass));

            $this->components->info("Add a route: Route::get('{$route}/{{$variable}}', fn (\\{$modelClass} \${$variable}) => \\AlfaSix\\Alfakit\\Facades\\Alfakit::render('{$page}', ['{$prop}' => [\\{$class}::class, ['{$variable}' => \${$variable}]]]));");
        }

        return self::SUCCESS;
    }
}
