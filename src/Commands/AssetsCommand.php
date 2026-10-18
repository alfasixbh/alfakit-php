<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Support\Assets\Assets;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:assets', description: 'Publish the stylesheets and scripts registered with Assets::register() to public/')]
class AssetsCommand extends Command
{
    protected $signature = 'alfakit:assets';

    public function handle(Filesystem $filesystem): int
    {
        $published = 0;
        $failed = false;

        foreach (Assets::all() as $asset) {
            if ($asset->isRemote()) {
                continue;
            }

            $target = public_path($asset->getPublicPath());

            if (! is_file($asset->getPath())) {
                $this->components->error("{$asset->getPackage()}/{$asset->getId()}: {$asset->getPath()} does not exist. Build the asset first.");
                $failed = true;

                continue;
            }

            $filesystem->ensureDirectoryExists(dirname($target));
            $filesystem->copy($asset->getPath(), $target);
            $published++;

            $this->components->task('Published '.Str::after($target, base_path().'/'));
        }

        if ($published === 0 && ! $failed) {
            $this->components->info('No local assets are registered.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
