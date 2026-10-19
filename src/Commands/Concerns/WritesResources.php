<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\Support\ModelInspector;
use AlfaSix\Alfakit\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

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
            'recordTitleAttribute' => ($title = $this->recordTitleAttributeFor($modelClass)) === null ? '' : "\n\n    protected static ?string \$recordTitleAttribute = '{$title}';",
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
     * The class of `--nested=PostResource` (or a fully qualified class) in the namespace of
     * the panel's resources.
     */
    protected function parentResourceClass(string $parent, string $namespace): string
    {
        $parent = Str::endsWith($parent, 'Resource') ? $parent : "{$parent}Resource";

        return Str::contains($parent, '\\') ? ltrim($parent, '\\') : "{$namespace}\\{$parent}";
    }

    /**
     * Leave out the foreign key to the parent of a nested resource (`--nested=PostResource`):
     * its pages create records under the parent record in the URL.
     *
     * @template TColumn of array{name: string, related: ?string}
     *
     * @param  list<TColumn>  $columns
     * @return list<TColumn>
     */
    protected function withoutParentColumn(array $columns, string $parent, ?string $panelId): array
    {
        $namespace = $this->getGenerationNamespace().$this->panelNamespaceSuffix($panelId).'\\Resources';
        $class = $this->parentResourceClass($parent, $namespace);

        if (! class_exists($class) || ! is_subclass_of($class, Resource::class)) {
            return $columns;
        }

        $parentModel = ltrim($class::getModel(), '\\');

        return array_values(array_filter($columns, static fn (array $column): bool => $column['related'] === null || ltrim($column['related'], '\\') !== $parentModel));
    }

    /**
     * Point a generated resource at its parent resource (`--nested=PostResource`).
     */
    protected function nestResource(string $path, string $parent, string $namespace): void
    {
        if (! is_file($path)) {
            return;
        }

        $class = $this->parentResourceClass($parent, $namespace);
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

    /**
     * Leave out the owner column of a panel that scopes records to the signed-in user
     * (`scopeToUser('customer_id')` or a `customer` relationship): the owner is filled in
     * on save, and a select of every user would show customers each other's names.
     *
     * @template TColumn of array{name: string, relationship: ?string}
     *
     * @param  list<TColumn>  $columns
     * @return list<TColumn>
     */
    protected function withoutUserOwnerColumn(array $columns, ?string $panelId): array
    {
        $panels = alfakit()->getPanels();
        $panel = $panelId !== null ? ($panels[$panelId] ?? null) : alfakit()->getDefaultPanel();
        $ownership = $panel?->getUserOwnership();

        if ($ownership === null) {
            return $columns;
        }

        $kept = array_values(array_filter($columns, static fn (array $column): bool => $column['name'] !== $ownership && $column['relationship'] !== $ownership));

        if (count($kept) !== count($columns)) {
            $this->components->info("Left out the owner column `{$ownership}`: the panel scopes records to the signed-in user and fills it in.");
        }

        return $kept;
    }

    /**
     * Leave out the tenant ownership column of a tenancy panel (`team_id` of
     * `->tenant(Team::class, ownershipRelationship: 'team')`): new records are associated
     * with the current tenant, so a select of the one current tenant is noise.
     *
     * @template TColumn of array{name: string, relationship: ?string}
     *
     * @param  list<TColumn>  $columns
     * @return list<TColumn>
     */
    protected function withoutTenantOwnerColumn(array $columns, ?string $panelId): array
    {
        $panels = alfakit()->getPanels();
        $panel = $panelId !== null ? ($panels[$panelId] ?? null) : alfakit()->getDefaultPanel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return $columns;
        }

        $relationship = $panel->getTenantOwnershipRelationshipName();
        $kept = array_values(array_filter($columns, static fn (array $column): bool => $column['relationship'] !== $relationship && $column['name'] !== Str::snake($relationship).'_id'));

        if (count($kept) !== count($columns)) {
            $this->components->info("Left out the tenant column of `{$relationship}()`: new records belong to the current tenant.");
        }

        return $kept;
    }

    /**
     * The column that names a record (`name`, `title`, `email`…), so page titles, breadcrumbs
     * and modal headings read "Edit Product 1" rather than "Edit 1"; null without one.
     */
    protected function recordTitleAttributeFor(string $modelClass): ?string
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        try {
            $model = new $modelClass;
            $attribute = ModelInspector::titleAttribute($model);
        } catch (Throwable) {
            return null;
        }

        return $attribute === $model->getKeyName() ? null : $attribute;
    }
}
