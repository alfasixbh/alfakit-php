<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\GuessesModelColumns;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-exporter', description: 'Create an Alfakit exporter for ExportAction', aliases: ['alfakit:make-exporter'])]
class MakeExporterCommand extends Command
{
    use CanGenerateFiles;
    use GuessesModelColumns;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-exporter
        {model? : The model name (e.g. Product or App\\Models\\Product)}
        {--generate : Guess the columns from the model\'s table}
        {--panel= : The panel id}
        {--force : Overwrite the exporter if it exists}';

    /** @var array<string> */
    protected $aliases = ['alfakit:make-exporter'];

    public function handle(): int
    {
        [$modelClass, $model] = $this->resolveModelClass((string) ($this->argument('model') ?? text('Model name', 'Product', required: true)));

        $class = "{$model}Exporter";
        $suffix = $this->panelNamespaceSuffix($this->option('panel') ?? alfakit()->getDefaultPanel()?->getId());
        $namespace = $this->getGenerationNamespace().$suffix.'\\Exports';
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Exports/{$class}.php";

        $columns = $this->option('generate') ? $this->generateColumns($modelClass) : null;

        $this->writeStub('exporter', $path, [
            'namespace' => $namespace,
            'class' => $class,
            'model' => $model,
            'modelClass' => $modelClass,
            'columns' => $columns ?? "            ExportColumn::make('id')\n                ->label('ID'),\n            ExportColumn::make('created_at'),",
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
        $hidden = ['password', 'remember_token', ...$model->getHidden()];
        $lines = [];

        foreach ($tableColumns as $column) {
            $name = $column['name'];

            if (in_array($name, $hidden, true)) {
                continue;
            }

            if ($name === $model->getKeyName()) {
                $lines[] = "            ExportColumn::make('{$name}')\n                ->label('".Str::upper($name)."'),";

                continue;
            }

            if (Str::endsWith($name, '_id') && method_exists($model, $relationship = Str::camel(Str::beforeLast($name, '_id')))) {
                $lines[] = "            ExportColumn::make('{$relationship}.".$this->guessTitleAttribute($model, $relationship)."'),";

                continue;
            }

            $lines[] = "            ExportColumn::make('{$name}'),";
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    protected function guessTitleAttribute(\Illuminate\Database\Eloquent\Model $model, string $relationship): string
    {
        try {
            $related = $model->{$relationship}()->getRelated();
            $columns = array_column(\Illuminate\Support\Facades\Schema::connection($related->getConnectionName())->getColumns($related->getTable()), 'name');
        } catch (Throwable) {
            return 'name';
        }

        foreach (['name', 'title', 'label', 'email'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $related->getKeyName();
    }
}
