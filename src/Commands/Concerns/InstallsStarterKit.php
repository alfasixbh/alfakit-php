<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller;
use Illuminate\Support\Str;

/**
 * `alfakit:install --starter-kit`: Alfakit inside an app made from Laravel's React, Vue or
 * Svelte starter kit (see StarterKitInstaller for what each step writes).
 */
trait InstallsStarterKit
{
    protected function installStarterKit(string $adapter, string $panelId, string $path, bool $force): int
    {
        $kit = StarterKitInstaller::detect(base_path());

        if ($kit === null) {
            $this->components->error('No Laravel starter kit found: expected resources/js/layouts/app-layout.tsx (React), AppLayout.vue (Vue) or AppLayout.svelte (Svelte) and the app sidebar next to it.');

            return self::FAILURE;
        }

        if ($kit !== $adapter) {
            $this->components->warn("Using the {$kit} starter kit found in resources/js (not --adapter={$adapter}).");
        }

        $this->components->info("Installing AlfaKit into the {$kit} starter kit…");

        $changes = (new StarterKitInstaller(base_path(), $kit, $force))->install($panelId, $path, headerWidgets: ! $this->option('no-header-widgets'));

        foreach ($changes as $change) {
            $label = str_pad(Str::upper($change['status']), 9);
            $this->line('  <fg='.match ($change['status']) {
                'created', 'updated' => 'green',
                'skipped' => 'yellow',
                default => 'gray',
            }.">{$label}</> {$change['path']} <fg=gray>{$change['note']}</>");
        }

        $this->createNotificationsTable();

        if (! $this->option('no-npm')) {
            $this->installNpmPackage($kit);
            $this->buildFrontend();
        }

        $this->newLine();
        $this->components->info('AlfaKit runs inside your starter kit app.');
        $this->components->bulletList([
            'Run the migrations: php artisan migrate',
            'Create a resource: php artisan make:alfakit-resource Post --generate',
            $this->frontendBuilt
                ? 'Visit /'.trim($path, '/')
                : 'Build the frontend (npm run build, or npm run dev) and visit /'.trim($path, '/').'; until then AlfaKit pages fail with "Unable to locate file in Vite manifest"',
            'Sign in with the app\'s own login; the panel links appear in the app sidebar',
            'In production, implement AlfaSix\\Alfakit\\Contracts\\CanAccessPanel on your User model',
        ]);

        return self::SUCCESS;
    }
}
