<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\SetupCommand;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;

/*
 * `alfakit:add` / `alfakit:remove` / `alfakit:add --list`: first-party plugins on demand.
 * Composer and artisan subprocesses are faked.
 */

/**
 * @param  array<int, string>  $packages  Composer packages the sandbox has "installed"
 */
function pluginSandbox(object $test, array $packages = []): string
{
    $sandbox = sys_get_temp_dir().'/alfakit-plugins-'.uniqid();
    $files = new Filesystem;

    foreach (['app/Providers/Alfakit', 'bootstrap', 'config', 'vendor/composer', 'app/Alfakit'] as $directory) {
        $files->ensureDirectoryExists("{$sandbox}/{$directory}");
    }

    file_put_contents("{$sandbox}/bootstrap/providers.php", "<?php\n\nreturn [\n];\n");
    file_put_contents("{$sandbox}/vendor/composer/installed.json", json_encode(['packages' => array_map(static fn (string $name): array => ['name' => $name], $packages)]));

    $test->originalBasePath = app()->basePath();
    app()->setBasePath($sandbox);
    config(['alfakit.file_generation.path' => $sandbox.'/app/Alfakit']);

    $test->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();
    Process::fake();

    return $sandbox;
}

afterEach(function (): void {
    PluginCatalog::$pluginsDirectory = null;

    if (isset($this->originalBasePath)) {
        $sandbox = app()->basePath();
        app()->setBasePath($this->originalBasePath);
        (new Filesystem)->deleteDirectory($sandbox);
    }
});

it('reads the catalog from the plugins\' composer.json files', function (): void {
    $plugins = PluginCatalog::all();

    expect($plugins)->toHaveKeys(['shield', 'comments', 'ops', 'kanban', 'webhooks', 'feature-flags', 'translation-manager'])
        ->not->toHaveKey('translations')
        ->and($plugins['ops']['class'])->toBe('AlfaSix\\AlfakitOps\\OpsPlugin')
        ->and($plugins['ops']['description'])->toBe('An operations toolkit for AlfaKit panels')
        ->and($plugins['comments']['commands'])->toBe([['vendor:publish', '--tag=alfakit-comments-migrations']])
        ->and($plugins['comments']['migrate'])->toBeTrue()
        ->and($plugins['shield']['commands'])->toBe([['alfakit-shield:install', '--panel={panel}']])
        // A recipe's commands are kept next to the plugin's own migration tag.
        ->and($plugins['feature-flags']['commands'])->toBe([
            ['vendor:publish', '--tag=pennant-migrations'],
            ['vendor:publish', '--tag=alfakit-feature-flags-migrations'],
        ])
        // spatie/laravel-health's default (Eloquent) result store needs its table.
        ->and($plugins['health']['commands'])->toContain(['vendor:publish', '--tag=health-migrations'])
        ->and($plugins['health']['migrate'])->toBeTrue()
        // Plugins that export with AlfaKit's exporter publish its tables too.
        ->and($plugins['forms']['commands'])->toBe([
            ['vendor:publish', '--tag=alfakit-import-export-migrations'],
            ['vendor:publish', '--tag=alfakit-forms-migrations'],
        ])
        ->and($plugins['analytics']['commands'])->toContain(['vendor:publish', '--tag=alfakit-import-export-migrations'])
        // Plugins that deny access by default say how to allow it.
        ->and(implode(' ', $plugins['ops']['notes']))->toContain('authorize(')
        ->and(implode(' ', $plugins['translation-manager']['notes']))->toContain('manageTranslations')
        ->and(implode(' ', $plugins['mail-templates']['notes']))->toContain('manageAlfakitMailTemplates')
        // Teams turns the panel into a team workspace: users need the HasTeams trait.
        ->and(implode(' ', $plugins['teams']['notes']))->toContain('AlfaSix\\AlfakitTeams\\Concerns\\HasTeams')->toContain('insteadof HasRoles')
        // Its history needs the record versions table.
        ->and($plugins['mail-templates']['commands'])->toContain(['vendor:publish', '--tag=alfakit-record-versions-migrations']);

    foreach ($plugins as $plugin) {
        expect(mb_strlen($plugin['description']))->toBeLessThanOrEqual(93);
    }
});

it('lists plugins with a description and their status', function (): void {
    pluginSandbox($this, ['alfasix/alfakit-comments', 'alfasix/alfakit-tags']);
    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--no-interaction' => true])->assertSuccessful();

    $this->artisan('alfakit:add', ['--list' => true])
        ->expectsOutputToContain('An operations toolkit for AlfaKit panels')
        ->expectsOutputToContain('on admin')
        ->expectsOutputToContain('installed')
        ->assertSuccessful();
});

it('registers an installed plugin, publishes its migrations and is idempotent', function (): void {
    pluginSandbox($this, ['alfasix/alfakit-comments']);

    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--migrate' => true, '--no-interaction' => true])->assertSuccessful();

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    expect($provider)->toContain("->plugins([\n                CommentsPlugin::make(),\n            ]);")->toContain('use AlfaSix\\AlfakitComments\\CommentsPlugin;');
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'vendor:publish', '--tag=alfakit-comments-migrations']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1, 2) === ['artisan', 'migrate']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command[0] === 'composer');

    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--no-interaction' => true])->expectsOutputToContain('already set up')->assertSuccessful();

    expect(substr_count(file_get_contents(PanelProviderEditor::path('admin')), 'CommentsPlugin::make()'))->toBe(1);
});

it('explains instead of publishing Spatie\'s tags migration when the app has its own tags table', function (): void {
    pluginSandbox($this, ['alfasix/alfakit-tags']);
    $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
    $created = ! $schema->hasTable('tags');

    // The app's own `tags` table (the test fixtures have one), without Spatie's `taggables`.
    if ($created) {
        $schema->create('tags', fn (Illuminate\Database\Schema\Blueprint $table) => $table->id());
    }

    try {
        $this->artisan('alfakit:add', ['plugin' => ['tags'], '--migrate' => true, '--no-interaction' => true])
            ->expectsOutputToContain('already has a `tags` table')
            ->assertSuccessful();

        Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && in_array('--tag=tags-migrations', $process->command, true));
        Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1, 2) === ['artisan', 'migrate']);
        expect(file_get_contents(PanelProviderEditor::path('admin')))->toContain('TagsPlugin::make()');

        // Spatie's own tables: no conflict.
        $schema->create('taggables', fn (Illuminate\Database\Schema\Blueprint $table) => $table->id());
        expect(PluginCatalog::tableConflict('tags'))->toBeNull();
    } finally {
        $schema->dropIfExists('taggables');

        if ($created) {
            $schema->drop('tags');
        }
    }
});

it('requires missing packages after confirmation, or prints the command with --no-install', function (): void {
    pluginSandbox($this);

    $this->artisan('alfakit:add', ['plugin' => ['activity-log'], '--no-install' => true])
        ->expectsOutputToContain('composer require alfasix/alfakit-activity-log')
        ->assertSuccessful();
    Process::assertNothingRan();

    $this->artisan('alfakit:add', ['plugin' => ['activity-log']])
        ->expectsConfirmation('Install alfasix/alfakit-activity-log with Composer?', 'yes')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsOutputToContain('LogsActivity')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'alfasix/alfakit-activity-log', '--no-interaction']);
    expect(file_get_contents(PanelProviderEditor::path('admin')))->toContain('ActivityLogPlugin::make(),');
});

it('rejects unknown plugins', function (): void {
    pluginSandbox($this);

    $this->artisan('alfakit:add', ['plugin' => ['nope'], '--no-interaction' => true])->expectsOutputToContain('alfakit:add --list')->assertFailed();
    $this->artisan('alfakit:remove', ['plugin' => ['nope'], '--no-interaction' => true])->assertFailed();
});

it('unregisters and removes a plugin, keeping its tables', function (): void {
    pluginSandbox($this, ['alfasix/alfakit-comments']);
    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--no-interaction' => true])->assertSuccessful();

    $this->artisan('alfakit:remove', ['plugin' => ['comments']])
        ->expectsConfirmation('Remove alfasix/alfakit-comments with Composer?', 'yes')
        ->expectsOutputToContain('tables were left in place')
        ->assertSuccessful();

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    expect($provider)->not->toContain('CommentsPlugin')
        ->and(shell_exec('php -l '.escapeshellarg(PanelProviderEditor::path('admin'))))->toContain('No syntax errors');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'remove', 'alfasix/alfakit-comments', '--no-interaction']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && in_array('migrate:rollback', $process->command, true));
});

it('takes HasRoles off the user model when removing Shield takes spatie/laravel-permission away', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-shield']);
    $this->artisan('alfakit:add', ['plugin' => ['shield'], '--no-interaction' => true])->assertSuccessful();
    config(['auth.providers.users.model' => 'App\\Models\\User']);
    (new Filesystem)->ensureDirectoryExists("{$sandbox}/app/Models");
    file_put_contents("{$sandbox}/app/Models/User.php", "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Notifications\\Notifiable;\nuse Spatie\\Permission\\Traits\\HasRoles;\n\nclass User\n{\n    use Notifiable, HasRoles;\n}\n");

    $this->artisan('alfakit:remove', ['plugin' => ['shield'], '--no-interaction' => true])
        ->expectsOutputToContain('Removed Spatie\\Permission\\Traits\\HasRoles from App\\Models\\User')
        ->assertSuccessful();

    $model = (string) file_get_contents("{$sandbox}/app/Models/User.php");

    expect($model)->not->toContain('HasRoles')
        ->toContain("    use Notifiable;\n")
        ->and(shell_exec('php -l '.escapeshellarg("{$sandbox}/app/Models/User.php")))->toContain('No syntax errors');
});

it('removes a trait from its own statement or a list, keeping other uses', function (): void {
    $own = "<?php\n\nuse Spatie\\Permission\\Traits\\HasRoles;\n\nclass User\n{\n    use Notifiable;\n    use HasRoles;\n}\n";

    expect(SetupCommand::removeTrait($own, 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toBe("<?php\n\n\nclass User\n{\n    use Notifiable;\n}\n")
        ->and(SetupCommand::removeTrait("<?php\nclass User\n{\n    use A, HasRoles, B;\n}\n", 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toBe("<?php\nclass User\n{\n    use A, B;\n}\n")
        ->and(SetupCommand::removeTrait("<?php\nclass User\n{\n    use A;\n}\n", 'Spatie\\Permission\\Traits\\HasRoles'))
        ->toBe("<?php\nclass User\n{\n    use A;\n}\n");
});

it('keeps the package while another panel still uses the plugin', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-comments']);
    $this->artisan('make:alfakit-panel', ['id' => 'app'])->assertSuccessful();
    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--panel' => 'admin', '--no-interaction' => true])->assertSuccessful();
    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--panel' => 'app', '--no-interaction' => true])->assertSuccessful();

    $this->artisan('alfakit:remove', ['plugin' => ['comments'], '--panel' => 'admin', '--no-interaction' => true])
        ->expectsOutputToContain('still used by app')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command[0] === 'composer');
    expect(file_get_contents(PanelProviderEditor::path('app')))->toContain('CommentsPlugin::make()')
        ->and(file_get_contents(PanelProviderEditor::path('admin')))->not->toContain('CommentsPlugin');
});

it('removes a plugin call with chained configuration or an entry of plugins([...])', function (): void {
    $provider = <<<'PHP'
        <?php

        namespace App\Providers\Alfakit;

        use AlfaSix\Alfakit\Panel;
        use AlfaSix\AlfakitComments\CommentsPlugin;
        use AlfaSix\AlfakitTags\TagsPlugin;

        class AdminPanelProvider
        {
            public function panel(Panel $panel): Panel
            {
                return $panel
                    ->id('admin')
                    ->plugin(CommentsPlugin::make()->mentions(fn () => ['a' => ')'])
                        ->reactions())
                    ->plugins([
                        TagsPlugin::make(),
                        \AlfaSix\AlfakitKanban\KanbanPlugin::make(),
                    ])
                    ->path('admin');
            }
        }
        PHP;

    $withoutComments = PanelProviderEditor::removePlugin($provider, 'AlfaSix\\AlfakitComments\\CommentsPlugin');

    expect($withoutComments)->not->toContain('CommentsPlugin')
        ->toContain("            ->id('admin')\n            ->plugins([\n                TagsPlugin::make(),");

    $withoutTags = PanelProviderEditor::removePlugin($withoutComments, 'AlfaSix\\AlfakitTags\\TagsPlugin');

    expect($withoutTags)->toContain("->plugins([\n                \\AlfaSix\\AlfakitKanban\\KanbanPlugin::make(),\n            ])")
        ->not->toContain('use AlfaSix\\AlfakitTags\\TagsPlugin;');

    $none = PanelProviderEditor::removePlugin($withoutTags, 'AlfaSix\\AlfakitKanban\\KanbanPlugin');

    expect($none)->toContain("            ->id('admin')\n            ->path('admin');")
        ->and(PanelProviderEditor::removePlugin($none, 'AlfaSix\\AlfakitKanban\\KanbanPlugin'))->toBe($none);
});

it('adds plugins into one plugins([...]) array in the order they were added', function (): void {
    $provider = <<<'PHP'
        <?php

        namespace App\Providers\Alfakit;

        use AlfaSix\Alfakit\Panel;

        class AdminPanelProvider
        {
            public function panel(Panel $panel): Panel
            {
                return $panel
                    ->id('admin')
                    ->path('admin');
            }
        }
        PHP;

    $updated = PanelProviderEditor::addPlugin($provider, 'AlfaSix\\AlfakitTags\\TagsPlugin');
    $updated = PanelProviderEditor::addPlugin((string) $updated, 'AlfaSix\\AlfakitComments\\CommentsPlugin');
    $updated = PanelProviderEditor::addPlugin((string) $updated, 'AlfaSix\\AlfakitAuditLog\\AuditLogPlugin');
    $again = PanelProviderEditor::addPlugin((string) $updated, 'AlfaSix\\AlfakitComments\\CommentsPlugin');

    expect($updated)->toContain("            ->path('admin')\n            ->plugins([\n                TagsPlugin::make(),\n                CommentsPlugin::make(),\n                AuditLogPlugin::make(),\n            ]);")
        ->toContain("use AlfaSix\\Alfakit\\Panel;\nuse AlfaSix\\AlfakitAuditLog\\AuditLogPlugin;\nuse AlfaSix\\AlfakitComments\\CommentsPlugin;\nuse AlfaSix\\AlfakitTags\\TagsPlugin;\n")
        ->and(substr_count((string) $updated, '->plugins('))->toBe(1)
        ->and($again)->toBe($updated);

    // An existing array (without a trailing comma, or inline) is appended to.
    $existing = str_replace("->path('admin');", "->plugins([\n                \\AlfaSix\\AlfakitKanban\\KanbanPlugin::make()->boards()\n            ])\n            ->path('admin');", $provider);

    expect(PanelProviderEditor::addPlugin($existing, 'AlfaSix\\AlfakitTags\\TagsPlugin'))
        ->toContain("->plugins([\n                \\AlfaSix\\AlfakitKanban\\KanbanPlugin::make()->boards(),\n                TagsPlugin::make(),\n            ])\n            ->path('admin');")
        ->and(PanelProviderEditor::addPlugin(str_replace("->path('admin');", "->plugins([KanbanPlugin::make()])->path('admin');", $provider), 'AlfaSix\\AlfakitTags\\TagsPlugin'))
        ->toContain("->plugins([KanbanPlugin::make(), TagsPlugin::make()])->path('admin');")
        ->and(PanelProviderEditor::addPlugin(str_replace("->path('admin');", "->plugins([\n                //\n            ])\n            ->path('admin');", $provider), 'AlfaSix\\AlfakitTags\\TagsPlugin'))
        ->toContain("->plugins([\n                TagsPlugin::make(),\n            ])");

    // Removing them all again leaves no empty array behind.
    $removed = PanelProviderEditor::removePlugin((string) $updated, 'AlfaSix\\AlfakitTags\\TagsPlugin');
    $removed = PanelProviderEditor::removePlugin($removed, 'AlfaSix\\AlfakitComments\\CommentsPlugin');
    $removed = PanelProviderEditor::removePlugin($removed, 'AlfaSix\\AlfakitAuditLog\\AuditLogPlugin');

    expect($removed)->toContain("            ->id('admin')\n            ->path('admin');\n");
});

it('reads every plugin\'s showcase manifest from its composer.json', function (): void {
    foreach (PluginCatalog::all() as $slug => $plugin) {
        $showcase = PluginCatalog::showcase($slug);

        expect($showcase)->not->toBeNull("{$slug} has no extra.alfakit.showcase")
            ->and($showcase['demoPath'])->not->toBeEmpty()->not->toStartWith('/')
            ->and(count($showcase['tour']))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5)
            ->and(count($showcase['highlights']))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8)
            ->and($showcase['clips'])->not->toBeEmpty();

        foreach ($showcase['worksWith'] as $other) {
            expect(PluginCatalog::has($other))->toBeTrue("{$slug} works with unknown {$other}");
        }
    }

    config(['alfakit.demo_url' => 'https://demo.example.com/']);

    expect(PluginCatalog::demoUrl('kanban', 'vue'))->toBe('https://demo.example.com/vue/admin/manama/orders/board');
});

it('shows a plugin\'s highlights, demo tour and related plugins with --list <slug>', function (): void {
    pluginSandbox($this);
    config(['alfakit.demo_url' => 'https://demo.example.com']);

    $this->artisan('alfakit:add', ['plugin' => ['kanban'], '--list' => true])
        ->expectsOutputToContain('WIP limits and transitions')
        ->expectsOutputToContain('https://demo.example.com/react/admin/manama/orders/board')
        ->expectsOutputToContain('Works great with: workflows, comments, calendar')
        ->expectsOutputToContain('php artisan alfakit:add kanban')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('shows the showcase of a plugin that is not installed yet, as in an app installed from Packagist', function (): void {
    $sandbox = pluginSandbox($this);
    PluginCatalog::$pluginsDirectory = $sandbox.'/no-plugins';
    config(['alfakit.demo_url' => 'https://demo.example.com']);

    $this->artisan('alfakit:add', ['plugin' => ['kanban'], '--list' => true])
        ->expectsOutputToContain('WIP limits and transitions')
        ->expectsOutputToContain('https://demo.example.com/react/admin/manama/orders/board')
        ->expectsOutputToContain('Works great with: workflows, comments, calendar')
        ->assertSuccessful();
});

it('ships a copy of every plugin\'s showcase that matches its composer.json', function (): void {
    $shipped = PluginCatalog::shippedShowcases();
    $plugins = glob(dirname(__DIR__, 3).'/plugins/*/composer.json') ?: [];

    expect($plugins)->not->toBeEmpty();

    foreach ($plugins as $file) {
        $composer = json_decode((string) file_get_contents($file), true);
        $slug = str_replace('alfasix/alfakit-', '', (string) $composer['name']);

        expect($shipped[$slug] ?? null)->toBe($composer['extra']['alfakit']['showcase'], "resources/plugins/showcase.json is out of date for {$slug}: copy extra.alfakit.showcase of every packages/plugins/*/composer.json into it (keyed by slug, sorted), then run npx prettier --write on it");
    }

    expect(array_keys($shipped))->toHaveCount(count($plugins));
});

it('publishes the migrations of a plugin that was not on disk before composer require', function (): void {
    $sandbox = pluginSandbox($this);
    $source = dirname(__DIR__, 3).'/plugins/comments';
    // An app installed from Packagist: no packages/plugins next to AlfaKit.
    PluginCatalog::$pluginsDirectory = $sandbox.'/no-plugins';

    Process::fake(function (PendingProcess $process) use ($sandbox, $source) {
        if (($process->command[0] ?? null) === 'composer') {
            $target = $sandbox.'/vendor/alfasix/alfakit-comments';
            (new Filesystem)->ensureDirectoryExists($target.'/src');
            copy($source.'/composer.json', $target.'/composer.json');
            copy($source.'/src/AlfakitCommentsServiceProvider.php', $target.'/src/AlfakitCommentsServiceProvider.php');
            copy($source.'/src/CommentsPlugin.php', $target.'/src/CommentsPlugin.php');
        }

        return Process::result();
    });

    try {
        expect(PluginCatalog::get('comments')['commands'])->toBe([]);

        $this->artisan('alfakit:add', ['plugin' => ['comments'], '--no-interaction' => true])->assertSuccessful();
    } finally {
        PluginCatalog::$pluginsDirectory = null;
    }

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'vendor:publish', '--tag=alfakit-comments-migrations']);
});

it('adds plugins to a panel whose chain has comments with apostrophes (the starter-kit provider)', function (): void {
    $provider = <<<'PHP'
        <?php

        namespace App\Providers\Alfakit;

        use AlfaSix\Alfakit\Panel;

        class AdminPanelProvider
        {
            public function panel(Panel $panel): Panel
            {
                return $panel
                    ->id('admin')
                    ->starterKit()
                    /* the app's own layout */
                    // Like the starter kit's dashboard: signed in with a verified email address.
                    ->authMiddleware([
                        'verified', // the kit's "verified" middleware
                    ]);
            }
        }
        PHP;

    $updated = PanelProviderEditor::addPlugin($provider, 'AlfaSix\\AlfakitComments\\CommentsPlugin');

    expect($updated)->toContain("                'verified', // the kit's \"verified\" middleware\n            ])\n            ->plugins([\n                CommentsPlugin::make(),\n            ]);")
        ->and(PanelProviderEditor::removePlugin((string) $updated, 'AlfaSix\\AlfakitComments\\CommentsPlugin'))
        ->not->toContain('CommentsPlugin');
});

it('does not publish the migrations of an already installed plugin a second time', function (): void {
    $sandbox = pluginSandbox($this);
    $source = $sandbox.'/vendor/acme/migrations';
    (new Filesystem)->ensureDirectoryExists($source);
    (new Filesystem)->ensureDirectoryExists($sandbox.'/database/migrations');
    file_put_contents($source.'/2026_01_01_000000_create_acme_tables.php', '<?php');

    (new class(app()) extends ServiceProvider
    {
        public function publish(string $from): void
        {
            $this->publishesMigrations([$from => database_path('migrations')], 'acme-migrations');
        }
    })->publish($source);

    $command = ['vendor:publish', '--tag=acme-migrations'];

    expect(AppEnvironment::migrationsAlreadyPublished($command))->toBeFalse();

    file_put_contents($sandbox.'/database/migrations/2026_10_19_083011_create_acme_tables.php', '<?php');

    expect(AppEnvironment::migrationsAlreadyPublished($command))->toBeTrue()
        ->and(AppEnvironment::migrationsAlreadyPublished(['vendor:publish', '--tag=unknown-tag']))->toBeFalse()
        ->and(AppEnvironment::migrationsAlreadyPublished(['alfakit-shield:install']))->toBeFalse();
});

it('does not ask to run the migrations when registering an installed plugin on another panel published nothing', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-comments']);
    $this->artisan('make:alfakit-panel', ['id' => 'app'])->assertSuccessful();
    $source = $sandbox.'/vendor/comments-migrations';
    (new Filesystem)->ensureDirectoryExists($source);
    (new Filesystem)->ensureDirectoryExists($sandbox.'/database/migrations');
    file_put_contents($source.'/2026_01_01_000000_create_alfakit_comments_table.php', '<?php');

    (new class(app()) extends ServiceProvider
    {
        public function publish(string $from): void
        {
            $this->publishesMigrations([$from => database_path('migrations')], 'alfakit-comments-migrations');
        }
    })->publish($source);

    // Registered on admin earlier: its migration is in the app already.
    file_put_contents($sandbox.'/database/migrations/2026_10_19_083011_create_alfakit_comments_table.php', '<?php');

    $this->artisan('alfakit:add', ['plugin' => ['comments'], '--panel' => 'app', '--no-interaction' => true])
        ->expectsOutputToContain('registered on app')
        ->doesntExpectOutputToContain('Run the migrations')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && in_array('vendor:publish', $process->command, true));
});

/** Turn the sandbox's admin panel into a starter kit panel, as `alfakit:install --starter-kit` does. */
function useStarterKitPanel(): void
{
    $provider = PanelProviderEditor::path('admin');
    file_put_contents($provider, str_replace("->id('admin')", "->id('admin')\n            ->starterKit()", (string) file_get_contents($provider)));
}

it('adds <AlfakitCopilot /> to the starter kit header block when adding the AI plugin', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-ai']);
    useStarterKitPanel();
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/Fixtures/StarterKits/react/resources', $sandbox.'/resources');
    $files = AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller::files('react');
    $header = $sandbox.'/'.$files['sidebarHeader'];

    // A header block written before the copilot existed.
    $block = (string) AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller::addWidgetsToHeader((string) file_get_contents($header), 'react', sidebarLayout: true);
    file_put_contents($header, (string) preg_replace('/^[ \t]*<AlfakitCopilot \/>\n/m', '', str_replace('AlfakitCopilot, ', '', $block)));

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['ai'], '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())
        ->toContain('Added <AlfakitCopilot /> to '.$files['sidebarHeader'])
        ->toContain("Turn the app header's copilot on: AiPlugin::make()->copilot()");

    // An app that had the AI plugin before: re-running alfakit:add ai patches the header.
    file_put_contents($header, (string) preg_replace('/^[ \t]*<AlfakitCopilot \/>\n/m', '', str_replace('AlfakitCopilot, ', '', $block)));

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['ai'], '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())
        ->toContain('already set up')
        ->toContain('Added <AlfakitCopilot /> to '.$files['sidebarHeader'])
        ->and(substr_count((string) file_get_contents($header), '<AlfakitCopilot />'))->toBe(1);

    // Nothing left to add: no notes.
    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['ai'], '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())->not->toContain('AlfakitCopilot');

    expect((string) file_get_contents($header))
        ->toContain("import { AlfakitCopilot, AlfakitLanguageSwitcher, AlfakitNotifications, AlfakitSearch } from '@alfakit/react';")
        ->and(substr_count((string) file_get_contents($header), '<AlfakitCopilot />'))->toBe(1);
});

it('adds the team menu <AlfakitTenantSwitcher /> to the starter kit header block when adding Teams', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-teams']);
    useStarterKitPanel();
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/Fixtures/StarterKits/react/resources', $sandbox.'/resources');
    $files = AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller::files('react');
    $header = $sandbox.'/'.$files['sidebarHeader'];
    file_put_contents($header, (string) AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller::addWidgetsToHeader((string) file_get_contents($header), 'react', sidebarLayout: true));
    $this->artisan('make:alfakit-panel', ['id' => 'app'])->assertSuccessful();

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['teams'], '--panel' => 'app', '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())->toContain('Added <AlfakitTenantSwitcher /> (the team menu) to '.$files['sidebarHeader']);

    $contents = (string) file_get_contents($header);

    expect($contents)->toContain("import { AlfakitCopilot, AlfakitLanguageSwitcher, AlfakitNotifications, AlfakitSearch, AlfakitTenantSwitcher } from '@alfakit/react';")
        ->and(substr_count($contents, '<AlfakitTenantSwitcher />'))->toBe(1)
        ->and(strpos($contents, '<AlfakitTenantSwitcher />'))->toBeLessThan(strpos($contents, '<AlfakitSearch />'));

    // Running it again changes nothing.
    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['teams'], '--panel' => 'app', '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())->not->toContain('AlfakitTenantSwitcher')
        ->and((string) file_get_contents($header))->toBe($contents);
});

it('leaves the starter kit header alone when AlfaKit runs as its own panel in a starter kit app', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-teams']);
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/Fixtures/StarterKits/react/resources', $sandbox.'/resources');

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['teams'], '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())->not->toContain('AlfakitTenantSwitcher');
});

it('does not ask to turn the copilot on when the panel already has it', function (): void {
    $sandbox = pluginSandbox($this, ['alfasix/alfakit-ai']);
    useStarterKitPanel();
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/Fixtures/StarterKits/react/resources', $sandbox.'/resources');
    $files = AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller::files('react');
    $header = $sandbox.'/'.$files['sidebarHeader'];
    $block = (string) AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller::addWidgetsToHeader((string) file_get_contents($header), 'react', sidebarLayout: true);
    file_put_contents($header, (string) preg_replace('/^[ \t]*<AlfakitCopilot \/>\n/m', '', str_replace('AlfakitCopilot, ', '', $block)));

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['ai'], '--no-interaction' => true]))->toBe(0);

    $provider = PanelProviderEditor::path('admin');
    file_put_contents($provider, str_replace('AiPlugin::make()', 'AiPlugin::make()->copilot()', (string) file_get_contents($provider)));
    file_put_contents($header, (string) preg_replace('/^[ \t]*<AlfakitCopilot \/>\n/m', '', str_replace('AlfakitCopilot, ', '', $block)));

    expect((string) file_get_contents($provider))->toContain('->copilot()')
        ->and(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['ai'], '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())
        ->toContain('Added <AlfakitCopilot /> to '.$files['sidebarHeader'])
        ->not->toContain("Turn the app header's copilot on");
});

it('says that Teams turns the panel into a team workspace and how to keep the admin panel without teams', function (): void {
    pluginSandbox($this, ['alfasix/alfakit-teams']);
    $this->artisan('make:alfakit-panel', ['id' => 'app'])->assertSuccessful();

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['teams'], '--no-interaction' => true]))->toBe(0)
        ->and($admin = Illuminate\Support\Facades\Artisan::output())
        ->toContain('Teams turns the admin panel into a team workspace')
        ->toContain('php artisan alfakit:add teams --panel=app');

    expect(Illuminate\Support\Facades\Artisan::call('alfakit:add', ['plugin' => ['teams'], '--panel' => 'app', '--no-interaction' => true]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())
        ->toContain('Teams turns the app panel into a team workspace')
        ->not->toContain('--panel=app');
});

it('publishes core tables shared by two plugins once: forms and analytics in one run', function (): void {
    $sandbox = pluginSandbox($this);
    (new Filesystem)->ensureDirectoryExists($sandbox.'/database/migrations');

    $command = ['vendor:publish', '--tag=alfakit-import-export-migrations'];

    // Core publishes single files: recognised, and only "already published" once the app has them.
    expect(AppEnvironment::migrationsAlreadyPublished($command))->toBeFalse();

    // The first plugin of the run (forms) published them under today's date.
    file_put_contents($sandbox.'/database/migrations/2026_10_20_051205_create_alfakit_import_export_tables.php', '<?php');

    expect(AppEnvironment::migrationsAlreadyPublished($command))->toBeTrue();

    $this->artisan('alfakit:add', ['plugin' => ['analytics'], '--no-interaction' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'alfasix/alfakit-analytics', '--no-interaction']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && in_array('--tag=alfakit-import-export-migrations', $process->command, true));
});

it('knows every first-party plugin in an app installed from Packagist', function (): void {
    // An app from Packagist has no monorepo plugins directory and no plugin installed yet.
    $empty = sys_get_temp_dir().'/alfakit-no-plugins-'.uniqid();
    mkdir($empty);
    $original = PluginCatalog::$pluginsDirectory;
    PluginCatalog::$pluginsDirectory = $empty;

    try {
        $known = array_keys(PluginCatalog::all());
    } finally {
        PluginCatalog::$pluginsDirectory = $original;
        rmdir($empty);
    }

    $plugins = array_map('basename', glob(dirname(__DIR__, 3).'/plugins/*', GLOB_ONLYDIR) ?: []);

    expect($plugins)->toHaveCount(27)
        ->and(array_values(array_diff($plugins, $known)))->toBe([]);
});
