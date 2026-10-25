<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\ManagesPlugins;
use AlfaSix\Alfakit\Commands\Concerns\ShowsBanner;
use AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginBundles;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The guided setup: detects the app, installs AlfaKit in the right mode and walks through
 * users, roles, AI, realtime, plugins, branding and demo data, ending with a summary.
 * Every answer has a flag, so it also runs unattended (`--no-interaction`), and running it
 * again skips what is already done.
 */
#[AsCommand(name: 'alfakit:setup', description: 'Set up AlfaKit step by step: install, users, roles, AI, realtime, plugins, branding and demo data')]
class SetupCommand extends Command
{
    use CanGenerateFiles;
    use ManagesPlugins;
    use ShowsBanner;

    public const MODES = ['panel', 'starter-kit', 'standalone'];

    protected $signature = 'alfakit:setup
        {--adapter= : react, vue or svelte (default: detected from package.json)}
        {--panel=admin : The panel id}
        {--mode= : panel, starter-kit or standalone (default: starter-kit when one is detected)}
        {--users : Generate the users resource (alfakit:users)}
        {--roles : Roles and permissions with the Shield plugin}
        {--ai= : Comma-separated laravel/ai providers to configure (openai,anthropic,…)}
        {--realtime : Set up Reverb and live notifications (alfakit:realtime)}
        {--plugins= : Comma-separated first-party plugins (shield,activity-log,comments,…), or all}
        {--bundle= : Comma-separated plugin bundles: saas, content, backoffice, support, ops (or all)}
        {--brand-color= : The primary colour (#hex or any CSS colour)}
        {--logo= : The brand logo: a path under public/ or a URL}
        {--font= : The font family (e.g. Inter)}
        {--demo : Write and run a demo data seeder}
        {--admin-email= : Create an admin user to sign in with (with --admin-name and --admin-password)}
        {--admin-name= : The name of the admin user (default: Admin)}
        {--admin-password= : The password of the admin user}
        {--migrate : Run the migrations at the end without asking}
        {--no-install : Never run composer or npm; print the commands instead}
        {--force : Overwrite generated files}';

    /** @var array<int, array{step: string, status: string, detail: string}> */
    protected array $summary = [];

    /** @var array<int, string> */
    protected array $nextSteps = [];

    /** How AlfaKit is installed in this run (`panel`, `starter-kit` or `standalone`). */
    protected ?string $mode = null;

    protected bool $needsMigrations = false;

    /** @var array<string, string> Default calls per plugin slug, from the chosen bundles. */
    protected array $pluginDefaults = [];

    public function handle(Filesystem $files): int
    {
        $this->summary = [];
        $this->nextSteps = [];
        $this->mode = null;
        $this->needsMigrations = false;

        $this->pluginDefaults = [];

        $panelId = Str::slug((string) ($this->option('panel') ?: 'admin'));
        $bundles = $this->chooseBundles();

        if ($bundles === null) {
            return self::FAILURE;
        }

        $this->banner();
        $this->components->info('AlfaKit setup');

        $detected = $this->detect();
        $mode = $this->chooseMode($detected, $panelId);

        if ($mode === null) {
            return self::FAILURE;
        }

        $this->mode = $mode;

        if (! $this->install($mode, $panelId, $detected['adapter'])) {
            $this->renderSummary();

            return self::FAILURE;
        }

        $hasPanel = $mode !== 'standalone';
        $bundlePlugins = $hasPanel ? $this->bundlePlugins($bundles) : [];
        $roles = $hasPanel && (in_array('shield', $bundlePlugins, true) || $this->wants('roles', 'Add roles and permissions (Shield plugin)?', 'Role resource and generated policies on spatie/laravel-permission; adds a Composer package and tables.'));

        if ($roles) {
            $this->installPlugin($files, 'shield', $panelId);
            $this->addHasRolesTrait($files);
        } elseif ($hasPanel) {
            $this->record('Roles and permissions', 'skipped');
        }

        $usersResourceExists = $hasPanel && ! $this->option('force')
            && is_file($this->getGenerationPath().str_replace('\\', '/', $this->panelNamespaceSuffix($panelId)).'/Resources/UserResource.php');

        if ($usersResourceExists) {
            // A re-run: done earlier, so neither asked again nor reported as skipped.
            $this->record('Users resource', 'already');
        } elseif ($hasPanel && $this->wants('users', 'Generate a users resource (invitations, impersonation, suspend/ban, sessions)?', 'Writes a UserResource and a few helper classes into app/Alfakit, plus one migration; no packages.')) {
            $this->runStep('Users resource', 'alfakit:users', ['--panel' => $panelId, '--roles' => $roles, '--force' => (bool) $this->option('force')]);
            $this->needsMigrations = true;
        } else {
            $this->record('Users resource', 'skipped', $hasPanel ? '' : 'no panel in standalone mode');
        }

        $this->setUpAi();

        if ($hasPanel && $this->wants('realtime', 'Set up realtime notifications (Laravel Reverb)?', 'Instant toasts and bell updates over WebSockets; installs laravel/reverb and laravel-echo.')) {
            $this->runStep('Realtime', 'alfakit:realtime', ['--panel' => $panelId, '--no-install' => (bool) $this->option('no-install')]);
        } else {
            $this->record('Realtime', 'skipped');
        }

        if ($bundles !== [] && ! $hasPanel) {
            $this->record('Bundles', 'skipped', 'bundles need a panel');
        }

        if ($hasPanel) {
            foreach (array_values(array_unique([...array_diff($bundlePlugins, ['shield']), ...$this->choosePlugins($roles, $bundlePlugins !== [])])) as $slug) {
                $this->installPlugin($files, $slug, $panelId);
            }

            // A starter kit panel uses the app's own theme: brand colour, logo and font do nothing there.
            if ($mode === 'starter-kit') {
                $this->record('Branding', 'skipped', 'the starter kit\'s own theme applies');
            } else {
                $this->brand($files, $panelId);
            }
        }

        if ($this->wants('demo', 'Add demo data (a seeder with an admin and sample users)?', 'For local trying-out only; never seeded in production.')) {
            $this->demo($files);
        } else {
            $this->record('Demo data', 'skipped');
        }

        $this->migrate();

        if ($mode === 'panel') {
            $this->createAdminUser($panelId);
        }

        $this->renderSummary();

        return collect($this->summary)->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The panel's own login needs a user. With `--admin-email` the user is created from the
     * flags; interactively the wizard offers to create one; otherwise it is a next step.
     */
    protected function createAdminUser(string $panelId): void
    {
        $email = $this->option('admin-email');
        $name = $this->option('admin-name');
        $password = $this->option('admin-password');

        // Re-running the wizard on an app that has users: nothing to create or suggest.
        if ((! is_string($email) || $email === '') && $this->anyUserExists()) {
            $this->record('Admin user', 'already', 'the app has users');

            return;
        }

        // Interactively, ask here: the user is created in a fresh process (below), which can't prompt.
        if ((! is_string($email) || $email === '') && $this->input->isInteractive() && confirm('Create an admin user to sign in with?', true, hint: 'Asks for a name, email address and password (php artisan make:alfakit-user).')) {
            $name = text('Name', 'Admin', 'Admin', required: true);
            $email = text('Email address', required: true, validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address.');
            $password = password('Password', required: true);
        }

        if (is_string($email) && $email !== '') {
            if (! is_string($password) || $password === '') {
                $this->record('Admin user', 'failed', '--admin-password is required with --admin-email');

                return;
            }

            // Re-running the wizard: the user is already there, so this step is done.
            if ($this->userExists($email)) {
                $this->record('Admin user', 'already', $email);

                return;
            }

            $arguments = [
                '--panel' => $panelId,
                '--name' => is_string($name) && $name !== '' ? $name : 'Admin',
                '--email' => $email,
                '--password' => $password,
            ];

            // A panel provider written by this run isn't booted in this process yet:
            // create the user in a fresh process, which loads it from bootstrap/providers.php.
            if (! alfakit()->hasPanel($panelId)) {
                $this->newLine();
                $this->components->info('Admin user');

                $result = AppEnvironment::artisan(['make:alfakit-user', ...collect($arguments)->map(fn (mixed $value, string $key): string => $key.'='.$value)->values()->all(), '--no-interaction'], fn (string $buffer) => $this->output->write($buffer));
                $this->record('Admin user', $result->successful() ? 'done' : 'failed');

                return;
            }

            $this->runStep('Admin user', 'make:alfakit-user', $arguments);

            return;
        }

        $this->record('Admin user', 'skipped');
        $this->nextSteps[] = 'Create a user to sign in with: php artisan make:alfakit-user';
    }

    /**
     * Whether the app has any user yet (false when the users table doesn't exist).
     */
    protected function anyUserExists(): bool
    {
        $model = config('auth.providers.'.config('auth.guards.'.config('auth.defaults.guard', 'web').'.provider', 'users').'.model');

        try {
            return is_string($model) && class_exists($model) && $model::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the app's user model already has a user with this email address. The model
     * comes from the default auth provider, the one `make:alfakit-user` creates users with.
     */
    protected function userExists(string $email): bool
    {
        $model = config('auth.providers.'.config('auth.guards.'.config('auth.defaults.guard', 'web').'.provider', 'users').'.model');

        try {
            return is_string($model) && class_exists($model) && $model::query()->where('email', $email)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------ Detection and install

    /**
     * @return array{adapter: ?string, starterKit: ?string, fortify: bool, inertia: bool, installed: bool}
     */
    protected function detect(): array
    {
        $starterKit = class_exists(StarterKitInstaller::class) && method_exists(StarterKitInstaller::class, 'detect')
            ? StarterKitInstaller::detect(base_path())
            : (AppEnvironment::starterKitLayout() === null ? null : AppEnvironment::adapter());

        $adapter = $this->option('adapter') ?: ($starterKit ?? AppEnvironment::adapter());

        $detected = [
            'adapter' => is_string($adapter) ? $adapter : null,
            'starterKit' => $starterKit,
            'fortify' => AppEnvironment::hasFortify(),
            'inertia' => AppEnvironment::hasComposerPackage('inertiajs/inertia-laravel'),
            'installed' => is_file(config_path('alfakit.php')),
        ];

        $this->components->twoColumnDetail('Frontend adapter', $detected['adapter'] ?? '<fg=yellow>not detected</>');
        $this->components->twoColumnDetail('Laravel starter kit', $starterKit === null ? 'no' : "<fg=green>{$starterKit}</>");
        $this->components->twoColumnDetail('Laravel Fortify', $detected['fortify'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Inertia (inertiajs/inertia-laravel)', $detected['inertia'] ? 'yes' : '<fg=yellow>no</>');
        $this->components->twoColumnDetail('AlfaKit config', $detected['installed'] ? 'published' : 'not yet');
        $this->newLine();

        return $detected;
    }

    /**
     * @param  array{adapter: ?string, starterKit: ?string, fortify: bool, inertia: bool, installed: bool}  $detected
     */
    protected function chooseMode(array $detected, string $panelId): ?string
    {
        $default = $this->installedMode($panelId) ?? ($detected['starterKit'] !== null ? 'starter-kit' : 'panel');
        $mode = $this->option('mode');

        if (is_string($mode) && $mode !== '') {
            if (! in_array($mode, self::MODES, true)) {
                $this->components->error("Unknown mode [{$mode}]. Use panel, starter-kit or standalone.");

                return null;
            }

            return $mode;
        }

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return (string) select('How do you want to use AlfaKit?', [
            'panel' => 'Admin panel (its own login, layout and navigation)',
            'starter-kit' => 'Inside my Laravel starter kit (its auth, layout and sidebar)',
            'standalone' => 'Components in my own Inertia pages (no panel)',
        ], $default);
    }

    protected function install(string $mode, string $panelId, ?string $adapter): bool
    {
        // Installed, unless the renderer's npm package is missing (the npm step was declined or
        // interrupted): then run the installer again, which skips what exists and installs it.
        $renderer = $adapter ?? AppEnvironment::adapter();
        $rendererMissing = ! $this->option('no-install') && $renderer !== null && ! AppEnvironment::hasNpmPackage("@alfakit/{$renderer}");

        if ($this->isInstalled($mode, $panelId) && ! $rendererMissing) {
            $this->record('Install ('.$mode.')', 'already');

            return true;
        }

        $arguments = array_filter([
            '--panel' => $panelId,
            '--adapter' => $adapter,
            '--no-npm' => (bool) $this->option('no-install'),
            '--force' => (bool) $this->option('force'),
        ], static fn (mixed $value): bool => $value !== null && $value !== false);

        if ($mode === 'standalone') {
            $arguments['--standalone'] = true;
        }

        if ($mode === 'starter-kit') {
            if ($this->installSupportsStarterKit()) {
                $arguments['--starter-kit'] = true;
            } else {
                $this->components->warn('This AlfaKit version has no starter-kit install yet; installing a regular panel instead.');
            }
        }

        if (! $this->input->isInteractive()) {
            $arguments['--no-interaction'] = true;
        }

        $code = $this->call('alfakit:install', $arguments);
        $this->record('Install ('.$mode.')', $code === self::SUCCESS ? 'done' : 'failed');

        // A panel install adds the notifications table migration.
        $this->needsMigrations = $this->needsMigrations || ($code === self::SUCCESS && $mode !== 'standalone');

        return $code === self::SUCCESS;
    }

    /**
     * Installed means the mode's main file exists: the panel provider, or the standalone
     * styles in resources/css/app.css.
     */
    protected function isInstalled(string $mode, string $panelId): bool
    {
        if ($mode === 'standalone') {
            $css = resource_path('css/app.css');

            return is_file($css) && str_contains((string) file_get_contents($css), 'standalone.css');
        }

        return is_file(PanelProviderEditor::path($panelId));
    }

    /**
     * The mode an earlier run installed, read from the panel provider (or, without one, the
     * standalone stylesheet import): a re-run without `--mode` keeps it instead of guessing
     * from the starter kit again.
     */
    protected function installedMode(string $panelId): ?string
    {
        $path = PanelProviderEditor::path($panelId);

        if (! is_file($path)) {
            // Standalone installs have no panel provider, only the stylesheet import.
            return $this->isInstalled('standalone', $panelId) ? 'standalone' : null;
        }

        return str_contains((string) file_get_contents($path), '->starterKit(') ? 'starter-kit' : 'panel';
    }

    protected function installSupportsStarterKit(): bool
    {
        $application = $this->getApplication();

        return $application !== null && $application->has('alfakit:install')
            && $application->find('alfakit:install')->getDefinition()->hasOption('starter-kit');
    }

    // ------------------------------------------------------------------ Steps

    /**
     * A yes/no step: its flag, else a question that defaults to no with a one-line hint
     * (interactive), else no. The install stays minimal unless you opt in.
     */
    protected function wants(string $option, string $question, string $hint): bool
    {
        if ($this->option($option)) {
            return true;
        }

        return $this->input->isInteractive() && confirm($question, false, hint: $hint);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function runStep(string $step, string $command, array $arguments): bool
    {
        if (! $this->input->isInteractive()) {
            $arguments['--no-interaction'] = true;
        }

        $this->newLine();
        $this->components->info($step);

        $code = $this->call($command, array_filter($arguments, static fn (mixed $value): bool => $value !== false && $value !== null));
        $this->record($step, $code === self::SUCCESS ? 'done' : 'failed');

        return $code === self::SUCCESS;
    }

    protected function setUpAi(): void
    {
        $providers = $this->option('ai');

        if (is_string($providers) && trim($providers) !== '') {
            $this->runStep('AI providers', 'alfakit:ai-setup', ['--providers' => $providers, '--no-install' => (bool) $this->option('no-install')]);

            return;
        }

        if ($this->input->isInteractive() && confirm('Set up AI providers (laravel/ai API keys)?', false, hint: 'Stores provider API keys in .env and tests them; installs laravel/ai if missing.')) {
            $this->runStep('AI providers', 'alfakit:ai-setup', ['--no-install' => (bool) $this->option('no-install')]);

            return;
        }

        $this->record('AI providers', 'skipped');
    }

    /**
     * The bundles of `--bundle`, or null (after an error) when one is unknown.
     *
     * @return list<string>|null
     */
    protected function chooseBundles(): ?array
    {
        $option = $this->option('bundle');

        if (! is_string($option) || trim($option) === '') {
            return [];
        }

        $bundles = array_values(array_unique(array_filter(array_map(static fn (string $bundle): string => Str::slug(trim($bundle)), explode(',', $option)))));

        if (in_array('all', $bundles, true)) {
            return PluginBundles::names();
        }
        $unknown = array_values(array_filter($bundles, static fn (string $bundle): bool => ! PluginBundles::has($bundle)));

        if ($unknown !== []) {
            $this->components->error('Unknown bundle(s): '.implode(', ', $unknown).'. Use '.implode(', ', PluginBundles::names()).'.');

            return null;
        }

        return $bundles;
    }

    /**
     * The plugins of the chosen bundles, remembering each bundle's defaults.
     *
     * @param  list<string>  $bundles
     * @return list<string>
     */
    protected function bundlePlugins(array $bundles): array
    {
        $plugins = [];

        foreach ($bundles as $bundle) {
            foreach (PluginBundles::plugins($bundle) as $slug) {
                $plugins[] = $slug;
                $this->pluginDefaults[$slug] ??= PluginBundles::defaults($bundle, $slug);
            }

            $this->record('Bundle: '.PluginBundles::BUNDLES[$bundle]['label'], 'done', implode(', ', PluginBundles::plugins($bundle)));
        }

        return array_values(array_unique($plugins));
    }

    /**
     * @return array<int, string>
     */
    protected function choosePlugins(bool $roles, bool $fromBundles = false): array
    {
        $option = $this->option('plugins');

        if (is_string($option) && trim($option) === 'all') {
            $plugins = array_keys(PluginCatalog::all());
        } elseif (is_string($option) && trim($option) !== '') {
            $plugins = $this->validatePluginSlugs(explode(',', $option));

            if ($plugins === null) {
                $this->record('Plugins', 'failed', 'unknown plugin; see php artisan alfakit:add --list');

                return [];
            }
        } elseif ($this->input->isInteractive()) {
            $options = array_diff_key(PluginCatalog::options(), $roles ? ['shield' => true] : []);

            // Opt in first, so "no plugins" is an obvious answer rather than an empty list.
            $plugins = confirm('Add first-party plugins now?', false, hint: 'Optional: AlfaKit works without them. Add any later with php artisan alfakit:add.')
                ? multiselect('Which first-party plugins do you want?', $options, default: [], scroll: 10, hint: 'Space to tick, Enter to confirm. Nothing ticked installs none.')
                : [];
        } else {
            $plugins = [];
        }

        if ($plugins === [] && ! $fromBundles) {
            $this->record('Plugins', 'skipped');
        }

        return $roles ? array_values(array_diff($plugins, ['shield'])) : $plugins;
    }

    /**
     * Require the package, register the plugin on the panel provider and publish its tables.
     */
    protected function installPlugin(Filesystem $files, string $slug, string $panelId): void
    {
        $result = $this->addPluginTo($files, $slug, $panelId, $this->pluginDefaults[$slug] ?? '');

        $this->nextSteps = [...$this->nextSteps, ...$result['notes']];
        $this->needsMigrations = $this->needsMigrations || $result['migrate'];
        $this->record('Plugin: '.$slug, $result['status'], $result['detail']);
    }

    /**
     * Shield's roles need spatie's HasRoles trait on the user model.
     */
    protected function addHasRolesTrait(Filesystem $files): void
    {
        $model = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $path = app_path(str_replace('\\', '/', Str::after($model, 'App\\')).'.php');

        if (! $files->exists($path)) {
            $this->nextSteps[] = "Add Spatie\\Permission\\Traits\\HasRoles to {$model}";

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'HasRoles')) {
            $this->forgetHasRolesNote();

            return;
        }

        if ($this->input->isInteractive() && ! confirm('Add the HasRoles trait to '.class_basename($model).'?', true)) {
            $this->nextSteps[] = "Add Spatie\\Permission\\Traits\\HasRoles to {$model}";

            return;
        }

        $updated = static::addTrait($contents, 'Spatie\\Permission\\Traits\\HasRoles');

        if ($updated === null) {
            $this->nextSteps[] = "Add Spatie\\Permission\\Traits\\HasRoles to {$model}";

            return;
        }

        $files->put($path, $updated);
        $this->forgetHasRolesNote();
        $this->record('HasRoles on '.class_basename($model), 'done');
    }

    /**
     * The Shield plugin's install notes ask for the trait; drop that once the model has it.
     */
    protected function forgetHasRolesNote(): void
    {
        $this->nextSteps = array_values(array_filter($this->nextSteps, static fn (string $step): bool => ! str_starts_with($step, 'Add Spatie\\Permission\\Traits\\HasRoles to your User model')));
    }

    /**
     * Add a trait inside a class (and its import), joined to the class's last trait statement.
     */
    public static function addTrait(string $contents, string $trait): ?string
    {
        $basename = class_basename($trait);

        if (preg_match('/^(?:final |abstract |readonly )*class\s+\w+[^{]*\{[ \t]*\n/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $position = (int) $match[0][1] + strlen($match[0][0]);
        $head = substr($contents, 0, $position);
        $body = substr($contents, $position);

        // The trait statements at the top of the class body (docblocks between them allowed).
        if (preg_match('/\A(?:[ \t]*(?:\/\*\*.*?\*\/|use [A-Z][^;]*;)[ \t]*\n)+/s', $body, $traits) === 1 && preg_match('/^[ \t]*use [A-Z]/m', $traits[0]) === 1) {
            // Join the last trait statement (`use HasFactory, Notifiable, HasRoles;`), the way
            // Laravel's own models list them, which also keeps Pint's laravel preset happy.
            $block = (string) preg_replace('/^([ \t]*use [A-Z][^;]*);(?![\s\S]*^[ \t]*use [A-Z])/m', '$1, '.$basename.';', $traits[0], 1);
            $body = $block.substr($body, strlen($traits[0]));
        } else {
            $body = "    use {$basename};\n\n".$body;
        }

        return PanelProviderEditor::addImport($head.$body, $trait);
    }

    /**
     * Remove a trait the wizard added (`use HasRoles;`, or `HasRoles` in a comma-separated
     * trait statement) and its import when nothing else uses it. Unchanged when absent.
     */
    public static function removeTrait(string $contents, string $trait): string
    {
        $basename = preg_quote(class_basename($trait), '/');

        // `use HasRoles;` on its own line.
        $updated = (string) preg_replace('/^[ \t]*use '.$basename.';[ \t]*\n/m', '', $contents);

        if ($updated === $contents) {
            // `use A, HasRoles, B;` inside the class body.
            $updated = (string) preg_replace_callback(
                '/^([ \t]*use )([A-Z][^;]*);/m',
                static function (array $match) use ($basename): string {
                    $names = array_map('trim', explode(',', $match[2]));

                    if (count($names) < 2 || preg_grep('/^'.$basename.'$/', $names) === []) {
                        return $match[0];
                    }

                    return $match[1].implode(', ', preg_grep('/^'.$basename.'$/', $names, PREG_GREP_INVERT) ?: []).';';
                },
                $contents,
            );
        }

        $withoutImports = (string) preg_replace('/^use [^;]+;$/m', '', $updated);

        if (preg_match('/\b'.$basename.'\b/', $withoutImports) !== 1) {
            $updated = (string) preg_replace('/^use '.preg_quote(ltrim($trait, '\\'), '/').';\n/m', '', $updated);
        }

        return $updated;
    }

    protected function brand(Filesystem $files, string $panelId): void
    {
        $interactive = $this->input->isInteractive();
        $color = $this->option('brand-color') ?: ($interactive ? text('Primary brand colour', placeholder: '#2563eb', hint: 'Any CSS colour. Leave empty to keep the current one.', validate: fn (string $value): ?string => $value === '' || static::isColor($value) ? null : 'Use a CSS colour such as #2563eb or oklch(0.6 0.2 260).') : null);
        $logo = $this->option('logo') ?: ($interactive ? text('Brand logo', placeholder: 'images/logo.svg', hint: 'A path under public/ or a URL. Leave empty to skip.') : null);
        $font = $this->option('font') ?: ($interactive ? text('Font family', placeholder: 'Inter', hint: 'Leave empty to keep the default.') : null);

        if (! filled($color) && ! filled($logo) && ! filled($font)) {
            $this->record('Branding', 'skipped');

            return;
        }

        if (filled($color) && ! static::isColor((string) $color)) {
            $this->record('Branding', 'failed', "invalid colour {$color}");

            return;
        }

        $path = PanelProviderEditor::path($panelId);

        if (! $files->exists($path)) {
            $this->record('Branding', 'failed', Str::after($path, base_path().'/').' not found');

            return;
        }

        $original = $files->get($path);
        $contents = $original;

        if (filled($color)) {
            $contents = PanelProviderEditor::setColor($contents, 'primary', (string) $color) ?? $contents;
        }

        if (filled($logo)) {
            $logo = str_replace("'", '', (string) $logo);
            $isUrl = preg_match('#^(https?:)?//#', $logo) === 1;
            $value = $isUrl ? "'{$logo}'" : "asset('".ltrim(Str::after($logo, 'public/'), '/')."')";

            // Written anyway (the file may come later), but a missing file is a broken image.
            if (! $isUrl && ! $files->exists(public_path(ltrim(Str::after($logo, 'public/'), '/')))) {
                $this->nextSteps[] = 'Add the logo file public/'.ltrim(Str::after($logo, 'public/'), '/').': until it exists the panel shows a broken image';
            }

            $contents = PanelProviderEditor::setCall($contents, 'brandLogo', $value) ?? $contents;
        }

        if (filled($font)) {
            $contents = PanelProviderEditor::setCall($contents, 'font', "'".str_replace("'", '', (string) $font)."'") ?? $contents;
        }

        if ($contents === $original) {
            $this->record('Branding', 'already');

            return;
        }

        $files->put($path, $contents);
        $this->record('Branding', 'done', implode(', ', array_filter([filled($color) ? "colour {$color}" : null, filled($logo) ? 'logo' : null, filled($font) ? "font {$font}" : null])));
    }

    public static function isColor(string $value): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$|^(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\([^()\'";]+\)$/', trim($value)) === 1;
    }

    protected function demo(Filesystem $files): void
    {
        $seeder = database_path('seeders/AlfakitDemoSeeder.php');
        $model = ltrim((string) config('auth.providers.users.model', 'App\\Models\\User'), '\\');

        if (! $files->exists($seeder) || $this->option('force')) {
            $files->ensureDirectoryExists(dirname($seeder));
            $files->put($seeder, PanelProviderEditor::sortImports(str_replace(['{{ modelClass }}', '{{ model }}'], [$model, class_basename($model)], $files->get($this->stubPath('setup/demo-seeder')))));
        }

        if ($this->laravel->isProduction()) {
            $this->record('Demo data', 'skipped', 'not seeded in production');

            return;
        }

        if ($this->input->isInteractive() && ! confirm('Seed the demo data now (runs the migrations first)?', true)) {
            $this->record('Demo data', 'done', 'php artisan db:seed --class=AlfakitDemoSeeder');

            return;
        }

        $this->migrate(force: true);
        $result = AppEnvironment::artisan(['db:seed', '--class=Database\\Seeders\\AlfakitDemoSeeder', '--no-interaction']);
        $this->record('Demo data', $result->successful() ? 'done' : 'failed', 'see database/seeders/AlfakitDemoSeeder.php for the demo sign-in');
    }

    protected function migrate(bool $force = false): void
    {
        if (! $this->needsMigrations && ! $force) {
            return;
        }

        $this->needsMigrations = false;

        $run = $force || $this->option('migrate') || ($this->input->isInteractive() && confirm('Run the database migrations now?', true));

        if (! $run) {
            $this->nextSteps[] = 'Run the migrations: php artisan migrate';

            return;
        }

        $result = AppEnvironment::artisan(['migrate', '--no-interaction', ...($this->laravel->isProduction() ? ['--force'] : [])]);
        $this->record('Migrations', $result->successful() ? 'done' : 'failed', $result->successful() ? '' : 'php artisan migrate');
    }

    // ------------------------------------------------------------------ Summary

    protected function record(string $step, string $status, string $detail = ''): void
    {
        $this->summary = array_values(array_filter($this->summary, static fn (array $entry): bool => $entry['step'] !== $step));
        $this->summary[] = ['step' => $step, 'status' => $status, 'detail' => $detail];
    }

    protected function renderSummary(): void
    {
        $this->newLine();
        $this->components->info('Summary');

        foreach ($this->summary as $entry) {
            $status = match ($entry['status']) {
                'done' => '<fg=green>done</>',
                'already' => '<fg=gray>already set up</>',
                'failed' => '<fg=red>failed</>',
                default => '<fg=yellow>skipped</>',
            };

            $this->components->twoColumnDetail($entry['step'], $status.($entry['detail'] !== '' ? " <fg=gray>({$entry['detail']})</>" : ''));
        }

        // Standalone mode has no panel to open and no resources: its pages are the app's own.
        $panelSteps = $this->mode === 'standalone' ? [
            'Put a component on one of your pages: php artisan make:alfakit-page Orders --standalone',
        ] : [
            AppEnvironment::frontendIncludesAlfakit()
                ? 'Open /'.Str::slug((string) ($this->option('panel') ?: 'admin'))
                : 'Build the frontend (npm run build, or keep npm run dev running) and open /'.Str::slug((string) ($this->option('panel') ?: 'admin')),
            'Create a resource: php artisan make:alfakit-resource Post --generate',
        ];

        $steps = array_values(array_unique([
            ...$this->nextSteps,
            ...$panelSteps,
            'Run php artisan alfakit:setup again any time: finished steps are skipped',
        ]));

        $this->newLine();
        $this->components->info('Next steps');
        $this->components->bulletList($steps);
    }
}
