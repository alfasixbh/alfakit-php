<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * `alfakit:realtime`: Reverb, broadcasting and Echo for live notifications. Composer, npm
 * and artisan subprocesses are faked.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-realtime-'.uniqid();
    $files = new Filesystem;

    foreach (['app/Providers/Alfakit', 'bootstrap', 'config', 'resources/js/alfakit', 'routes', 'vendor/composer', 'app/Alfakit'] as $directory) {
        $files->ensureDirectoryExists("{$this->sandbox}/{$directory}");
    }

    file_put_contents("{$this->sandbox}/.env", "APP_NAME=Demo\nBROADCAST_CONNECTION=log\n");
    file_put_contents("{$this->sandbox}/.env.example", "APP_NAME=Laravel\nBROADCAST_CONNECTION=log\n");
    file_put_contents("{$this->sandbox}/package.json", json_encode(['dependencies' => ['@inertiajs/react' => '^2.0', 'react' => '^19.0']]));
    file_put_contents("{$this->sandbox}/vendor/composer/installed.json", json_encode(['packages' => []]));
    file_put_contents("{$this->sandbox}/resources/js/alfakit/app.tsx", "import { createAlfakit } from '@alfakit/react';\n\nvoid createAlfakit({});\n");

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    $this->app->useConfigPath($this->sandbox.'/config');
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit']);

    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    Process::fake();
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    $this->app->useConfigPath($this->originalBasePath.'/config');
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('installs reverb, runs the broadcasting installers, writes keys, wires Echo and enables the panel option', function (): void {
    $this->artisan('alfakit:realtime')
        ->expectsConfirmation('Install laravel/reverb with Composer?', 'yes')
        ->expectsConfirmation('Install laravel-echo, pusher-js from npm?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'laravel/reverb', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'install:broadcasting', '--reverb', '--without-reverb', '--without-node', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, 1) === ['artisan', 'reverb:install', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['npm', 'install', '--save-dev', 'laravel-echo', 'pusher-js']);

    $env = file_get_contents("{$this->sandbox}/.env");
    $example = file_get_contents("{$this->sandbox}/.env.example");

    expect($env)->toMatch('/^REVERB_APP_ID=\d{6}$/m')
        ->toMatch('/^REVERB_APP_KEY=[a-z0-9]{20}$/m')
        ->toContain('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"')
        ->toContain('BROADCAST_CONNECTION=reverb')
        ->not->toContain('BROADCAST_CONNECTION=log')
        ->and($example)->toContain("REVERB_APP_KEY=\n")->toContain('VITE_REVERB_HOST="${REVERB_HOST}"');

    expect(file_get_contents("{$this->sandbox}/resources/js/alfakit/echo.ts"))->toContain("broadcaster: 'reverb'")->toContain("typeof window === 'undefined'")
        // Loaded when AlfaKit first needs Echo, not imported by the entry: laravel-echo and
        // pusher-js stay out of the entry's chunk (R-19: a blank panel with a second entry).
        ->and(file_get_contents("{$this->sandbox}/resources/js/alfakit/app.tsx"))
        ->toContain("createAlfakit({\n    echo: () => import('./echo').then((module) => module.default()),")
        ->not->toContain("import './echo';")
        ->and(file_get_contents(PanelProviderEditor::path('admin')))->toContain('->realtimeNotifications()');
});

it('is idempotent and prints commands instead of installing with --no-install', function (): void {
    file_put_contents("{$this->sandbox}/routes/channels.php", '<?php');
    file_put_contents("{$this->sandbox}/bootstrap/app.php", "<?php\n    ->withRouting(\n        channels: __DIR__.'/../routes/channels.php',\n    )\n");
    file_put_contents("{$this->sandbox}/config/broadcasting.php", '<?php return [];');

    $this->artisan('alfakit:realtime', ['--no-install' => true])
        ->expectsOutputToContain('composer require laravel/reverb')
        ->expectsOutputToContain('npm install --save-dev laravel-echo pusher-js')
        ->assertSuccessful();

    $env = file_get_contents("{$this->sandbox}/.env");

    $this->artisan('alfakit:realtime', ['--no-install' => true])->assertSuccessful();

    Process::assertNothingRan();

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    expect(file_get_contents("{$this->sandbox}/.env"))->toBe($env)
        ->and(substr_count($provider, '->realtimeNotifications()'))->toBe(1)
        ->and(substr_count(file_get_contents("{$this->sandbox}/resources/js/alfakit/app.tsx"), "import('./echo')"))->toBe(1);
});

it('upgrades a panel entry that imports echo.ts to load it from createAlfakit({ echo })', function (): void {
    $old = "import './echo';\nimport { createAlfakit } from '@alfakit/svelte';\n\nvoid createAlfakit({\n    discover: {},\n});\n";

    expect(AlfaSix\Alfakit\Commands\RealtimeCommand::loadEchoInPanelEntry($old))
        ->toBe("import { createAlfakit } from '@alfakit/svelte';\n\nvoid createAlfakit({\n    echo: () => import('./echo').then((module) => module.default()),\n    discover: {},\n});\n")
        ->and(AlfaSix\Alfakit\Commands\RealtimeCommand::loadEchoInPanelEntry("import './echo';\nboot();\n"))->toBeNull();

    // An echo.ts from an earlier version has no default export: the entry keeps importing it.
    file_put_contents("{$this->sandbox}/resources/js/alfakit/echo.ts", "export {};\n");
    file_put_contents("{$this->sandbox}/resources/js/alfakit/app.tsx", $old);

    $this->artisan('alfakit:realtime', ['--no-install' => true])->assertSuccessful();

    expect(file_get_contents("{$this->sandbox}/resources/js/alfakit/app.tsx"))->toBe($old);

    $this->artisan('alfakit:realtime', ['--no-install' => true, '--force' => true])->assertSuccessful();

    expect(file_get_contents("{$this->sandbox}/resources/js/alfakit/app.tsx"))->toContain("import('./echo')")->not->toContain("import './echo';");
});

it('formats echo.ts and the edited entry with the app formatter', function (): void {
    (new Filesystem)->ensureDirectoryExists("{$this->sandbox}/node_modules/.bin");
    file_put_contents("{$this->sandbox}/node_modules/.bin/prettier", '');
    file_put_contents("{$this->sandbox}/.prettierrc", '{ "semi": true, "tabWidth": 4 }');

    $this->artisan('alfakit:realtime', ['--no-install' => true])->assertSuccessful();

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (array) $process->command;

        return array_slice($command, 0, 2) === ["{$this->sandbox}/node_modules/.bin/prettier", '--write']
            && in_array("{$this->sandbox}/resources/js/alfakit/echo.ts", $command, true)
            && in_array("{$this->sandbox}/resources/js/alfakit/app.tsx", $command, true);
    });
});

it('adds the framework Echo package when install:broadcasting configured it in the app entry', function (): void {
    file_put_contents("{$this->sandbox}/vendor/composer/installed.json", json_encode(['packages' => [['name' => 'laravel/reverb']]]));
    file_put_contents("{$this->sandbox}/config/reverb.php", '<?php return [];');
    file_put_contents("{$this->sandbox}/resources/js/app.tsx", "import { configureEcho } from '@laravel/echo-react';\n");

    $this->artisan('alfakit:realtime')
        ->expectsConfirmation('Install laravel-echo, pusher-js, @laravel/echo-react from npm?', 'no')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && in_array('reverb:install', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'composer');
});

it('keeps the broadcast connection while Reverb is not installed, so the app keeps booting', function (): void {
    $this->artisan('alfakit:realtime', ['--no-install' => true])
        ->expectsOutputToContain('kept log')
        ->expectsOutputToContain('BROADCAST_CONNECTION=reverb in .env')
        ->assertSuccessful();

    expect(file_get_contents("{$this->sandbox}/.env"))->toContain('BROADCAST_CONNECTION=log')->not->toContain('BROADCAST_CONNECTION=reverb')
        ->toMatch('/^REVERB_APP_KEY=[a-z0-9]{20}$/m');
});

it('switches to reverb once laravel/reverb is installed', function (): void {
    file_put_contents("{$this->sandbox}/vendor/composer/installed.json", json_encode(['packages' => [['name' => 'laravel/reverb']]]));

    $this->artisan('alfakit:realtime', ['--no-install' => true])->assertSuccessful();

    expect(file_get_contents("{$this->sandbox}/.env"))->toContain('BROADCAST_CONNECTION=reverb');
});
