<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\SetupCommand;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;

/*
 * `alfakit:setup`: the wizard runs the other installers in a sandboxed app. Composer, npm
 * and artisan subprocesses are faked; prompts are answered by the test.
 */

/**
 * @param  array<int, string>  $packages  Composer packages the sandbox has "installed"
 */
function setupSandbox(object $test, array $packages = []): string
{
    $sandbox = sys_get_temp_dir().'/alfakit-setup-'.uniqid();
    $files = new Filesystem;

    foreach (['app/Models', 'app/Providers', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'resources/css', 'resources/js', 'routes', 'vendor/composer', 'public'] as $directory) {
        $files->ensureDirectoryExists("{$sandbox}/{$directory}");
    }

    file_put_contents("{$sandbox}/bootstrap/providers.php", "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    file_put_contents("{$sandbox}/.env", "APP_NAME=Demo\n");
    file_put_contents("{$sandbox}/package.json", json_encode(['dependencies' => ['@inertiajs/react' => '^2.0', 'react' => '^19.0']]));
    // An Inertia app: the adapter package and the root view (standalone installs need both).
    $files->ensureDirectoryExists("{$sandbox}/resources/views");
    file_put_contents("{$sandbox}/resources/views/app.blade.php", '@inertia');
    file_put_contents("{$sandbox}/vendor/composer/installed.json", json_encode(['packages' => array_map(static fn (string $name): array => ['name' => $name], ['inertiajs/inertia-laravel', ...$packages])]));
    file_put_contents("{$sandbox}/app/Models/User.php", <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Factories\HasFactory;
        use Illuminate\Foundation\Auth\User as Authenticatable;
        use Illuminate\Notifications\Notifiable;

        class User extends Authenticatable
        {
            /** @use HasFactory<\Database\Factories\UserFactory> */
            use HasFactory, Notifiable;

            protected $fillable = ['name', 'email', 'password'];
        }
        PHP);

    $test->originalBasePath = app()->basePath();
    app()->setBasePath($sandbox);
    app()->useConfigPath($sandbox.'/config');
    config([
        'alfakit.file_generation.path' => $sandbox.'/app/Alfakit',
        'alfakit.file_generation.namespace' => 'App\\Alfakit',
        'auth.providers.users.model' => 'App\\Models\\User',
    ]);

    Process::fake();

    return $sandbox;
}

afterEach(function (): void {
    if (isset($this->originalBasePath)) {
        $sandbox = app()->basePath();
        app()->setBasePath($this->originalBasePath);
        app()->useConfigPath($this->originalBasePath.'/config');
        (new Filesystem)->deleteDirectory($sandbox);
    }
});

it('runs every step from flags without interaction, then skips them on a second run', function (): void {
    $sandbox = setupSandbox($this, ['alfasix/alfakit-comments']);

    $this->artisan('alfakit:setup', [
        '--mode' => 'panel',
        '--adapter' => 'react',
        '--users' => true,
        '--plugins' => 'comments',
        '--brand-color' => '#ff6600',
        '--logo' => 'public/images/logo.svg',
        '--font' => 'Inter',
        '--demo' => true,
        '--migrate' => true,
        '--no-install' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Summary')
        ->expectsOutputToContain('Next steps')
        ->expectsOutputToContain('Add the logo file public/images/logo.svg')
        ->assertSuccessful();

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    expect($provider)
        ->toContain("'primary' => '#ff6600'")
        ->not->toContain("'primary' => '#2563eb'")
        ->toContain("->brandLogo(asset('images/logo.svg'))")
        ->toContain("->font('Inter')")
        ->toContain('CommentsPlugin::make(),')
        ->toContain('use AlfaSix\\AlfakitComments\\CommentsPlugin;')
        ->toContain('EnsureAccountIsActive::class')
        ->and(shell_exec('php -l '.escapeshellarg(PanelProviderEditor::path('admin'))))->toContain('No syntax errors')
        ->and(file_exists("{$sandbox}/app/Alfakit/Admin/Resources/UserResource.php"))->toBeTrue()
        ->and(file_get_contents("{$sandbox}/database/seeders/AlfakitDemoSeeder.php"))->toContain('use App\\Models\\User;')->toContain('class AlfakitDemoSeeder extends Seeder')
        // The panel's database notifications need Laravel's notifications table.
        ->and(glob("{$sandbox}/database/migrations/*_create_notifications_table.php"))->toHaveCount(1);

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'vendor:publish', '--tag=alfakit-comments-migrations']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1, 2) === ['artisan', 'migrate']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && in_array('--class=Database\\Seeders\\AlfakitDemoSeeder', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && in_array($process->command[0], ['composer', 'npm'], true));

    // Second run: nothing is duplicated.
    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--users' => true, '--plugins' => 'comments', '--brand-color' => '#ff6600', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('already set up')
        // The users resource exists: not regenerated, no migrations to run.
        ->doesntExpectOutputToContain('Run the migrations')
        ->assertSuccessful();

    $again = file_get_contents(PanelProviderEditor::path('admin'));

    expect(substr_count($again, 'CommentsPlugin::make()'))->toBe(1)
        ->and(glob("{$sandbox}/database/migrations/*_create_notifications_table.php"))->toHaveCount(1)
        ->and(substr_count($again, 'EnsureAccountIsActive::class'))->toBe(1)
        ->and(substr_count($again, '->colors('))->toBe(1);
});

it('keeps the installed panel mode when re-run on a starter kit without --mode', function (): void {
    $sandbox = setupSandbox($this);
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/Fixtures/StarterKits/react/resources', $sandbox.'/resources');
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:setup', ['--no-install' => true, '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())
        ->toContain('Install (panel)')
        ->not->toContain('Install (starter-kit)');
});

it('installs roles through Shield: composer, the plugin, its installer and HasRoles on the user model', function (): void {
    $sandbox = setupSandbox($this);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--roles' => true, '--users' => true, '--no-interaction' => true])
        ->expectsOutputToContain('already set up')
        // The wizard added the trait, so the next steps don't ask for it.
        ->doesntExpectOutputToContain('Add Spatie\\Permission\\Traits\\HasRoles to your User model')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'alfasix/alfakit-shield', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'alfakit-shield:install', '--panel=admin']);

    $model = file_get_contents("{$sandbox}/app/Models/User.php");

    expect($model)->toContain("    use HasFactory, Notifiable, HasRoles;\n")
        ->toContain('use Spatie\\Permission\\Traits\\HasRoles;')
        ->and(file_get_contents(PanelProviderEditor::path('admin')))->toContain('ShieldPlugin::make(),')
        ->and(file_get_contents("{$sandbox}/app/Alfakit/Admin/Resources/UserResource.php"))->toContain("Select::make('roles')")
        ->and(shell_exec('php -l '.escapeshellarg("{$sandbox}/app/Models/User.php")))->toContain('No syntax errors');
});

it('prints the composer command instead of installing plugins with --no-install', function (): void {
    setupSandbox($this);

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--adapter' => 'react', '--plugins' => 'activity-log', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer require alfasix/alfakit-activity-log')
        ->assertSuccessful();

    Process::assertNothingRan();

    expect(file_get_contents(PanelProviderEditor::path('admin')))->not->toContain('ActivityLogPlugin');
});

it('walks through the questions interactively', function (): void {
    $sandbox = setupSandbox($this);

    $this->artisan('alfakit:setup', ['--adapter' => 'react', '--no-install' => true])
        ->expectsChoice('How do you want to use AlfaKit?', 'panel', [
            'panel' => 'Admin panel (its own login, layout and navigation)',
            'starter-kit' => 'Inside my Laravel starter kit (its auth, layout and sidebar)',
            'standalone' => 'Components in my own Inertia pages (no panel)',
        ])
        ->expectsConfirmation('Add roles and permissions (Shield plugin)?', 'no')
        ->expectsConfirmation('Generate a users resource (invitations, impersonation, suspend/ban, sessions)?', 'no')
        ->expectsConfirmation('Set up AI providers (laravel/ai API keys)?', 'no')
        ->expectsConfirmation('Set up realtime notifications (Laravel Reverb)?', 'no')
        ->expectsConfirmation('Add first-party plugins now?', 'no')
        ->expectsQuestion('Primary brand colour', 'oklch(0.62 0.19 41)')
        ->expectsQuestion('Brand logo', '')
        ->expectsQuestion('Font family', '')
        ->expectsConfirmation('Add demo data (a seeder with an admin and sample users)?', 'no')
        // The install added the notifications table migration.
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsConfirmation('Create an admin user to sign in with?', 'no')
        ->expectsOutputToContain('php artisan make:alfakit-user')
        ->assertSuccessful();

    expect(file_get_contents(PanelProviderEditor::path('admin')))->toContain("'primary' => 'oklch(0.62 0.19 41)'")
        ->and(file_exists("{$sandbox}/app/Alfakit/Admin/Resources/UserResource.php"))->toBeFalse();
});

it('installs into a starter kit with alfakit:install --starter-kit', function (): void {
    setupSandbox($this);

    // No starter kit files: alfakit:install --starter-kit refuses, and the summary says so.
    $this->artisan('alfakit:setup', ['--mode' => 'starter-kit', '--adapter' => 'react', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('No Laravel starter kit found')
        ->expectsOutputToContain('failed')
        ->assertFailed();
});

it('rejects an unknown mode', function (): void {
    setupSandbox($this);

    $this->artisan('alfakit:setup', ['--mode' => 'spaceship', '--no-interaction' => true])->assertFailed();
});

it('adds a trait after the model\'s trait statements', function (): void {
    $model = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Notifications\\Notifiable;\n\nclass User\n{\n    use Notifiable;\n\n    protected \$fillable = [];\n}\n";

    expect(SetupCommand::addTrait($model, 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toBe("<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Notifications\\Notifiable;\nuse Spatie\\Permission\\Traits\\HasRoles;\n\nclass User\n{\n    use Notifiable, HasRoles;\n\n    protected \$fillable = [];\n}\n");

    expect(SetupCommand::addTrait("<?php\n\nnamespace App;\n\nclass User\n{\n    protected \$fillable = [];\n}\n", 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toContain("{\n    use HasRoles;\n\n    protected \$fillable = [];");

    // The starter kits' User: a docblock above a comma-separated trait statement.
    $starterKit = "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n    /** @use HasFactory<UserFactory> */\n    use HasFactory, Notifiable;\n\n    /**\n     * Casts.\n     */\n    protected function casts(): array\n    {\n        return [];\n    }\n}\n";

    expect(SetupCommand::addTrait($starterKit, 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toContain("    /** @use HasFactory<UserFactory> */\n    use HasFactory, Notifiable, HasRoles;\n\n    /**\n     * Casts.");
});

it('recognises CSS colours', function (string $value, bool $valid): void {
    expect(SetupCommand::isColor($value))->toBe($valid);
})->with([
    ['#2563eb', true],
    ['#fff', true],
    ['oklch(0.6 0.2 260)', true],
    ['rgb(10, 20, 30)', true],
    ['blue', false],
    ["#fff'); evil(", false],
]);

it('edits panel providers: imports stay sorted, calls are added once', function (): void {
    $provider = "<?php\n\nnamespace App\\Providers\\Alfakit;\n\nuse AlfaSix\\Alfakit\\Panel;\nuse AlfaSix\\Alfakit\\PanelProvider;\n\nclass AdminPanelProvider extends PanelProvider\n{\n    public function panel(Panel \$panel): Panel\n    {\n        return \$panel\n            ->id('admin')\n            ->path('admin');\n    }\n}\n";

    $updated = PanelProviderEditor::addPlugin($provider, 'AlfaSix\\AlfakitComments\\CommentsPlugin');
    $updated = PanelProviderEditor::addPlugin((string) $updated, 'AlfaSix\\AlfakitComments\\CommentsPlugin');
    $updated = PanelProviderEditor::setColor((string) $updated, 'primary', '#123456');
    $updated = PanelProviderEditor::setColor((string) $updated, 'primary', '#654321');
    $updated = PanelProviderEditor::setCall((string) $updated, 'font', "'Inter'");
    $updated = PanelProviderEditor::setCall((string) $updated, 'font', "'Montserrat'");

    expect($updated)->toContain("use AlfaSix\\Alfakit\\Panel;\nuse AlfaSix\\Alfakit\\PanelProvider;\nuse AlfaSix\\AlfakitComments\\CommentsPlugin;")
        ->toContain("            ->id('admin')\n            ->font('Montserrat')\n            ->colors([\n                'primary' => '#654321',\n            ])\n            ->path('admin')\n            ->plugins([\n                CommentsPlugin::make(),\n            ]);")
        ->and(substr_count((string) $updated, 'CommentsPlugin::make()'))->toBe(1);
});

it('hands AI providers to alfakit:ai-setup', function (): void {
    $sandbox = setupSandbox($this);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();
    putenv('MISTRAL_API_KEY=ms-setup-key');

    try {
        $this->artisan('alfakit:setup', ['--mode' => 'panel', '--ai' => 'mistral', '--no-install' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain('ms-setup-key')
            ->assertSuccessful();
    } finally {
        putenv('MISTRAL_API_KEY');
    }

    expect(file_get_contents("{$sandbox}/.env"))->toContain('MISTRAL_API_KEY=ms-setup-key');
});

it('installs a bundle of plugins with its defaults: composer, registration and migrations', function (): void {
    setupSandbox($this);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--bundle' => 'ops', '--migrate' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Bundle: Ops and safety')
        ->assertSuccessful();

    foreach (['ops', 'backup', 'health', 'webhooks'] as $slug) {
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', "alfasix/alfakit-{$slug}", '--no-interaction']);
    }

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'vendor:publish', '--tag=alfakit-webhooks-migrations']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1, 2) === ['artisan', 'migrate']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'alfasix/alfakit-shield', '--no-interaction']);

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    expect($provider)
        ->toContain("OpsPlugin::make()->navigationGroup('System'),")
        ->toContain("WebhooksPlugin::make()->navigationGroup('System'),")
        ->toContain('use AlfaSix\\AlfakitHealth\\HealthPlugin;')
        ->and(shell_exec('php -l '.escapeshellarg(PanelProviderEditor::path('admin'))))->toContain('No syntax errors');

    // Running it again adds nothing twice.
    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--bundle' => 'ops', '--no-interaction' => true])->assertSuccessful();

    expect(substr_count(file_get_contents(PanelProviderEditor::path('admin')), 'OpsPlugin::make()'))->toBe(1);
});

it('sets up Shield roles when a bundle includes it and merges bundles with --plugins', function (): void {
    $sandbox = setupSandbox($this);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--bundle' => 'saas,support', '--plugins' => 'comments,tags', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer require alfasix/alfakit-teams')
        ->expectsOutputToContain('composer require alfasix/alfakit-helpdesk')
        ->expectsOutputToContain('composer require alfasix/alfakit-tags')
        ->assertSuccessful();

    Process::assertNothingRan();
    expect(file_get_contents("{$sandbox}/app/Models/User.php"))->toContain('Notifiable, HasRoles;');
});

it('installs every bundle and every plugin with all', function (): void {
    setupSandbox($this);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--bundle' => 'all', '--plugins' => 'all', '--no-interaction' => true])->assertSuccessful();

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    foreach (PluginCatalog::all() as $plugin) {
        expect($provider)->toContain(class_basename($plugin['class']).'::make()');
    }

    expect($provider)->toContain("InvoicesPlugin::make()->navigationGroup('Finance'),")
        ->and(shell_exec('php -l '.escapeshellarg(PanelProviderEditor::path('admin'))))->toContain('No syntax errors');
});

it('rejects an unknown bundle before doing anything', function (): void {
    setupSandbox($this);

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--bundle' => 'everything-nice', '--no-interaction' => true])
        ->expectsOutputToContain('Unknown bundle(s): everything-nice')
        ->assertFailed();

    Process::assertNothingRan();
});

it('defines bundles of known plugins', function (): void {
    expect(AlfaSix\Alfakit\Commands\Support\PluginBundles::names())->toBe(['saas', 'content', 'backoffice', 'support', 'ops']);

    foreach (AlfaSix\Alfakit\Commands\Support\PluginBundles::names() as $bundle) {
        foreach (AlfaSix\Alfakit\Commands\Support\PluginBundles::plugins($bundle) as $slug) {
            expect(PluginCatalog::has($slug))->toBeTrue("{$bundle}: {$slug}");
        }
    }
});

it('seeds a verified demo admin, so the demo sign-in works with email verification on', function (): void {
    $source = str_replace(
        ['{{ modelClass }}', '{{ model }}', 'namespace Database\\Seeders;', 'class AlfakitDemoSeeder'],
        [User::class, 'User', 'namespace AlfakitDemoSeederTest;', 'class GeneratedDemoSeeder'],
        (string) file_get_contents(dirname(__DIR__, 2).'/stubs/setup/demo-seeder.stub'),
    );
    eval(substr($source, strlen('<?php')));

    (new AlfakitDemoSeederTest\GeneratedDemoSeeder)->run();
    (new AlfakitDemoSeederTest\GeneratedDemoSeeder)->run();

    $admin = User::query()->where('email', 'admin@example.com')->sole();

    expect($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and(User::query()->where('email', 'like', 'demo%')->count())->toBe(12);
});

it('creates the admin user to sign in with from the flags', function (): void {
    setupSandbox($this);
    config(['auth.providers.users.model' => User::class]);

    $this->artisan('alfakit:setup', [
        '--mode' => 'panel',
        '--adapter' => 'react',
        '--admin-email' => 'owner@example.com',
        '--admin-name' => 'Owner',
        '--admin-password' => 'secret-password',
        '--no-install' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'owner@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Owner')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();

    // Without a password the step fails instead of creating a user without one.
    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--adapter' => 'react', '--admin-email' => 'other@example.com', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('--admin-password is required')
        ->assertFailed();

    expect(User::query()->where('email', 'other@example.com')->exists())->toBeFalse();
});

it('creates the admin user in a fresh process when the panel was only just written', function (): void {
    setupSandbox($this);
    config(['auth.providers.users.model' => User::class]);
    Process::fake();

    // The `backoffice` panel's provider is written by this run, so this process never booted it.
    expect(alfakit()->hasPanel('backoffice'))->toBeFalse();

    $this->artisan('alfakit:setup', [
        '--mode' => 'panel',
        '--panel' => 'backoffice',
        '--adapter' => 'react',
        '--admin-email' => 'owner@example.com',
        '--admin-password' => 'secret-password',
        '--no-install' => true,
        '--no-interaction' => true,
    ])->doesntExpectOutputToContain('is not registered')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_slice($process->command, 1, 2) === ['artisan', 'make:alfakit-user']
        && in_array('--panel=backoffice', $process->command, true)
        && in_array('--email=owner@example.com', $process->command, true)
        && in_array('--password=secret-password', $process->command, true));
});

it('asks for the admin user interactively and creates it', function (): void {
    setupSandbox($this);
    config(['auth.providers.users.model' => User::class]);

    $this->artisan('alfakit:setup', ['--adapter' => 'react', '--no-install' => true])
        ->expectsChoice('How do you want to use AlfaKit?', 'panel', [
            'panel' => 'Admin panel (its own login, layout and navigation)',
            'starter-kit' => 'Inside my Laravel starter kit (its auth, layout and sidebar)',
            'standalone' => 'Components in my own Inertia pages (no panel)',
        ])
        ->expectsConfirmation('Add roles and permissions (Shield plugin)?', 'no')
        ->expectsConfirmation('Generate a users resource (invitations, impersonation, suspend/ban, sessions)?', 'no')
        ->expectsConfirmation('Set up AI providers (laravel/ai API keys)?', 'no')
        ->expectsConfirmation('Set up realtime notifications (Laravel Reverb)?', 'no')
        ->expectsConfirmation('Add first-party plugins now?', 'no')
        ->expectsQuestion('Primary brand colour', '#2563eb')
        ->expectsQuestion('Brand logo', '')
        ->expectsQuestion('Font family', '')
        ->expectsConfirmation('Add demo data (a seeder with an admin and sample users)?', 'no')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsConfirmation('Create an admin user to sign in with?', 'yes')
        ->expectsQuestion('Name', 'Owner')
        ->expectsQuestion('Email address', 'owner-interactive@example.com')
        ->expectsQuestion('Password', 'secret-password')
        ->assertSuccessful();

    $user = User::query()->where('email', 'owner-interactive@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Owner')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('points standalone installs at their own pages, not at a panel', function (): void {
    setupSandbox($this);

    $this->artisan('alfakit:setup', ['--mode' => 'standalone', '--adapter' => 'react', '--users' => true, '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('no panel in standalone mode')
        ->expectsOutputToContain('make:alfakit-page Orders --standalone')
        ->doesntExpectOutputToContain('make:alfakit-resource Post --generate')
        ->doesntExpectOutputToContain('and open /admin')
        ->assertSuccessful();
});

it('skips the admin user when the wizard runs again with the same email', function (): void {
    setupSandbox($this);
    config(['auth.providers.users.model' => User::class]);
    $flags = ['--mode' => 'panel', '--adapter' => 'react', '--admin-email' => 'again@example.com', '--admin-password' => 'secret-password', '--no-install' => true, '--no-interaction' => true];

    $this->artisan('alfakit:setup', $flags)->assertSuccessful();
    $this->artisan('alfakit:setup', $flags)->expectsOutputToContain('already set up')->assertSuccessful();

    expect(User::query()->where('email', 'again@example.com')->count())->toBe(1);
});

it('neither asks for nor suggests an admin user on a re-run when the app has users', function (): void {
    setupSandbox($this);
    config(['auth.providers.users.model' => User::class]);
    $flags = ['--mode' => 'panel', '--adapter' => 'react', '--no-install' => true, '--no-interaction' => true];

    $this->artisan('alfakit:setup', $flags)->expectsOutputToContain('Create a user to sign in with')->assertSuccessful();

    User::query()->create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);

    $this->artisan('alfakit:setup', $flags)->doesntExpectOutputToContain('Create a user to sign in with')->assertSuccessful();
});

it('shows the plugin list only after opting in, and installs what is ticked', function (): void {
    setupSandbox($this);

    $this->artisan('alfakit:setup', ['--adapter' => 'react', '--no-install' => true])
        ->expectsChoice('How do you want to use AlfaKit?', 'panel', [
            'panel' => 'Admin panel (its own login, layout and navigation)',
            'starter-kit' => 'Inside my Laravel starter kit (its auth, layout and sidebar)',
            'standalone' => 'Components in my own Inertia pages (no panel)',
        ])
        ->expectsConfirmation('Add roles and permissions (Shield plugin)?', 'no')
        ->expectsConfirmation('Generate a users resource (invitations, impersonation, suspend/ban, sessions)?', 'no')
        ->expectsConfirmation('Set up AI providers (laravel/ai API keys)?', 'no')
        ->expectsConfirmation('Set up realtime notifications (Laravel Reverb)?', 'no')
        ->expectsConfirmation('Add first-party plugins now?', 'yes')
        ->expectsChoice('Which first-party plugins do you want?', ['comments'], PluginCatalog::options())
        ->expectsQuestion('Primary brand colour', '#2563eb')
        ->expectsQuestion('Brand logo', '')
        ->expectsQuestion('Font family', '')
        ->expectsConfirmation('Add demo data (a seeder with an admin and sample users)?', 'no')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsConfirmation('Create an admin user to sign in with?', 'no')
        ->expectsOutputToContain('composer require alfasix/alfakit-comments')
        ->assertSuccessful();
});
