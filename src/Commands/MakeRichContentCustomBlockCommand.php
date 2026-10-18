<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-rich-content-custom-block', description: 'Create a custom block for the rich editor')]
class MakeRichContentCustomBlockCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-rich-content-custom-block {name? : The class name (HeroBlock)} {--force}';

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Block class name', 'HeroBlock', required: true)));
        $name = Str::endsWith($name, 'Block') ? $name : "{$name}Block";
        $base = Str::beforeLast($name, 'Block') ?: $name;
        $subNamespace = 'Forms\\Components\\RichEditor\\RichContentCustomBlocks';

        $this->writeStub('rich-content-custom-block', $this->getGenerationPath().'/'.str_replace('\\', '/', $subNamespace)."/{$name}.php", [
            'namespace' => $this->getGenerationNamespace().'\\'.$subNamespace,
            'class' => $name,
            'id' => Str::kebab($base),
            'label' => Str::headline($base),
        ], (bool) $this->option('force'));

        $this->components->info("Add it to an editor: RichEditor::make('content')->customBlocks([{$name}::class]).");

        return self::SUCCESS;
    }
}
