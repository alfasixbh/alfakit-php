<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesStandaloneComponents;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:alfakit-component', description: 'Create a standalone Alfakit component (form, table, infolist, actions and widgets in one) for your own Inertia pages')]
class MakeComponentCommand extends Command
{
    use GeneratesStandaloneComponents;

    protected $signature = 'make:alfakit-component {name? : The class name (AccountOverview)} {--model= : A model the component works with} {--force}';

    public function handle(): int
    {
        return $this->generateStandaloneComponent('component', 'AccountOverview');
    }
}
