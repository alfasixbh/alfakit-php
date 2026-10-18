<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-api-resource', description: 'Create an Alfakit resource backed by an API or other data source')]
class MakeApiResourceCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-api-resource
        {name? : The record name (e.g. Supplier)}
        {--url= : Base URL of a REST API (generates an HttpDataSource)}
        {--panel= : The panel id}
        {--force}';

    public function handle(): int
    {
        $model = (string) ($this->argument('name') ?? text('Record name', 'Supplier', required: true));
        $model = Str::studly(Str::beforeLast(class_basename(str_replace('/', '\\', $model)), 'Resource') ?: $model);

        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix($panelId);
        $namespace = $this->getGenerationNamespace().$suffix.'\\Resources';
        $basePath = $this->getGenerationPath().str_replace('\\', '/', $suffix).'/Resources';

        $resource = "{$model}Resource";
        $plural = Str::pluralStudly($model);
        $dataSource = "{$model}DataSource";
        $url = $this->option('url');
        $force = (bool) $this->option('force');

        $this->writeStub('api/resource', "{$basePath}/{$resource}.php", [
            'namespace' => $namespace,
            'resource' => $resource,
            'model' => $model,
            'plural' => $plural,
            'dataSource' => $dataSource,
        ], $force);

        $this->writeStub(filled($url) ? 'api/data-source-http' : 'api/data-source', "{$basePath}/{$resource}/{$dataSource}.php", [
            'namespace' => "{$namespace}\\{$resource}",
            'dataSource' => $dataSource,
            'plural' => Str::headline($plural),
            'url' => rtrim((string) $url, '/'),
            'endpoint' => Str::kebab($plural),
        ], $force);

        $common = ['namespace' => "{$namespace}\\{$resource}\\Pages", 'resourceNamespace' => $namespace, 'resource' => $resource, 'model' => $model, 'plural' => $plural];
        $pagesPath = "{$basePath}/{$resource}/Pages";

        $this->writeStub('api/list', "{$pagesPath}/List{$plural}.php", $common, $force);
        $this->writeStub('api/create', "{$pagesPath}/Create{$model}.php", $common, $force);
        $this->writeStub('api/edit', "{$pagesPath}/Edit{$model}.php", $common, $force);
        $this->writeStub('api/view', "{$pagesPath}/View{$model}.php", $common, $force);

        return self::SUCCESS;
    }
}
