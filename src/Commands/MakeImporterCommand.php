<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\GuessesModelColumns;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-importer', description: 'Create an Alfakit importer for ImportAction', aliases: ['alfakit:make-importer'])]
class MakeImporterCommand extends Command
{
    use CanGenerateFiles;
    use GuessesModelColumns;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-importer
        {model? : The model name (e.g. Product or App\\Models\\Product)}
        {--generate : Guess the columns from the model\'s table}
        {--panel= : The panel id}
        {--force : Overwrite the importer if it exists}';

    /** @var array<string> */
    protected $aliases = ['alfakit:make-importer'];

    public function handle(): int
    {
        [$modelClass, $model] = $this->resolveModelClass((string) ($this->argument('model') ?? text('Model name', 'Product', required: true)));

        $class = "{$model}Importer";
        $suffix = $this->panelNamespaceSuffix($this->option('panel') ?? alfakit()->getDefaultPanel()?->getId());
        $namespace = $this->getGenerationNamespace().$suffix.'\\Imports';
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Imports/{$class}.php";

        $columns = $this->option('generate') ? $this->generateColumns($modelClass) : null;

        $this->writeStub('importer', $path, [
            'namespace' => $namespace,
            'class' => $class,
            'model' => $model,
            'modelClass' => $modelClass,
            'columns' => $columns ?? "            ImportColumn::make('name')\n                ->requiredMapping()\n                ->rules(['required', 'max:255']),",
        ], (bool) $this->option('force'));

        return self::SUCCESS;
    }

    protected function generateColumns(string $modelClass): ?string
    {
        $tableColumns = $this->getModelTableColumns($modelClass);

        if ($tableColumns === null) {
            return null;
        }

        $model = new $modelClass;
        $skip = [$model->getKeyName(), 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'password'];
        $lines = [];

        foreach ($tableColumns as $column) {
            $name = $column['name'];
            $type = $column['type'];

            if (in_array($name, $skip, true)) {
                continue;
            }

            $required = ! $column['nullable'] && $column['default'] === null;
            $rules = $required ? ['required'] : [];
            $chain = [];

            if (Str::endsWith($name, '_id')) {
                $relationship = Str::camel(Str::beforeLast($name, '_id'));
                $name = Str::snake($relationship);
                $chain[] = method_exists($model, $relationship) ? '->relationship()' : "->relationship('{$relationship}')";
            } elseif (static::isBooleanColumn($name, $type)) {
                $chain[] = '->boolean()';
                $rules[] = 'boolean';
            } elseif (static::isIntegerColumn($type)) {
                $chain[] = '->integer()';
                $rules[] = 'integer';
            } elseif (static::isDecimalColumn($type)) {
                $chain[] = '->numeric()';
                $rules[] = 'numeric';
            } elseif (static::isDateColumn($type)) {
                $rules[] = 'date';
            } elseif (in_array($type, ['json', 'jsonb'], true)) {
                $chain[] = '->array()';
                $rules[] = 'array';
            } elseif (str_contains($name, 'email')) {
                $rules[] = 'email';
                $rules[] = 'max:255';
            } elseif (in_array($type, ['varchar', 'string', 'char', 'nvarchar', 'character varying'], true)) {
                $rules[] = 'max:255';
            }

            if ($required) {
                array_unshift($chain, '->requiredMapping()');
            }

            if ($rules !== []) {
                $chain[] = "->rules(['".implode("', '", $rules)."'])";
            }

            $lines[] = "            ImportColumn::make('{$name}')".implode('', array_map(static fn (string $call): string => "\n                {$call}", $chain)).',';
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
