<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

/**
 * Adding and removing first-party plugins (`alfakit:setup`, `alfakit:add`,
 * `alfakit:remove`): Composer behind a confirmation (or printed with `--no-install`), the
 * registration on the panel provider, and the plugin's published migrations.
 */
trait ManagesPlugins
{
    /**
     * `$chain` adds default calls to the registration (`->navigationGroup('Settings')`).
     *
     * @return array{status: string, detail: string, notes: list<string>, migrate: bool}
     */
    protected function addPluginTo(Filesystem $files, string $slug, string $panelId, string $chain = ''): array
    {
        $plugin = PluginCatalog::get($slug);
        $newlyInstalled = false;
        $notes = [];

        if (! AppEnvironment::hasComposerPackage($plugin['package'])) {
            if ($this->option('no-install')) {
                return ['status' => 'skipped', 'detail' => "composer require {$plugin['package']}", 'notes' => [], 'migrate' => false];
            }

            if ($this->input->isInteractive() && ! confirm("Install {$plugin['package']} with Composer?", true)) {
                return ['status' => 'skipped', 'detail' => '', 'notes' => [], 'migrate' => false];
            }

            $result = AppEnvironment::composerRequire([$plugin['package']], output: fn (string $buffer) => $this->output->write($buffer));

            if ($result->failed()) {
                return ['status' => 'failed', 'detail' => "composer require {$plugin['package']}", 'notes' => [], 'migrate' => false];
            }

            $newlyInstalled = true;
        }

        $provider = PanelProviderEditor::path($panelId);
        $registered = false;

        if ($files->exists($provider)) {
            $contents = $files->get($provider);
            $updated = PanelProviderEditor::addPlugin($contents, $plugin['class'], $chain);

            if ($updated === null) {
                $notes[] = "Register the plugin: ->plugin(\\{$plugin['class']}::make())";
            } elseif ($updated !== $contents) {
                $files->put($provider, $updated);
                $registered = true;
            }
        } else {
            $notes[] = "Register the plugin on your panel: ->plugin(\\{$plugin['class']}::make())";
        }

        if (! $newlyInstalled && ! $registered) {
            return ['status' => 'already', 'detail' => '', 'notes' => $notes, 'migrate' => false];
        }

        foreach ($plugin['commands'] as $command) {
            $command = array_map(static fn (string $argument): string => str_replace('{panel}', $panelId, $argument), $command);

            if (AppEnvironment::artisan($command)->failed()) {
                $notes[] = 'Run: php artisan '.implode(' ', $command);
            }
        }

        foreach ($plugin['notes'] as $note) {
            $notes[] = str_replace('{panel}', $panelId, $note);
        }

        return ['status' => 'done', 'detail' => $registered ? 'registered on '.$panelId : '', 'notes' => $notes, 'migrate' => $plugin['migrate']];
    }

    /**
     * Unregister the plugin, then `composer remove` it. Its tables stay in place.
     *
     * @return array{status: string, detail: string, notes: list<string>}
     */
    protected function removePluginFrom(Filesystem $files, string $slug, string $panelId): array
    {
        $plugin = PluginCatalog::get($slug);
        $provider = PanelProviderEditor::path($panelId);
        $unregistered = false;
        $notes = [];

        if ($files->exists($provider)) {
            $contents = $files->get($provider);
            $updated = PanelProviderEditor::removePlugin($contents, $plugin['class']);

            if ($updated !== $contents) {
                $files->put($provider, $updated);
                $unregistered = true;
            }
        }

        if (! AppEnvironment::hasComposerPackage($plugin['package'])) {
            return ['status' => $unregistered ? 'done' : 'already', 'detail' => $unregistered ? 'unregistered from '.$panelId : 'not installed', 'notes' => []];
        }

        $other = $this->otherPanelsUsing($files, $plugin['class'], $panelId);

        if ($other !== []) {
            return ['status' => 'done', 'detail' => 'unregistered from '.$panelId.'; still used by '.implode(', ', $other).', so the package stays', 'notes' => []];
        }

        if ($plugin['migrate']) {
            $notes[] = "{$slug}: its database tables were left in place. Drop them with a migration if you no longer need the data.";
        }

        if ($this->option('no-install')) {
            return ['status' => 'done', 'detail' => "unregistered; run composer remove {$plugin['package']}", 'notes' => $notes];
        }

        if ($this->input->isInteractive() && ! confirm("Remove {$plugin['package']} with Composer?", true)) {
            return ['status' => 'done', 'detail' => 'unregistered; package kept', 'notes' => $notes];
        }

        $result = AppEnvironment::composerRemove([$plugin['package']], output: fn (string $buffer) => $this->output->write($buffer));

        return $result->successful()
            ? ['status' => 'done', 'detail' => 'removed', 'notes' => $notes]
            : ['status' => 'failed', 'detail' => "composer remove {$plugin['package']}", 'notes' => $notes];
    }

    /**
     * Other panel providers that still register the plugin.
     *
     * @return array<int, string>
     */
    protected function otherPanelsUsing(Filesystem $files, string $class, string $panelId): array
    {
        $panels = [];

        foreach ($files->glob(app_path('Providers/Alfakit/*PanelProvider.php')) ?: [] as $path) {
            $id = Str::kebab(Str::beforeLast(basename($path, '.php'), 'PanelProvider'));

            if ($id !== $panelId && PanelProviderEditor::hasPlugin($files->get($path), $class)) {
                $panels[] = $id;
            }
        }

        return $panels;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<int, string>|null The slugs, or null (after an error) when one is unknown.
     */
    protected function validatePluginSlugs(array $slugs): ?array
    {
        $slugs = array_values(array_unique(array_filter(array_map(static fn (string $slug): string => Str::slug(trim($slug)), $slugs))));
        $unknown = array_values(array_filter($slugs, static fn (string $slug): bool => ! PluginCatalog::has($slug)));

        if ($unknown !== []) {
            $this->components->error('Unknown plugin(s): '.implode(', ', $unknown).'. Run php artisan alfakit:add --list to see them.');

            return null;
        }

        return $slugs;
    }
}
