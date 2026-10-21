<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Generators\ClassGenerator;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Generate a complete users resource in the application: form, table and infolist, with
 * invitations, impersonation, suspend/ban, forced password changes, two-factor resets,
 * sign-in tracking and sessions. Running it again only writes what is missing.
 */
#[AsCommand(name: 'alfakit:users', description: 'Generate a complete users resource (invitations, impersonation, suspend/ban, 2FA reset, sessions)')]
class UsersCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'alfakit:users
        {--panel= : The panel to add the resource to (default: the default panel, or admin)}
        {--model= : The user model (default: the auth provider\'s model)}
        {--roles : Add a roles field and column (spatie/laravel-permission, e.g. via the Shield plugin)}
        {--no-roles : Never add roles, even when spatie/laravel-permission is installed}
        {--no-migrations : Do not write the account columns migration}
        {--no-provider : Do not edit the panel provider}
        {--force : Overwrite existing files}';

    /** @var array<int, string> */
    protected array $written = [];

    /** Whether the account columns migration still has to run. */
    protected bool $migrationPending = true;

    public function handle(Filesystem $files): int
    {
        $this->written = [];
        $this->migrationPending = true;
        $panelId = $this->resolvePanelId();
        $modelClass = ltrim((string) ($this->option('model') ?: $this->defaultModel($panelId)), '\\');
        $model = class_basename($modelClass);
        $force = (bool) $this->option('force');
        $roles = $this->shouldAddRoles($modelClass);

        $suffix = $this->panelNamespaceSuffix($panelId);
        $namespace = $this->getGenerationNamespace().$suffix;
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix);

        $replacements = [
            'namespace' => $namespace,
            'modelClass' => $modelClass,
            'model' => $model,
            'appNamespace' => $this->appNamespace(),
            'table' => $this->tableFor($modelClass),
            ...$this->rolesReplacements($roles),
        ];

        $targets = [
            'users/resource' => "{$path}/Resources/UserResource.php",
            'users/pages/list' => "{$path}/Resources/UserResource/Pages/ListUsers.php",
            'users/pages/create' => "{$path}/Resources/UserResource/Pages/CreateUser.php",
            'users/pages/edit' => "{$path}/Resources/UserResource/Pages/EditUser.php",
            'users/pages/view' => "{$path}/Resources/UserResource/Pages/ViewUser.php",
            'users/user-accounts' => "{$path}/Users/UserAccounts.php",
            'users/user-invitation' => "{$path}/Users/UserInvitation.php",
            'users/accept-invitation' => "{$path}/Users/Pages/AcceptInvitation.php",
            'users/change-password' => "{$path}/Users/Pages/ChangePassword.php",
            'users/ensure-account-is-active' => "{$path}/Users/Middleware/EnsureAccountIsActive.php",
            'users/require-password-change' => "{$path}/Users/Middleware/RequirePasswordChange.php",
            'users/record-sign-in' => app_path('Listeners/RecordUserSignIn.php'),
        ];

        foreach ($targets as $stub => $target) {
            $this->generate($files, $stub, $target, $replacements, $force);
        }

        if (! $this->option('no-migrations')) {
            $this->writeMigration($files, $replacements, $force);
        }

        if (! $this->option('no-provider')) {
            $this->updatePanelProvider($files, $panelId, $namespace);
            $this->guardStarterKitPages($files, $panelId, $namespace);
        }

        $this->newLine();
        $this->components->info($this->written === [] ? 'The users resource is already generated.' : 'The users resource is ready.');
        $this->components->bulletList(array_values(array_filter([
            $this->option('no-migrations') || ! $this->migrationPending ? null : 'Run the migration: php artisan migrate',
            "Let admins impersonate: implement AlfaSix\\Alfakit\\Contracts\\CanImpersonate on {$model} (canImpersonate(): bool)",
            'Invitations are e-mailed: configure MAIL_MAILER (and a queue worker if you queue notifications)',
            config('session.driver') === 'database' ? null : 'Sessions list and revoke need SESSION_DRIVER=database',
            $roles && ! $this->usesHasRoles($modelClass) ? "Roles need Spatie\\Permission\\Traits\\HasRoles on {$model}" : null,
            'Authorise who manages users with a UserPolicy (php artisan make:policy UserPolicy --model='.$model.')',
        ])));

        return self::SUCCESS;
    }

    /**
     * Whether the user model uses spatie/laravel-permission's `HasRoles`, read from its file:
     * `alfakit:setup` adds the trait in the same process, after the class was loaded.
     */
    protected function usesHasRoles(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            return false;
        }

        if (in_array('Spatie\\Permission\\Traits\\HasRoles', class_uses_recursive($modelClass), true)) {
            return true;
        }

        $file = (new ReflectionClass($modelClass))->getFileName();

        return is_string($file) && preg_match('/^[ \t]+use\s+[^;]*\bHasRoles\b/m', (string) @file_get_contents($file)) === 1;
    }

    protected function resolvePanelId(): string
    {
        $option = $this->option('panel');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        foreach (alfakit()->getPanels() as $panel) {
            if ($panel->isDefault()) {
                return $panel->getId();
            }
        }

        return 'admin';
    }

    protected function defaultModel(string $panelId): string
    {
        $guard = alfakit()->hasPanel($panelId) ? alfakit()->getPanel($panelId)->getAuthGuard() : (string) config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');
        $model = config("auth.providers.{$provider}.model");

        return is_string($model) && $model !== '' ? $model : $this->appNamespace().'Models\\User';
    }

    protected function appNamespace(): string
    {
        try {
            return $this->laravel->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }

    protected function shouldAddRoles(string $modelClass): bool
    {
        if ($this->option('no-roles')) {
            return false;
        }

        if ($this->option('roles')) {
            return true;
        }

        $hasPermissions = AppEnvironment::hasComposerPackage('spatie/laravel-permission') || class_exists('Spatie\\Permission\\Models\\Role');

        return $hasPermissions && class_exists($modelClass) && method_exists($modelClass, 'roles');
    }

    protected function tableFor(string $modelClass): string
    {
        if (class_exists($modelClass) && is_a($modelClass, Model::class, true)) {
            return (new $modelClass)->getTable();
        }

        return Str::snake(Str::pluralStudly(class_basename($modelClass)));
    }

    /**
     * @return array<string, string>
     */
    protected function rolesReplacements(bool $roles): array
    {
        if (! $roles) {
            return ['rolesFormField' => '', 'rolesEntry' => '', 'rolesColumn' => '', 'rolesFilter' => '', 'rolesInviteField' => ''];
        }

        $indent = str_repeat(' ', 24);

        return [
            'rolesFormField' => "{$indent}Select::make('roles')\n{$indent}    ->label(__('alfakit::users.fields.roles'))\n{$indent}    ->relationship('roles', 'name')\n{$indent}    ->multiple()\n{$indent}    ->preload()\n{$indent}    ->searchable(),\n",
            'rolesEntry' => "{$indent}TextEntry::make('roles.name')->label(__('alfakit::users.fields.roles'))->badge()->placeholder('—'),\n",
            'rolesColumn' => "                TextColumn::make('roles.name')\n                    ->label(__('alfakit::users.fields.roles'))\n                    ->badge(),\n",
            'rolesFilter' => "                SelectFilter::make('roles')\n                    ->label(__('alfakit::users.fields.roles'))\n                    ->relationship('roles', 'name')\n                    ->multiple()\n                    ->preload(),\n",
            'rolesInviteField' => "                Select::make('roles')\n                    ->label(__('alfakit::users.fields.roles'))\n                    ->options(fn (): array => UserAccounts::roleOptions())\n                    ->multiple(),\n",
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function generate(Filesystem $files, string $stub, string $target, array $replacements, bool $force): void
    {
        $relative = Str::after($target, base_path().'/');

        if ($files->exists($target) && ! $force) {
            $this->components->twoColumnDetail($relative, '<fg=yellow>exists, skipped</>');

            return;
        }

        $contents = app(ClassGenerator::class)->generate($stub, $replacements);

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, PanelProviderEditor::sortImports($contents));
        $this->written[] = $relative;

        $this->components->twoColumnDetail($relative, '<fg=green>created</>');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function writeMigration(Filesystem $files, array $replacements, bool $force): void
    {
        $table = $replacements['table'];
        $name = "add_alfakit_account_columns_to_{$table}_table";
        $existing = $files->glob(database_path("migrations/*_{$name}.php")) ?: [];
        // A migration that already ran (`--force` rewrites it in place) has nothing to run.
        $this->migrationPending = $existing === [] || ! self::migrationRan($existing[0]);

        if ($existing !== [] && ! $force) {
            $this->components->twoColumnDetail('database/migrations/…_'.$name.'.php', '<fg=yellow>exists, skipped</>');

            return;
        }

        $this->generate($files, 'users/migration', $existing[0] ?? database_path('migrations/'.date('Y_m_d_His')."_{$name}.php"), $replacements, true);
    }

    /**
     * Whether the migration file is recorded in the migrations table.
     */
    protected static function migrationRan(string $path): bool
    {
        try {
            $table = (string) config('database.migrations.table', 'migrations');

            return Schema::hasTable($table)
                && DB::table($table)->where('migration', pathinfo($path, PATHINFO_FILENAME))->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Register the middleware, the change-password page, the invitation route and (when
     * the panel doesn't discover resources) the resource on the panel provider.
     */
    protected function updatePanelProvider(Filesystem $files, string $panelId, string $namespace): void
    {
        $path = PanelProviderEditor::path($panelId);
        $relative = Str::after($path, base_path().'/');

        if (! $files->exists($path)) {
            $this->components->warn("{$relative} not found. Add to your panel: ->authMiddleware([EnsureAccountIsActive::class, RequirePasswordChange::class]), ->pages([ChangePassword::class]) and ->routes(fn (Router \$router, Panel \$panel) => AcceptInvitation::registerRoute(\$router, \$panel)).");

            return;
        }

        $original = $files->get($path);
        $contents = $original;
        $failed = false;

        $edits = [
            'EnsureAccountIsActive::class' => ["authMiddleware([\n    EnsureAccountIsActive::class,\n    RequirePasswordChange::class,\n])", ["{$namespace}\\Users\\Middleware\\EnsureAccountIsActive", "{$namespace}\\Users\\Middleware\\RequirePasswordChange"]],
            'ChangePassword::class' => ['pages([ChangePassword::class])', ["{$namespace}\\Users\\Pages\\ChangePassword"]],
            'AcceptInvitation::registerRoute' => ['routes(fn (Router $router, Panel $panel) => AcceptInvitation::registerRoute($router, $panel))', ["{$namespace}\\Users\\Pages\\AcceptInvitation", 'Illuminate\\Routing\\Router', 'AlfaSix\\Alfakit\\Panel']],
        ];

        if (! str_contains($contents, 'discoverResources(')) {
            $edits['UserResource::class'] = ['resources([UserResource::class])', ["{$namespace}\\Resources\\UserResource"]];
        }

        foreach ($edits as $marker => [$call, $imports]) {
            if (str_contains($contents, $marker)) {
                continue;
            }

            $updated = PanelProviderEditor::addCall($contents, $call);

            if ($updated === null) {
                $failed = true;

                continue;
            }

            foreach ($imports as $import) {
                $updated = PanelProviderEditor::addImport($updated, $import);
            }

            $contents = $updated;
        }

        if ($contents !== $original) {
            $files->put($path, $contents);
            $this->components->twoColumnDetail($relative, '<fg=green>updated</>');
        }

        if ($failed) {
            $this->components->warn("Could not edit {$relative} automatically. Add ->authMiddleware([EnsureAccountIsActive::class, RequirePasswordChange::class]), ->pages([ChangePassword::class]) and ->routes(fn (Router \$router, Panel \$panel) => AcceptInvitation::registerRoute(\$router, \$panel)) to the panel.");
        }
    }

    /**
     * Starter kit panels: users sign in on the host's own login page and use its own pages,
     * which the panel's `authMiddleware()` never sees. Append `EnsureAccountIsActive` to the
     * host's `web` middleware group, so suspended and banned users are signed out there too.
     * Read from the provider file: during `alfakit:setup` the panel it just wrote is not booted.
     */
    protected function guardStarterKitPages(Filesystem $files, string $panelId, string $namespace): void
    {
        $provider = PanelProviderEditor::path($panelId);

        if (! $files->exists($provider) || preg_match('/->(?:starterKit|embedded)\(/', $files->get($provider)) !== 1) {
            return;
        }

        $path = base_path('bootstrap/app.php');
        $class = "{$namespace}\\Users\\Middleware\\EnsureAccountIsActive";
        $snippet = '$middleware->web(append: [EnsureAccountIsActive::class]) in bootstrap/app.php (use '.$class.')';
        $contents = $files->exists($path) ? $files->get($path) : null;

        if ($contents !== null && str_contains($contents, 'EnsureAccountIsActive::class')) {
            return;
        }

        $updated = $contents === null ? null : preg_replace_callback(
            '/(\$middleware->web\(append:\s*\[)(.*?)(\n?)([ \t]*)\]\)/s',
            static function (array $match): string {
                $items = rtrim($match[2]);
                $indent = preg_match('/\n([ \t]+)\S/', $match[2], $line) === 1 ? $line[1] : $match[4].'    ';
                $separator = $items === '' || str_ends_with($items, ',') ? '' : ',';

                return $match[1].$items.$separator."\n{$indent}EnsureAccountIsActive::class,\n".$match[4].'])';
            },
            $contents,
            1,
            $count,
        );

        if ($updated === null || $count !== 1) {
            $this->components->warn("Starter kit: add {$snippet}, so suspended and banned users are signed out of the app's own pages too.");

            return;
        }

        $files->put($path, PanelProviderEditor::addImport($updated, $class));
        $this->components->twoColumnDetail('bootstrap/app.php', '<fg=green>EnsureAccountIsActive on the web group</>');
    }
}
