<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Support\Assets\ViteManifest;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:optimize-clear', description: 'Remove the cached component discovery')]
class OptimizeClearCommand extends Command
{
    public function handle(Filesystem $filesystem): int
    {
        $filesystem->deleteDirectory((string) config('alfakit.cache_path'));

        foreach (alfakit()->getPanels() as $panel) {
            $panel->flushComponentCache();
        }

        ViteManifest::flush();

        $this->components->info('Alfakit component cache cleared.');

        return self::SUCCESS;
    }
}
