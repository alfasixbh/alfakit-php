<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesStandaloneComponents;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:alfakit-form', description: 'Create a standalone Alfakit form for your own Inertia pages')]
class MakeFormCommand extends Command
{
    use GeneratesStandaloneComponents;

    protected $signature = 'make:alfakit-form {name? : The class name (ContactForm)} {--model= : A model the form edits (bound with ->record())} {--generate : Generate the fields from the model table} {--force}';

    public function handle(): int
    {
        return $this->generateStandaloneComponent('form', 'ContactForm');
    }
}
