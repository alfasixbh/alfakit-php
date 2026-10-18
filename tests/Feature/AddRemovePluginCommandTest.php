<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

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
        ]);

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
