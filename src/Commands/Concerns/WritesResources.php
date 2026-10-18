<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use Illuminate\Support\Str;

/**
 * Writes a resource class and its pages from the generator stubs, for
 * `make:alfakit-resource` and `alfakit:generate`.
 */
trait WritesResources
{
    /** @var list<string> files the last `writeResource()` call created or overwrote */
    protected array $writtenResourceFiles = [];

    /**
     * @param  array<string, string>  $replacements
     */
    protected function writeResourceStub(string $stub, string $path, array $replacements, bool $force): void
    {
        if ($this->writeStub($stub, $path, $replacements, $force)) {
            $this->writtenResourceFiles[] = $path;
        }
    }

    /**
     * Write the resource class and its pages. `filters`, `infolist` and `relations` are the
     * code of the table filters, the `infolist()` method and the `getRelations()` entries
     * (`alfakit:generate` fills them; `make:alfakit-resource` leaves them empty).
     *
     * @param  array{formFields: string, tableColumns: string, imports: array<string>, softDeletes: bool, view: bool, simple: bool, force: bool, nested?: string|null, panel?: mixed, filters?: string, infolist?: string, relations?: string}  $options
     * @return string the path of the resource class
     */
    protected function writeResource(string $model, string $modelClass, array $options): string
    {
        $panelId = $options['panel'] ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix(is_string($panelId) ? $panelId : null);
        $namespace = $this->getGenerationNamespace().$suffix.'\\Resources';
        $basePath = $this->getGenerationPath().str_replace('\\', '/', $suffix).'/Resources';

        $this->writtenResourceFiles = [];
        $resource = "{$model}Resource";
        $plural = Str::pluralStudly($model);
        $force = $options['force'];
        $softDeletes = $options['softDeletes'];
        $view = $options['view'];
        $simple = $options['simple'];
        $imports = $options['imports'];

        $this->writeResourceStub($simple ? 'resource-simple' : 'resource', "{$basePath}/{$resource}.php", [
            'namespace' => $namespace,
            'resource' => $resource,
            'model' => $model,
            'modelClass' => $modelClass,
            'plural' => $plural,
            'formFields' => $options['formFields'],
            'tableColumns' => $options['tableColumns'],
            'imports' => implode("\n", array_map(static fn (string $i): string => "use {$i};", array_unique($imports))),
            'filters' => ($options['filters'] ?? '').($softDeletes ? "\n                TrashedFilter::make()," : ''),
            'filtersImport' => $softDeletes ? "use AlfaSix\\Alfakit\\Tables\\Filters\\TrashedFilter;\n" : '',
            'softDeletesActions' => $softDeletes ? "\n                RestoreAction::make(),\n                ForceDeleteAction::make()," : '',
            'softDeletesBulkActions' => $softDeletes ? "\n                RestoreBulkAction::make(),\n                ForceDeleteBulkAction::make()," : '',
            'softDeletesImports' => $softDeletes ? "use AlfaSix\\Alfakit\\Actions\\ForceDeleteAction;\nuse AlfaSix\\Alfakit\\Actions\\ForceDeleteBulkAction;\nuse AlfaSix\\Alfakit\\Actions\\RestoreAction;\nuse AlfaSix\\Alfakit\\Actions\\RestoreBulkAction;\nuse Illuminate\\Database\\Eloquent\\Builder;\nuse Illuminate\\Database\\Eloquent\\SoftDeletingScope;\n" : '',
            'softDeletesQuery' => $softDeletes ? "\n\n    /**\n     * @return Builder<{$model}>\n     */\n    public static function getEloquentQuery(): Builder\n    {\n        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);\n    }" : '',
            'viewAction' => $view ? "\n                ViewAction::make()," : '',
            'viewActionImport' => $view ? "use AlfaSix\\Alfakit\\Actions\\ViewAction;\n" : '',
            'viewPage' => $view ? "\n            'view' => Pages\\View{$model}::route('/{record}')," : '',
            'infolist' => $options['infolist'] ?? '',
            'relations' => $options['relations'] ?? '            //',
        ], $force);

        $pagesNamespace = "{$namespace}\\{$resource}\\Pages";
        $pagesPath = "{$basePath}/{$resource}/Pages";
        $common = ['namespace' => $pagesNamespace, 'resourceNamespace' => $namespace, 'resource' => $resource, 'model' => $model, 'plural' => $plural];

        if ($simple) {
            $this->writeResourceStub('pages/manage', "{$pagesPath}/Manage{$plural}.php", $common, $force);
        } else {
            $this->writeResourceStub('pages/list', "{$pagesPath}/List{$plural}.php", $common, $force);
            $this->writeResourceStub('pages/create', "{$pagesPath}/Create{$model}.php", $common, $force);
            $this->writeResourceStub('pages/edit', "{$pagesPath}/Edit{$model}.php", [...$common, 'viewActionImport' => $view ? "use AlfaSix\\Alfakit\\Actions\\ViewAction;\n" : '', 'viewAction' => $view ? "\n            ViewAction::make()," : ''], $force);

            if ($view) {
                $this->writeResourceStub('pages/view', "{$pagesPath}/View{$model}.php", $common, $force);
            }
        }

        if (($parent = $options['nested'] ?? null) !== null) {
            $this->nestResource("{$basePath}/{$resource}.php", $parent, $namespace);
        }

        return "{$basePath}/{$resource}.php";
    }

    /**
     * Point a generated resource at its parent resource (`--nested=PostResource`).
     */
    protected function nestResource(string $path, string $parent, string $namespace): void
    {
        if (! is_file($path)) {
            return;
        }

        $parent = Str::endsWith($parent, 'Resource') ? $parent : "{$parent}Resource";
        $class = Str::contains($parent, '\\') ? ltrim($parent, '\\') : "{$namespace}\\{$parent}";
        $short = class_basename($class);
        $contents = (string) file_get_contents($path);

        if (! str_contains($contents, "use {$class};") && Str::beforeLast($class, '\\') !== $namespace) {
            $contents = preg_replace('/^use AlfaSix\\\\Alfakit\\\\Resources\\\\Resource;$/m', "use {$class};\nuse AlfaSix\\Alfakit\\Resources\\Resource;", $contents, 1) ?? $contents;
        }

        $contents = preg_replace(
            '/(    protected static \?string \$model = [^;]+;\n)/',
            "$1\n    protected static ?string \$parentResource = {$short}::class;\n",
            $contents,
            1,
        ) ?? $contents;

        file_put_contents($path, $contents);

        $this->components->info("Nested under {$short}: its pages live at /{parent}/{record}/... Link to them from a relation manager with `protected static ?string \$relatedResource`.");
    }
}
