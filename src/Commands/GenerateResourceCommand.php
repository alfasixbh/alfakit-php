<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\WritesResources;
use AlfaSix\Alfakit\Commands\Support\GeneratedCode;
use AlfaSix\Alfakit\Commands\Support\ModelInspector;
use AlfaSix\Alfakit\Commands\Support\ResourceCodeBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * The resource wizard (`alfakit:generate`, or `make:alfakit-resource --wizard`): pick a
 * model, see its columns and relationships, choose the form fields, table columns,
 * filters, infolist entries and relation managers (with defaults from each column's type),
 * then write the resource through the regular generator stubs. Every choice is also an
 * option, so CI runs it without prompts.
 */
#[AsCommand(name: 'alfakit:generate', description: 'Generate a resource from a model, choosing fields, columns, filters and relation managers')]
class GenerateResourceCommand extends Command
{
    use CanGenerateFiles;
    use WritesResources;

    /** Relationship types that get a relation manager. */
    public const MANAGED_RELATIONS = ['HasMany', 'BelongsToMany', 'MorphMany', 'MorphToMany', 'HasManyThrough'];

    protected $signature = 'alfakit:generate
        {model? : The model (Post or App\\Models\\Post); a list of app models without it}
        {--panel= : The panel id}
        {--form=* : Columns that become form fields (comma separated, "none" for none; default: smart defaults)}
        {--table=* : Columns that become table columns}
        {--filters=* : Columns that become table filters}
        {--infolist=* : Columns that become infolist entries (adds a View page)}
        {--relation-managers=* : HasMany / BelongsToMany / MorphMany relationships that get a relation manager}
        {--view : Add a View page with an infolist}
        {--simple : Modal-based CRUD on a single page}
        {--soft-deletes : Add trashed filter and restore / force-delete actions (default: when the model soft deletes)}
        {--no-soft-deletes : Leave soft-delete support out even when the model soft deletes}
        {--force : Overwrite existing files}';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();
        $modelClass = $this->resolveModel($interactive);

        if ($modelClass === null) {
            return self::FAILURE;
        }

        if ($interactive && $this->option('panel') === null && count($panels = alfakit()->getPanels()) > 1) {
            $this->input->setOption('panel', select('Panel', array_combine(array_keys($panels), array_keys($panels)), alfakit()->getDefaultPanel()?->getId()));
        }

        try {
            $info = ModelInspector::inspect($modelClass);
            $info['columns'] = $this->withoutTenantOwnerColumn($this->withoutUserOwnerColumn($info['columns'], is_string($this->option('panel')) ? $this->option('panel') : null), is_string($this->option('panel')) ? $this->option('panel') : null);
        } catch (Throwable $exception) {
            $this->components->error("Could not read the table of {$modelClass}: {$exception->getMessage()}. Run the migrations first.");

            return self::FAILURE;
        }

        $builder = new ResourceCodeBuilder($info['columns']);

        if ($interactive) {
            $this->showColumns($info);
        }

        $simple = (bool) $this->option('simple');
        $options = $builder->options();

        $form = $this->choose('form', 'Form fields', $options, $builder->defaultFormColumns(), $interactive);
        $this->warnGuardedAttributes($modelClass, array_values(array_filter($form, 'is_string')));
        $tableColumns = $this->choose('table', 'Table columns', $options, $builder->defaultTableColumns(), $interactive);
        $filters = $this->choose('filters', 'Table filters', $builder->options(filtersOnly: true), $builder->defaultFilterColumns(), $interactive);

        $view = (bool) $this->option('view') || $this->option('infolist') !== [];

        if ($interactive && ! $view) {
            $view = confirm('Add a View page with an infolist?', default: false);
        }

        $infolist = $view ? $this->choose('infolist', 'Infolist entries', $options, $builder->defaultInfolistColumns(), $interactive) : [];

        $softDeletes = match (true) {
            (bool) $this->option('no-soft-deletes') => false,
            (bool) $this->option('soft-deletes') && ! $info['softDeletes'] => $this->warnNoSoftDeletes($modelClass),
            (bool) $this->option('soft-deletes') => true,
            $info['softDeletes'] && $interactive => confirm('The model soft deletes. Add the trashed filter and restore actions?', default: true),
            default => $info['softDeletes'],
        };

        $managed = array_values(array_filter($info['relations'], static fn (array $relation): bool => in_array($relation['type'], self::MANAGED_RELATIONS, true)));
        $relationOptions = [];

        foreach ($managed as $relation) {
            $relationOptions[$relation['name']] = "{$relation['name']} — {$relation['type']} ".class_basename($relation['related']);
        }

        $managers = $simple ? [] : $this->choose('relation-managers', 'Relation managers', $relationOptions, array_keys($relationOptions), $interactive);

        $model = class_basename($modelClass);

        if ($interactive && ! confirm("Generate {$model}Resource?", default: true)) {
            return self::FAILURE;
        }

        $relations = array_map(static fn (string $name): string => '            RelationManagers\\'.Str::studly($name).'RelationManager::class,', $managers);
        $formFields = $builder->formFields($form);
        $columns = $builder->tableColumns($tableColumns);
        $filterCode = $builder->filters($filters);
        $infolistCode = $builder->infolist($infolist);
        $imports = $builder->imports();

        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $resourceNamespace = $this->getGenerationNamespace().$this->panelNamespaceSuffix(is_string($panelId) ? $panelId : null).'\\Resources';

        if ($relations !== []) {
            $imports[] = "{$resourceNamespace}\\{$model}Resource\\RelationManagers";
        }

        $force = (bool) $this->option('force');
        $path = $this->writeResource($model, $modelClass, [
            'formFields' => $formFields === '' ? '                //' : $formFields,
            'tableColumns' => $columns === '' ? '                //' : $columns,
            'imports' => $imports,
            'filters' => $filterCode,
            'infolist' => $infolistCode,
            'relations' => $relations === [] ? '            //' : implode("\n", $relations),
            'softDeletes' => $softDeletes,
            'view' => $view,
            'simple' => $simple,
            'panel' => $panelId,
            'force' => $force,
        ]);

        foreach ($this->writtenResourceFiles as $file) {
            $this->tidy($file);
        }

        foreach ($managers as $name) {
            $relation = collect($managed)->firstWhere('name', $name);

            if ($relation === null) {
                continue;
            }

            $class = Str::studly($name).'RelationManager';
            $managerPath = dirname($path)."/{$model}Resource/RelationManagers/{$class}.php";

            if (is_file($managerPath) && ! $force) {
                $this->components->warn("{$class} already exists, skipping. Use --force to overwrite.");

                continue;
            }

            $this->callSilently('make:alfakit-relation-manager', array_filter([
                'resource' => "{$model}Resource",
                'relationship' => $name,
                'recordTitleAttribute' => $relation['titleAttribute'],
                '--attach' => in_array($relation['type'], ['BelongsToMany', 'MorphToMany'], true),
                '--panel' => is_string($panelId) ? $panelId : null,
                '--force' => $force,
            ]));

            $this->tidy($managerPath);
            $this->components->info("Created {$class}");
        }

        return self::SUCCESS;
    }

    /**
     * @return class-string<Model>|null
     */
    protected function resolveModel(bool $interactive): ?string
    {
        $argument = $this->argument('model');

        if (! is_string($argument) || $argument === '') {
            $models = ModelInspector::models();

            if (! $interactive) {
                $this->components->error('Pass the model: php artisan alfakit:generate Post');

                return null;
            }

            $argument = $models === []
                ? text('Model class', 'App\\Models\\Post', required: true)
                : (string) search(
                    'Model',
                    static fn (string $value): array => array_values(array_filter($models, static fn (string $model): bool => $value === '' || Str::contains($model, $value, ignoreCase: true))),
                    scroll: 10,
                );
        }

        $class = Str::contains($argument, '\\') ? ltrim($argument, '\\') : 'App\\Models\\'.Str::studly($argument);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->components->error("{$class} is not an Eloquent model.");

            return null;
        }

        return $class;
    }

    /**
     * A multi-select prompt, or the option's comma-separated value, or the defaults.
     *
     * @param  array<string, string>  $options
     * @param  list<string>  $defaults
     * @return list<string>
     */
    protected function choose(string $option, string $label, array $options, array $defaults, bool $interactive): array
    {
        /** @var array<int, string> $given */
        $given = (array) $this->option($option);
        $values = array_values(array_filter(array_map('trim', explode(',', implode(',', $given))), static fn (string $value): bool => $value !== ''));

        if ($values === ['none']) {
            return [];
        }

        if ($values !== []) {
            $unknown = array_diff($values, array_keys($options));

            if ($unknown !== []) {
                $this->components->warn("--{$option}: skipping ".implode(', ', $unknown).' (not a column of the model).');
            }

            return array_values(array_intersect($values, array_keys($options)));
        }

        $defaults = array_values(array_intersect($defaults, array_keys($options)));

        if (! $interactive || $options === []) {
            return $defaults;
        }

        return array_values(array_map('strval', multiselect($label, $options, $defaults, scroll: 12)));
    }

    /**
     * @param  array{table: string, columns: list<array<string, mixed>>, relations: list<array<string, mixed>>}  $info
     */
    protected function showColumns(array $info): void
    {
        $this->components->info("Table {$info['table']}");

        table(['Column', 'Type', 'Becomes', 'Nullable'], array_map(static fn (array $column): array => [
            (string) $column['name'],
            (string) $column['type'],
            $column['kind'] === 'relation' ? "relation {$column['relationship']}" : (string) $column['kind'],
            $column['nullable'] ? 'yes' : 'no',
        ], $info['columns']));

        if ($info['relations'] !== []) {
            table(['Relationship', 'Type', 'Related'], array_map(static fn (array $relation): array => [
                (string) $relation['name'],
                (string) $relation['type'],
                (string) $relation['related'],
            ], $info['relations']));
        }
    }

    protected function tidy(string $path): void
    {
        if (is_file($path)) {
            file_put_contents($path, GeneratedCode::tidyImports((string) file_get_contents($path)));
        }
    }

    /**
     * `--soft-deletes` on a model without the SoftDeletes trait: leave it out with a warning.
     */
    protected function warnNoSoftDeletes(string $modelClass): bool
    {
        $this->components->warn(str_replace('{class}', $modelClass, 'The model {class} does not use SoftDeletes, so the resource is generated without the trashed filter and restore actions. Add `use SoftDeletes;` to the model and `$table->softDeletes();` to its table, then generate the resource again with --soft-deletes.'));

        return false;
    }

    /**
     * Resources save their form with `create()` / `update()`: warn when the model would refuse
     * the generated fields (Laravel's MassAssignmentException on the first save).
     *
     * @param  list<string>  $attributes
     */
    protected function warnGuardedAttributes(string $modelClass, array $attributes): void
    {
        $guarded = ModelInspector::guardedAttributes($modelClass, $attributes);

        if ($guarded !== []) {
            $this->components->warn("{$modelClass} does not allow mass assignment of ".implode(', ', $guarded).', so saving the form would fail. Add them to the model\'s $fillable array (or use protected $guarded = [];).');
        }
    }
}
