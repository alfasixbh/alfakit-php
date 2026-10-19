<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\WritesResources;
use AlfaSix\Alfakit\Commands\Support\ModelInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-resource', description: 'Create an Alfakit resource with its pages')]
class MakeResourceCommand extends Command
{
    use CanGenerateFiles;
    use WritesResources;

    protected $signature = 'make:alfakit-resource
        {model? : The model name (e.g. Post or App\\Models\\Post)}
        {--generate : Generate form fields and table columns from the database schema}
        {--view : Also create a View page}
        {--simple : Modal-based CRUD on a single page (no create/edit pages)}
        {--soft-deletes : Add trashed filter and restore/force-delete actions}
        {--nested= : Nest the resource under a parent resource (e.g. PostResource)}
        {--singular : A resource that edits one record (e.g. settings)}
        {--panel= : The panel id}
        {--model : Also create the model}
        {--migration : Also create a migration for the model}
        {--factory : Also create a factory for the model}
        {--model-namespace= : The namespace of the model (default App\\Models)}
        {--wizard : Pick columns, filters, infolist entries and relation managers interactively (alfakit:generate)}
        {--force}';

    public function handle(): int
    {
        if ($this->option('wizard')) {
            return $this->call('alfakit:generate', array_filter([
                'model' => $this->argument('model'),
                '--panel' => $this->option('panel'),
                '--simple' => (bool) $this->option('simple'),
                '--force' => (bool) $this->option('force'),
            ]));
        }

        $model = (string) ($this->argument('model') ?? text('Model name', 'Post', required: true));
        $model = Str::studly(class_basename(str_replace('/', '\\', $model)));
        $modelNamespace = trim((string) $this->option('model-namespace'), '\\');
        $modelClass = match (true) {
            Str::contains((string) $this->argument('model'), '\\') => ltrim((string) $this->argument('model'), '\\'),
            $modelNamespace !== '' => "{$modelNamespace}\\{$model}",
            default => "App\\Models\\{$model}",
        };

        $this->createModelFiles($modelClass);

        [$formFields, $tableColumns, $imports] = $this->option('generate') ? $this->generateFromSchema($modelClass) : [$this->defaultForm(), $this->defaultTable(), []];

        if ($this->option('singular')) {
            $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
            $suffix = $this->panelNamespaceSuffix($panelId);

            return $this->generateSingularResource($this->getGenerationNamespace().$suffix.'\\Resources', $this->getGenerationPath().str_replace('\\', '/', $suffix).'/Resources', "{$model}Resource", $model, $modelClass, $formFields, $imports, (bool) $this->option('force'));
        }

        $this->writeResource($model, $modelClass, [
            'formFields' => $formFields,
            'tableColumns' => $tableColumns,
            'imports' => $imports,
            'softDeletes' => $this->softDeletesFor($modelClass),
            'view' => (bool) $this->option('view'),
            'simple' => (bool) $this->option('simple'),
            'nested' => filled($this->option('nested')) ? (string) $this->option('nested') : null,
            'panel' => $this->option('panel'),
            'force' => (bool) $this->option('force'),
        ]);

        return self::SUCCESS;
    }

    /**
     * `--model`, `--migration` and `--factory`: create what the resource needs, through
     * Laravel's own generators so their stubs and conventions apply.
     */
    protected function createModelFiles(string $modelClass): void
    {
        $wantsModel = (bool) $this->option('model');
        $wantsMigration = (bool) $this->option('migration');
        $wantsFactory = (bool) $this->option('factory');

        if (! $wantsModel && ! $wantsMigration && ! $wantsFactory) {
            return;
        }

        if (! class_exists($modelClass)) {
            $this->call('make:model', array_filter([
                'name' => Str::startsWith($modelClass, 'App\\Models\\') ? Str::after($modelClass, 'App\\Models\\') : $modelClass,
                '--migration' => $wantsMigration,
            ]));
        } else {
            if ($wantsModel) {
                $this->components->info("{$modelClass} already exists, skipping the model.");
            }

            if ($wantsMigration) {
                $table = Str::snake(Str::pluralStudly(class_basename($modelClass)));

                $this->call('make:migration', ['name' => "create_{$table}_table", '--create' => $table]);
            }
        }

        if ($wantsFactory) {
            $this->call('make:factory', ['name' => class_basename($modelClass).'Factory', '--model' => $modelClass]);
        }
    }

    /**
     * @param  array<string>  $imports
     */
    protected function generateSingularResource(string $namespace, string $basePath, string $resource, string $model, string $modelClass, string $formFields, array $imports, bool $force): int
    {
        $this->writeStub('resource-singular', "{$basePath}/{$resource}.php", [
            'namespace' => $namespace,
            'resource' => $resource,
            'model' => $model,
            'modelClass' => $modelClass,
            'label' => Str::headline(Str::pluralStudly($model)),
            'formFields' => $formFields,
            'imports' => implode("\n", array_map(static fn (string $i): string => "use {$i};", array_unique($imports))),
        ], $force);

        $this->writeStub('pages/singular', "{$basePath}/{$resource}/Pages/Manage{$model}.php", [
            'namespace' => "{$namespace}\\{$resource}\\Pages",
            'resourceNamespace' => $namespace,
            'resource' => $resource,
            'model' => $model,
        ], $force);

        return self::SUCCESS;
    }

    protected function defaultForm(): string
    {
        return "                TextInput::make('name')\n                    ->required()\n                    ->maxLength(255),";
    }

    protected function defaultTable(): string
    {
        return "                TextColumn::make('name')\n                    ->searchable()\n                    ->sortable(),\n                TextColumn::make('created_at')\n                    ->dateTime()\n                    ->sortable()\n                    ->toggleable(isToggledHiddenByDefault: true),";
    }

    /**
     * @return array{0: string, 1: string, 2: array<string>}
     */
    protected function generateFromSchema(string $modelClass): array
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->warn("Model {$modelClass} not found; generating a default form and table instead.");

            return [$this->defaultForm(), $this->defaultTable(), []];
        }

        $model = new $modelClass;
        $table = $model->getTable();
        $columns = DbSchema::getColumns($table);
        $fields = [];
        $tableColumns = [];
        $imports = [];

        $skip = [$model->getKeyName(), 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'password'];

        foreach ($columns as $column) {
            $name = $column['name'];
            $type = Str::lower((string) $column['type_name']);
            $nullable = (bool) $column['nullable'];
            $required = $nullable ? '' : "\n                    ->required()";

            if (in_array($name, $skip, true)) {
                continue;
            }

            if (Str::endsWith($name, '_id')) {
                $relationship = Str::camel(Str::beforeLast($name, '_id'));
                $fields[] = "                Select::make('{$name}')\n                    ->relationship('{$relationship}', 'name')\n                    ->searchable()\n                    ->preload(){$required},";
                $tableColumns[] = "                TextColumn::make('{$relationship}.name')\n                    ->sortable(),";
                $imports[] = 'AlfaSix\\Alfakit\\Forms\\Components\\Select';

                continue;
            }

            [$field, $tableColumn, $fieldImports] = match (true) {
                in_array($type, ['boolean', 'bool', 'tinyint'], true) && (str_starts_with($name, 'is_') || str_starts_with($name, 'has_') || $type !== 'tinyint') => [
                    "                Toggle::make('{$name}'),",
                    "                IconColumn::make('{$name}')\n                    ->boolean(),",
                    ['AlfaSix\\Alfakit\\Forms\\Components\\Toggle', 'AlfaSix\\Alfakit\\Tables\\Columns\\IconColumn'],
                ],
                in_array($type, ['text', 'longtext', 'mediumtext'], true) => [
                    "                Textarea::make('{$name}')\n                    ->columnSpanFull(){$required},",
                    null,
                    ['AlfaSix\\Alfakit\\Forms\\Components\\Textarea'],
                ],
                in_array($type, ['json', 'jsonb'], true) => [
                    "                KeyValue::make('{$name}')\n                    ->columnSpanFull(){$required},",
                    null,
                    ['AlfaSix\\Alfakit\\Forms\\Components\\KeyValue'],
                ],
                in_array($type, ['date'], true) => [
                    "                DatePicker::make('{$name}'){$required},",
                    "                TextColumn::make('{$name}')\n                    ->date()\n                    ->sortable(),",
                    ['AlfaSix\\Alfakit\\Forms\\Components\\DatePicker'],
                ],
                in_array($type, ['datetime', 'timestamp', 'timestamptz', 'datetimetz'], true) => [
                    "                DateTimePicker::make('{$name}'){$required},",
                    "                TextColumn::make('{$name}')\n                    ->dateTime()\n                    ->sortable(),",
                    ['AlfaSix\\Alfakit\\Forms\\Components\\DateTimePicker'],
                ],
                in_array($type, ['time'], true) => [
                    "                TimePicker::make('{$name}'){$required},",
                    "                TextColumn::make('{$name}'),",
                    ['AlfaSix\\Alfakit\\Forms\\Components\\TimePicker'],
                ],
                in_array($type, ['integer', 'int', 'bigint', 'smallint', 'int4', 'int8', 'int2', 'mediumint'], true) => [
                    "                TextInput::make('{$name}')\n                    ->integer(){$required},",
                    "                TextColumn::make('{$name}')\n                    ->numeric()\n                    ->sortable(),",
                    [],
                ],
                in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float8', 'float4'], true) => [
                    "                TextInput::make('{$name}')\n                    ->numeric(){$required},",
                    "                TextColumn::make('{$name}')\n                    ->numeric()\n                    ->sortable(),",
                    [],
                ],
                str_contains($name, 'email') => [
                    "                TextInput::make('{$name}')\n                    ->email()\n                    ->maxLength(255){$required},",
                    "                TextColumn::make('{$name}')\n                    ->searchable(),",
                    [],
                ],
                str_contains($name, 'image') || str_contains($name, 'avatar') || str_contains($name, 'photo') => [
                    "                FileUpload::make('{$name}')\n                    ->image(){$required},",
                    "                ImageColumn::make('{$name}'),",
                    ['AlfaSix\\Alfakit\\Forms\\Components\\FileUpload', 'AlfaSix\\Alfakit\\Tables\\Columns\\ImageColumn'],
                ],
                str_contains($name, 'color') => [
                    "                ColorPicker::make('{$name}'){$required},",
                    "                ColorColumn::make('{$name}'),",
                    ['AlfaSix\\Alfakit\\Forms\\Components\\ColorPicker', 'AlfaSix\\Alfakit\\Tables\\Columns\\ColorColumn'],
                ],
                default => [
                    "                TextInput::make('{$name}')\n                    ->maxLength(255){$required},",
                    "                TextColumn::make('{$name}')\n                    ->searchable(),",
                    [],
                ],
            };

            $fields[] = $field;

            if ($tableColumn !== null) {
                $tableColumns[] = $tableColumn;
            }

            array_push($imports, ...$fieldImports);
        }

        $tableColumns[] = "                TextColumn::make('created_at')\n                    ->dateTime()\n                    ->sortable()\n                    ->toggleable(isToggledHiddenByDefault: true),";

        return [implode("\n", $fields), implode("\n", $tableColumns), $imports];
    }

    /**
     * `--soft-deletes` on a model without the SoftDeletes trait would generate a trashed filter
     * and restore actions that fail at runtime, so it is left out with a warning.
     */
    protected function softDeletesFor(string $modelClass): bool
    {
        if (! $this->option('soft-deletes')) {
            return false;
        }

        if (ModelInspector::softDeletes($modelClass) === false) {
            $this->components->warn(str_replace('{class}', $modelClass, 'The model {class} does not use SoftDeletes, so the resource is generated without the trashed filter and restore actions. Add `use SoftDeletes;` to the model and `$table->softDeletes();` to its table, then generate the resource again with --soft-deletes.'));

            return false;
        }

        return true;
    }
}
