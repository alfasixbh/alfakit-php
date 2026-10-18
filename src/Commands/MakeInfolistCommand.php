<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesStandaloneComponents;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:alfakit-infolist', description: 'Create a standalone Alfakit infolist for your own Inertia pages')]
class MakeInfolistCommand extends Command
{
    use GeneratesStandaloneComponents;

    protected $signature = 'make:alfakit-infolist {name? : The class name (OrderDetails)} {--model= : The model the infolist shows (bound with ->record())} {--force}';

    public function handle(): int
    {
        return $this->generateStandaloneComponent('infolist', 'OrderDetails');
    }
}
