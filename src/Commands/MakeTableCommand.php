<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\GeneratesStandaloneComponents;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:alfakit-table', description: 'Create a standalone Alfakit table for your own Inertia pages')]
class MakeTableCommand extends Command
{
    use GeneratesStandaloneComponents;

    protected $signature = 'make:alfakit-table {name? : The class name (OrdersTable)} {--model= : The model the table lists} {--generate : Generate the columns from the model table} {--force}';

    public function handle(): int
    {
        return $this->generateStandaloneComponent('table', 'OrdersTable');
    }
}
