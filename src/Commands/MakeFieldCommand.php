<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesFrontendComponents;
use AlfaSix\Alfakit\Commands\Concerns\WarnsAboutDeprecatedAliases;
use AlfaSix\Alfakit\Commands\Support\ShadcnField;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-field', description: 'Create a custom form field: a PHP class and its frontend component', aliases: ['alfakit:make-field'])]
class MakeFieldCommand extends Command
{
    use GeneratesFrontendComponents;
    use WarnsAboutDeprecatedAliases;

    protected $signature = 'make:alfakit-field {name? : The class name} {--adapter= : react, vue or svelte (detected from package.json)} {--no-frontend : Only create the PHP class} {--from-shadcn= : Wrap a shadcn component installed in resources/js/components/ui (slider, calendar, input-otp…)} {--force}';

    /** @var array<int, string> */
    protected $aliases = ['alfakit:make-field'];

    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text('Field class name', 'RatingInput', required: true)));
        // The class name, like the auto-registered file (`fields/RatingInput.tsx`).
        $type = $name;
        $shadcn = $this->option('from-shadcn');

        if (! is_string($shadcn) || $shadcn === '') {
            return $this->generateComponent('field', $name, 'Forms\\Components', $type, $type);
        }

        $adapter = $this->option('no-frontend') ? 'react' : $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        // Asked once: generateComponent() reads it back.
        $this->input->setOption('adapter', $adapter);
        $field = ShadcnField::for($adapter, $shadcn);

        if (ShadcnField::installedPath($adapter, $field->component) === null && ! $this->option('no-frontend')) {
            $this->components->warn("resources/js/components/ui/{$field->component} is not installed. Add it first: npx shadcn@latest add {$field->component} (shadcn-vue@latest / shadcn-svelte@latest for Vue / Svelte).");
        }

        if (! $field->known) {
            $this->components->warn("No ready-made binding for '{$field->component}' (known: ".implode(', ', ShadcnField::KNOWN).'): finish the value binding in the generated component.');
        }

        return $this->generateComponent('field', $name, 'Forms\\Components', $type, $type, [...$field->replacements, 'shadcn' => $field->component], 'shadcn-field');
    }
}
