<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\GuessesModelColumns;
use AlfaSix\Alfakit\Schemas\ModelSchema;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-schema', description: 'Create a reusable schema fragment, usable inside any components([...])', aliases: ['alfakit:make-schema'])]
class MakeSchemaCommand extends Command
{
    use CanGenerateFiles;
    use GuessesModelColumns;

    protected $signature = 'make:alfakit-schema {name? : The class name (AddressFields)} {--model= : Start from the fields inferred for a model} {--force}';

    /** @var array<int, string> */
    protected $aliases = ['alfakit:make-schema'];

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Schema class name', 'AddressFields', required: true)));
        $namespace = $this->getGenerationNamespace().'\\Schemas';
        $path = $this->getGenerationPath()."/Schemas/{$name}.php";

        $components = $this->defaultComponents();
        $model = $this->option('model');

        if (is_string($model) && $model !== '') {
            [$modelClass] = $this->resolveModelClass($model);
            $components = $this->componentsFromModel($modelClass) ?? $components;
        }

        $written = $this->writeStub('schema', $path, [
            'namespace' => $namespace,
            'class' => $name,
            'components' => $components,
        ], (bool) $this->option('force'));

        if ($written) {
            $this->components->info("Use it with {$name}::make() inside any components([...]); add ->statePath('…') to prefix its state or ->relationship('…') to edit a related record.");
        }

        return self::SUCCESS;
    }

    protected function defaultComponents(): string
    {
        return implode("\n", [
            "            TextInput::make('street')->required()->maxLength(255),",
            "            TextInput::make('city')->required()->maxLength(255),",
            "            TextInput::make('postal_code')->maxLength(20),",
        ]);
    }

    /**
     * The inferred fields as code (`Schema::fromModel()` does the same at runtime), or null
     * when the model or its table cannot be read.
     */
    protected function componentsFromModel(string $modelClass): ?string
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->warn("Model {$modelClass} not found; add the fields yourself.");

            return null;
        }

        $fields = ModelSchema::fields($modelClass);

        if ($fields === []) {
            $this->components->warn("Could not read the table of {$modelClass}; add the fields yourself.");

            return null;
        }

        $lines = [];

        foreach ($fields as $field) {
            $class = class_basename($field);
            $call = $field->isRequired() ? '->required()' : '';

            $lines[] = "            \\AlfaSix\\Alfakit\\Forms\\Components\\{$class}::make('{$field->getName()}'){$call},";
        }

        return implode("\n", $lines);
    }
}
