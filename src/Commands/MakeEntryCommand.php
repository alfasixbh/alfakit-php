<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-entry', description: 'Create a custom infolist entry: a PHP class and its frontend component', aliases: ['alfakit:make-entry'])]
class MakeEntryCommand extends Command
{
    use GeneratesFrontendComponents;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-entry {name? : The class name} {--adapter= : react, vue or svelte (detected from package.json)} {--no-frontend : Only create the PHP class} {--force}';

    /** @var array<int, string> */
    protected $aliases = ['alfakit:make-entry'];

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Entry class name', 'RatingEntry', required: true)));
        // The class name, like the auto-registered file (`columns/ProgressBar.tsx`).
        $type = $name;

        return $this->generateComponent('entry', $name, 'Infolists\\Components', $type, $type);
    }
}
