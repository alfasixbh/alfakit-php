<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Support\SchemaGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\text;

/**
 * Shared by `make:alfakit-form`, `make:alfakit-table`, `make:alfakit-infolist` and
 * `make:alfakit-component`: classes extending `AlfaSix\Alfakit\Standalone\*`, generated in
 * `app/Alfakit/Standalone`, with an optional model to bind.
 */
trait GeneratesStandaloneComponents
{
    use CanGenerateFiles;

    /**
     * @param  string  $kind  `form`, `table`, `infolist` or `component` (the stub name)
     */
    protected function generateStandaloneComponent(string $kind, string $defaultName): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text(Str::headline($kind).' class name', $defaultName, required: true)));
        $model = $this->option('model');
        $model = is_string($model) && $model !== '' ? Str::studly($model) : null;
        $modelClass = $model === null ? null : (str_contains($model, '\\') ? $model : 'App\\Models\\'.$model);
        $modelBasename = $modelClass === null ? null : class_basename($modelClass);
        $variable = $modelBasename === null ? null : Str::camel($modelBasename);

        $namespace = $this->getGenerationNamespace().'\\Standalone';
        $path = $this->getGenerationPath()."/Standalone/{$name}.php";

        [$components, $columns, $imports] = $this->standaloneBody($kind, $modelClass);

        $written = $this->writeStub("standalone/{$kind}", $path, [
            'components' => $components,
            'columns' => $columns,
            'imports' => $imports,
            'namespace' => $namespace,
            'class' => $name,
            'heading' => Str::headline($name),
            'modelImport' => $modelClass === null ? '' : "use {$modelClass};\n",
            'modelProperty' => $modelBasename === null ? '' : "    public {$modelBasename} \${$variable};\n\n",
            'modelParameter' => $variable === null ? '' : "['{$variable}' => \${$variable}]",
            'query' => $modelBasename === null ? '/* Model::query() */' : "{$modelBasename}::query()",
            'recordParameter' => $variable === null ? '' : "['record' => \${$variable}]",
        ], (bool) $this->option('force'));

        if ($written) {
            $prop = Str::camel($name);
            $arguments = match (true) {
                $variable === null => '',
                in_array($kind, ['form', 'infolist'], true) => "['record' => \${$variable}]",
                default => "['{$variable}' => \${$variable}]",
            };

            $this->components->info("Render it from a controller: Inertia::render('YourPage', ['{$prop}' => \\{$namespace}\\{$name}::make({$arguments})]) and show it with <AlfakitComponent component={{$prop}} />.");
        }

        return self::SUCCESS;
    }

    /**
     * The form components / table columns and their imports: generated from the model's
     * table with `--generate` (`SchemaGenerator`), otherwise the stub's examples.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function standaloneBody(string $kind, ?string $modelClass): array
    {
        $components = implode("\n", [
            "            TextInput::make('name')->required()->maxLength(255),",
            "            TextInput::make('email')->email()->required(),",
            "            Textarea::make('message')->required()->rows(5)->columnSpanFull(),",
        ]);
        $columns = implode("\n", [
            "                TextColumn::make('id')->sortable(),",
            "                TextColumn::make('created_at')->dateTime()->sortable(),",
        ]);
        $imports = $kind === 'table'
            ? "use AlfaSix\\Alfakit\\Tables\\Columns\\TextColumn;\n"
            : "use AlfaSix\\Alfakit\\Forms\\Components\\TextInput;\nuse AlfaSix\\Alfakit\\Forms\\Components\\Textarea;\n";

        $generate = $this->input->hasOption('generate') && (bool) $this->input->getOption('generate');

        if (! $generate || ! in_array($kind, ['form', 'table'], true)) {
            return [$components, $columns, $imports];
        }

        if ($modelClass === null || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->warn('--generate needs an existing model (--model=Post); using the example '.($kind === 'table' ? 'columns' : 'fields').'.');

            return [$components, $columns, $imports];
        }

        try {
            $generated = $kind === 'form'
                ? SchemaGenerator::formComponents($modelClass, 12)
                : SchemaGenerator::tableColumns($modelClass, 16);
        } catch (Throwable $exception) {
            $this->components->warn("Could not read the table of {$modelClass} ({$exception->getMessage()}); using the examples.");

            return [$components, $columns, $imports];
        }

        if ($generated['code'] === '') {
            return [$components, $columns, $imports];
        }

        $generatedImports = implode('', array_map(static fn (string $class): string => "use {$class};\n", $generated['imports']));

        return $kind === 'form'
            ? [$generated['code'], $columns, $generatedImports]
            : [$components, $generated['code'], $generatedImports];
    }
}
