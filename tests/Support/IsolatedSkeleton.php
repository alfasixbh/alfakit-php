<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A copy of Testbench's Laravel skeleton for the running PHP process.
 *
 * Every test app boots from `vendor/orchestra/testbench-core/laravel` by default, so its
 * `public/build/manifest.json` (Inertia's asset version), `storage/` (logs, maintenance
 * mode, uploads, caches), `app/`, `config/` and `lang/` are shared by every process
 * running tests: `pest --parallel` workers, or two suites run at once. A test that writes
 * a Vite manifest then makes another worker's Inertia visits answer 409, and one that
 * empties a storage directory deletes files another worker is writing. Each process gets
 * its own copy instead; within a process tests still run one after another, so the
 * clean-up each test does keeps working as in a serial run.
 */
final class IsolatedSkeleton
{
    private static ?string $path = null;

    public static function path(): string
    {
        if (self::$path !== null && is_file(self::$path.'/bootstrap/app.php')) {
            return self::$path;
        }

        $source = (string) default_skeleton_path();
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $target = sys_get_temp_dir().'/alfakit-testbench-'.getmypid().'-'.(is_string($token) && $token !== '' ? $token : 'serial').'-'.bin2hex(random_bytes(4));

        self::copy($source, $target);

        // Testbench links the skeleton's `vendor` to the project's vendor directory, which
        // holds the skeleton (vendor/orchestra/testbench-core/laravel); the link itself may
        // not exist yet on a fresh install.
        symlink(dirname($source, 3), $target.'/vendor');

        register_shutdown_function(static function () use ($target): void {
            self::delete($target);
        });

        return self::$path = $target;
    }

    /**
     * Copy the skeleton without what tests or earlier runs left behind: the vendor link,
     * files in storage (its directories are kept), cached bootstrap files and Vite builds.
     */
    private static function copy(string $source, string $target): void
    {
        mkdir($target, 0755, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);

            if (self::skips($relative, $item)) {
                continue;
            }

            if ($item->isDir()) {
                @mkdir($target.'/'.$relative, 0755, true);
            } else {
                copy($item->getPathname(), $target.'/'.$relative);
            }
        }
    }

    private static function skips(string $relative, SplFileInfo $item): bool
    {
        if ($relative === 'vendor' || str_starts_with($relative, 'vendor/') || $item->isLink()) {
            return true;
        }

        foreach (['public/build', 'public/hot', 'public/storage'] as $generated) {
            if ($relative === $generated || str_starts_with($relative, $generated.'/')) {
                return true;
            }
        }

        if ($item->isFile() && $item->getFilename() !== '.gitignore') {
            return str_starts_with($relative, 'storage/') || str_starts_with($relative, 'bootstrap/cache/');
        }

        return false;
    }

    private static function delete(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
