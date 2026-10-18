<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-column', description: 'Create a custom table column: a PHP class and its frontend cell component', aliases: ['alfakit:make-column'])]
class MakeColumnCommand extends Command
{
    use GeneratesFrontendComponents;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-column {name? : The class name} {--adapter= : react, vue or svelte (detected from package.json)} {--no-frontend : Only create the PHP class} {--force}';

    /** @var array<int, string> */
    protected $aliases = ['alfakit:make-column'];

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Column class name', 'ProgressColumn', required: true)));
        // The class name, like the auto-registered file (`columns/ProgressBar.tsx`).
        $type = $name;

        return $this->generateComponent('column', $name, 'Tables\\Columns', $type, "column:{$type}");
    }
}
