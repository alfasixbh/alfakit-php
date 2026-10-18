<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Support\Assets\ViteManifest;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:optimize', description: 'Cache discovered resources, pages and widgets for every panel, and the Vite manifest')]
class OptimizeCommand extends Command
{
    public function handle(Filesystem $filesystem): int
    {
        $directory = (string) config('alfakit.cache_path');
        $filesystem->ensureDirectoryExists($directory);

        foreach (alfakit()->getPanels() as $panel) {
            $components = $panel->getDiscoverableComponents();

            $filesystem->put(
                "{$directory}/{$panel->getId()}.php",
                '<?php return '.var_export($components, true).';'.PHP_EOL,
            );

            $panel->flushComponentCache();

            $this->components->task("Cached {$panel->getId()} panel (".count($components['resources']).' resources, '.count($components['pages']).' pages, '.count($components['widgets']).' widgets)');
        }

        // The renderer's Vite manifest as PHP (served from OPcache instead of decoding the JSON
        // on every page load); ignored as soon as a new build changes the manifest.
        if (ViteManifest::cache($manifest = ViteManifest::path(), $filesystem)) {
            $this->components->task('Cached Vite manifest ('.basename(dirname($manifest)).'/'.basename($manifest).')');
        }

        return self::SUCCESS;
    }
}
