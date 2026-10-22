<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\SetupCommand;
use AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller;
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

            $result = AppEnvironment::composerRequire([$plugin['package']], output: fn (string $buffer) => $this->output->write($buffer), allowDependencyChanges: fn (): bool => AppEnvironment::allowDependencyChanges($this));

            if ($result->failed()) {
                return ['status' => 'failed', 'detail' => "composer require {$plugin['package']}", 'notes' => [], 'migrate' => false];
            }

            $newlyInstalled = true;
            // Its composer.json is in vendor now: read the real entry (plugin class, its own
            // migration tag) instead of the fallback used before it was installed.
            $plugin = PluginCatalog::get($slug);
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
            // Starter kit headers set up before `<AlfakitCopilot />` existed get it here.
            return ['status' => 'already', 'detail' => '', 'notes' => match ($slug) {
                'ai' => [...$notes, ...$this->addCopilotToStarterKitHeaders($files, $panelId, onlyChanges: true)],
                'teams' => [...$notes, ...$this->addTenantSwitcherToStarterKitHeaders($files, onlyChanges: true)],
                default => $notes,
            }, 'migrate' => false];
        }

        // An app table of the same name as the dependency's: its migration would fail.
        if (($conflict = PluginCatalog::tableConflict($slug)) !== null) {
            return ['status' => 'done', 'detail' => $registered ? 'registered on '.$panelId : '', 'notes' => [...$notes, $conflict], 'migrate' => false];
        }

        // Whether this run published anything to migrate: a plugin registered on another
        // panel has its migrations (and tables) already, so there is nothing to run.
        $published = $plugin['commands'] === [];

        foreach ($plugin['commands'] as $command) {
            $command = array_map(static fn (string $argument): string => str_replace('{panel}', $panelId, $argument), $command);

            // Migrations the app already has: a plugin installed earlier (only registered now),
            // or core tables another plugin of the same run published (forms and analytics
            // both publish the import/export tables).
            if (AppEnvironment::migrationsAlreadyPublished($command)) {
                continue;
            }

            $published = true;

            if (AppEnvironment::artisan($command)->failed()) {
                $notes[] = 'Run: php artisan '.implode(' ', $command);
            }
        }

        foreach ($plugin['notes'] as $note) {
            $notes[] = str_replace('{panel}', $panelId, $note);
        }

        // Teams changes the panel's shape (bundles and `--plugins=all` put it on the admin panel).
        if ($slug === 'teams') {
            $notes[] = "Teams turns the {$panelId} panel into a team workspace: its pages move under a team URL (/{$panelId}/{team}/…) and every resource is scoped to the current team."
                .($panelId === 'admin' ? ' To keep an admin panel without teams, put Teams on a panel of its own as the teams docs do: php artisan make:alfakit-panel app, php artisan alfakit:add teams --panel=app, then php artisan alfakit:remove teams --panel=admin.' : '');
        }

        // Without HasTeams on the user model no user has a team and every team page is a 404.
        if ($slug === 'teams' && $this->addHasTeamsToUserModel($files)) {
            $notes = array_values(array_filter($notes, static fn (string $note): bool => ! str_starts_with($note, 'Give users teams:')));
        }

        // Starter kit apps: the team menu goes in the app header (`<AlfakitTenantSwitcher />`).
        if ($slug === 'teams') {
            $notes = [...$notes, ...$this->addTenantSwitcherToStarterKitHeaders($files)];
        }

        // Starter kit apps: the copilot shows in the app header (`<AlfakitCopilot />`).
        if ($slug === 'ai') {
            $notes = [...$notes, ...$this->addCopilotToStarterKitHeaders($files, $panelId)];
        }

        return ['status' => 'done', 'detail' => $registered ? 'registered on '.$panelId : '', 'notes' => $notes, 'migrate' => $plugin['migrate'] && ($newlyInstalled || $published)];
    }

    /**
     * Give the user model the teams plugin's `HasTeams` trait and the two tenancy contracts
     * it implements (`HasTenants`, `HasDefaultTenant`). With spatie's `HasRoles` (Shield),
     * both traits define `teams()`, so the teams plugin's wins (`insteadof`). Returns whether
     * the model has it now; false leaves the "Give users teams" note in place.
     */
    protected function addHasTeamsToUserModel(Filesystem $files): bool
    {
        $model = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $path = app_path(str_replace('\\', '/', Str::after($model, 'App\\')).'.php');

        if (! $files->exists($path)) {
            return false;
        }

        $contents = $files->get($path);
        $updated = static::addHasTeams($contents);

        if ($updated === null) {
            return false;
        }

        if ($updated !== $contents) {
            $files->put($path, $updated);
            $this->components->task('Added HasTeams to '.class_basename($model).' (teams for every user)');
        }

        return true;
    }

    /**
     * `use HasTeams;` (or a `HasTeams::teams insteadof HasRoles` block next to spatie's
     * `HasRoles`), `implements HasDefaultTenant, HasTenants` and their imports. Unchanged
     * when the model has them; null when the class can't be edited safely.
     */
    public static function addHasTeams(string $contents): ?string
    {
        $trait = 'AlfaSix\\AlfakitTeams\\Concerns\\HasTeams';

        if (! str_contains($contents, 'HasTeams')) {
            if (preg_match('/^(?:final |abstract |readonly )*class\s+\w+[^{]*\{[ \t]*\n/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
                return null;
            }

            if (preg_match('/^[ \t]*use [^;{]*\bHasRoles\b[^;{]*;/m', $contents) === 1) {
                $position = (int) $match[0][1] + strlen($match[0][0]);
                $body = substr($contents, $position);

                // After the class's last top trait statement: the block keeps the teams plugin's teams().
                if (preg_match('/\A(?:[ \t]*(?:\/\*\*.*?\*\/|use [A-Z][^;]*;)[ \t]*\n)+/s', $body, $traits) !== 1) {
                    return null;
                }

                $block = "    use HasTeams {\n        HasTeams::teams insteadof HasRoles;\n    }\n";
                $contents = substr($contents, 0, $position).$traits[0].$block.substr($body, strlen($traits[0]));
                $contents = PanelProviderEditor::addImport($contents, $trait);
            } else {
                $contents = SetupCommand::addTrait($contents, $trait);

                if ($contents === null) {
                    return null;
                }
            }
        }

        foreach (['AlfaSix\\Alfakit\\Contracts\\HasDefaultTenant', 'AlfaSix\\Alfakit\\Contracts\\HasTenants'] as $contract) {
            $basename = class_basename($contract);

            if (preg_match('/^(?:final |abstract |readonly )*class\s+\w+[^{]*\bimplements\b[^{]*\b'.$basename.'\b/m', $contents) === 1) {
                continue;
            }

            $contents = (string) preg_replace_callback(
                '/^((?:final |abstract |readonly )*class\s+\w+(?:\s+extends\s+[\w\\\\]+)?)(\s+implements\s+([^{]+?))?(\s*\{)/m',
                static fn (array $m): string => $m[1].' implements '.($m[3] !== '' ? rtrim($m[3]).', ' : '').$basename.$m[4],
                $contents,
                1,
            );
            $contents = PanelProviderEditor::addImport($contents, $contract);
        }

        return $contents;
    }

    /**
     * Add `<AlfakitCopilot />` to the header block the starter kit installer wrote (headers
     * from before the component existed), or say how to.
     *
     * @return list<string>
     */
    protected function addCopilotToStarterKitHeaders(Filesystem $files, string $panelId, bool $onlyChanges = false): array
    {
        // A panel-mode install in a starter kit app: the kit's header isn't AlfaKit's.
        if (! $this->hasStarterKitPanel($files)) {
            return [];
        }

        $kit = StarterKitInstaller::detect(base_path());

        if ($kit === null) {
            return [];
        }

        $paths = StarterKitInstaller::files($kit);
        $missing = false;
        $notes = [];

        foreach ([$paths['sidebarHeader'], $paths['header']] as $relative) {
            $path = base_path($relative);

            if (! $files->exists($path) || ! str_contains($contents = $files->get($path), StarterKitInstaller::HEADER_MARKER)) {
                continue;
            }

            $updated = StarterKitInstaller::addCopilotToHeader($contents, $kit);

            if ($updated === null) {
                $missing = true;
            } elseif ($updated !== $contents) {
                $files->put($path, $updated);
                $notes[] = "Added <AlfakitCopilot /> to {$relative}.";
            }
        }

        if ($missing) {
            $notes[] = "Show the copilot in your app header: <AlfakitCopilot /> from @alfakit/{$kit}, next to <AlfakitNotifications />.";
        }

        $provider = PanelProviderEditor::path($panelId);
        $copilotOn = $files->exists($provider) && str_contains($files->get($provider), '->copilot(');

        if (! $copilotOn && (! $onlyChanges || $notes !== [])) {
            $notes[] = 'Turn the app header\'s copilot on: AiPlugin::make()->copilot()';
        }

        return $notes;
    }

    /**
     * Whether a panel of the app runs inside the starter kit (`->starterKit()`): only then do
     * the kit's header components belong in its header.
     */
    protected function hasStarterKitPanel(Filesystem $files): bool
    {
        foreach ($files->glob(app_path('Providers/Alfakit/*.php')) ?: [] as $provider) {
            if (str_contains($files->get($provider), '->starterKit(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add `<AlfakitTenantSwitcher />` to the header block the starter kit installer wrote, or
     * say how to: without it a team workspace has no way to its teams, members and settings.
     *
     * @return list<string>
     */
    protected function addTenantSwitcherToStarterKitHeaders(Filesystem $files, bool $onlyChanges = false): array
    {
        // A panel-mode install in a starter kit app: the kit's header isn't AlfaKit's.
        if (! $this->hasStarterKitPanel($files)) {
            return [];
        }

        $kit = StarterKitInstaller::detect(base_path());

        if ($kit === null) {
            return [];
        }

        $paths = StarterKitInstaller::files($kit);
        $missing = false;
        $notes = [];

        foreach ([$paths['sidebarHeader'], $paths['header']] as $relative) {
            $path = base_path($relative);

            if (! $files->exists($path) || ! str_contains($contents = $files->get($path), StarterKitInstaller::HEADER_MARKER)) {
                $missing = $missing || $files->exists($path);

                continue;
            }

            $updated = StarterKitInstaller::addTenantSwitcherToHeader($contents, $kit);

            if ($updated === null) {
                $missing = true;
            } elseif ($updated !== $contents) {
                $files->put($path, $updated);
                $notes[] = "Added <AlfakitTenantSwitcher /> (the team menu) to {$relative}.";
            }
        }

        if ($missing && ! $onlyChanges) {
            $notes[] = "Show the team menu in your app header: <AlfakitTenantSwitcher /> from @alfakit/{$kit}, next to <AlfakitSearch />.";
        }

        return $notes;
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

        if ($result->successful() && $slug === 'shield' && ! AppEnvironment::hasComposerPackage('spatie/laravel-permission')) {
            $notes = [...$notes, ...$this->forgetRoles($files)];
        }

        return $result->successful()
            ? ['status' => 'done', 'detail' => 'removed', 'notes' => $notes]
            : ['status' => 'failed', 'detail' => "composer remove {$plugin['package']}", 'notes' => $notes];
    }

    /**
     * spatie/laravel-permission went away with Shield: take `HasRoles` (which `alfakit:setup
     * --roles` added) off the user model, or every request loading it would fail, and point
     * at the roles field of the generated users resource.
     *
     * @return list<string>
     */
    protected function forgetRoles(Filesystem $files): array
    {
        $notes = [];
        $model = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $path = app_path(str_replace('\\', '/', Str::after($model, 'App\\')).'.php');
        $trait = 'Spatie\\Permission\\Traits\\HasRoles';

        if ($files->exists($path)) {
            $contents = $files->get($path);
            $updated = SetupCommand::removeTrait($contents, $trait);

            if ($updated !== $contents) {
                $files->put($path, $updated);
                $notes[] = 'Removed '.$trait.' from '.$model.' (spatie/laravel-permission was removed with Shield).';
            } elseif (str_contains($contents, 'HasRoles')) {
                $notes[] = 'Remove '.$trait.' from '.$model.': spatie/laravel-permission was removed with Shield.';
            }
        }

        foreach ([...($files->glob(app_path('Alfakit/Resources/UserResource.php')) ?: []), ...($files->glob(app_path('Alfakit/*/Resources/UserResource.php')) ?: [])] as $resource) {
            if (str_contains($files->get($resource), "'roles'")) {
                $notes[] = 'Remove the roles field, column and filter from '.Str::after($resource, base_path().'/').' (or regenerate it with php artisan alfakit:users --force).';
            }
        }

        return $notes;
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
