<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-schema-component', description: 'Create a custom layout component for schemas: a PHP class and its frontend component')]
class MakeSchemaComponentCommand extends Command
{
    use GeneratesFrontendComponents;

    protected $signature = 'make:alfakit-schema-component {name? : The class name (Card)} {--adapter= : react, vue or svelte (detected from package.json)} {--no-frontend : Only create the PHP class} {--force}';

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Component class name', 'Card', required: true)));
        // The class name, like the auto-registered file (`columns/ProgressBar.tsx`).
        $type = $name;

        return $this->generateComponent('schema-component', $name, 'Schemas\\Components', $type, $type);
    }
}
