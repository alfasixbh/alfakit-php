<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\GuessesModelColumns;
use AlfaSix\Alfakit\Commands\Support\ModelInspector;
use AlfaSix\Alfakit\Commands\Support\ResourceCodeBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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
        $imports = [];
        $model = $this->option('model');

        if (is_string($model) && $model !== '') {
            [$modelClass] = $this->resolveModelClass($model);
            [$components, $imports] = $this->componentsFromModel($modelClass) ?? [$components, []];
        }

        $written = $this->writeStub('schema', $path, [
            'namespace' => $namespace,
            'class' => $name,
            'components' => $components,
            'imports' => implode('', array_map(static fn (string $class): string => "use {$class};\n", $imports)),
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
     * The fields the resource generator infers for the model (relationship selects, enum
     * options, numeric inputs, rich editors…), as code with the classes to import, or null
     * when the model or its table cannot be read.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    protected function componentsFromModel(string $modelClass): ?array
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->warn("Model {$modelClass} not found; add the fields yourself.");

            return null;
        }

        try {
            $builder = new ResourceCodeBuilder(ModelInspector::inspect($modelClass)['columns']);
            // The builder indents for a resource's `form()`; a fragment's array sits one level up.
            $code = (string) preg_replace('/^    /m', '', $builder->formFields($builder->defaultFormColumns()));
        } catch (Throwable) {
            $code = '';
        }

        if ($code === '') {
            $this->components->warn("Could not read the table of {$modelClass}; add the fields yourself.");

            return null;
        }

        return [$code, $builder->imports()];
    }
}
