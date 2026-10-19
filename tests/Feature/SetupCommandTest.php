<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\SetupCommand;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
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
        ->assertSuccessful();

    $again = file_get_contents(PanelProviderEditor::path('admin'));

    expect(substr_count($again, 'CommentsPlugin::make()'))->toBe(1)
        ->and(glob("{$sandbox}/database/migrations/*_create_notifications_table.php"))->toHaveCount(1)
        ->and(substr_count($again, 'EnsureAccountIsActive::class'))->toBe(1)
        ->and(substr_count($again, '->colors('))->toBe(1);
});

it('installs roles through Shield: composer, the plugin, its installer and HasRoles on the user model', function (): void {
    $sandbox = setupSandbox($this);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    $this->artisan('alfakit:setup', ['--mode' => 'panel', '--roles' => true, '--users' => true, '--no-interaction' => true])
        ->expectsOutputToContain('already set up')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'alfasix/alfakit-shield', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'alfakit-shield:install', '--panel=admin']);

    $model = file_get_contents("{$sandbox}/app/Models/User.php");

    expect($model)->toContain("    use HasFactory, Notifiable;\n    use HasRoles;\n")
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
        ->expectsChoice('Which first-party plugins do you want?', [], PluginCatalog::options())
        ->expectsQuestion('Primary brand colour', 'oklch(0.62 0.19 41)')
        ->expectsQuestion('Brand logo', '')
        ->expectsQuestion('Font family', '')
        ->expectsConfirmation('Add demo data (a seeder with an admin and sample users)?', 'no')
        // The install added the notifications table migration.
        ->expectsConfirmation('Run the database migrations now?', 'no')
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
        ->toBe("<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Notifications\\Notifiable;\nuse Spatie\\Permission\\Traits\\HasRoles;\n\nclass User\n{\n    use Notifiable;\n    use HasRoles;\n\n    protected \$fillable = [];\n}\n");

    expect(SetupCommand::addTrait("<?php\n\nnamespace App;\n\nclass User\n{\n    protected \$fillable = [];\n}\n", 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toContain("{\n    use HasRoles;\n\n    protected \$fillable = [];");
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
    expect(file_get_contents("{$sandbox}/app/Models/User.php"))->toContain('use HasRoles;');
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
