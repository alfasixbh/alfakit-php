<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\FrontendFormatter;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;

/**
 * Live updates over Laravel Reverb: installs laravel/reverb, runs Laravel's
 * `install:broadcasting --reverb` (channels route, broadcasting config) and
 * `reverb:install`, writes the REVERB_* and VITE_REVERB_* keys, sets up Laravel Echo as
 * `window.Echo` in the panel entry and turns on `->realtimeNotifications()`. Every step
 * is skipped when already done.
 */
#[AsCommand(name: 'alfakit:realtime', description: 'Set up Laravel Reverb and Echo for live panel notifications')]
class RealtimeCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'alfakit:realtime
        {--panel= : The panel to enable realtime notifications on (default: admin)}
        {--no-install : Do not run composer or npm (print the commands instead)}
        {--force : Overwrite resources/js/alfakit/echo.ts}';

    /** @var array<int, array{0: string, 1: string}> */
    protected array $steps = [];

    public function handle(Filesystem $files): int
    {
        $this->steps = [];
        $panelId = (string) ($this->option('panel') ?: 'admin');
        $install = ! $this->option('no-install');

        $hasReverb = $this->installReverb($install);
        $this->writeEnvironment();
        $this->installBroadcasting($hasReverb);
        $this->installEchoClient($files);
        $this->installNpmPackages($install);
        $this->enableOnPanel($files, $panelId);

        $this->newLine();
        $this->components->info('Realtime is set up.');

        foreach ($this->steps as [$step, $status]) {
            $this->components->twoColumnDetail($step, $status);
        }

        $this->components->bulletList([
            'Start the WebSocket server: php artisan reverb:start (keep it running, e.g. with Supervisor)',
            'Run a queue worker, broadcasts are queued: php artisan queue:work',
            'Rebuild your assets: npm run dev',
            'Send live toasts: Notification::make()->title(\'Done\')->broadcast($user)',
            in_array('realtime', static::panelCalls(), true)
                ? 'Live presence, conflict banners and live tables/widgets are on with ->realtime()'
                : 'TODO: live tables and widgets (->realtime()) come in a later AlfaKit release; ->realtimeNotifications() is enabled today.',
        ]);

        return self::SUCCESS;
    }

    protected function installReverb(bool $install): bool
    {
        if (AppEnvironment::hasComposerPackage('laravel/reverb')) {
            $this->steps[] = ['laravel/reverb', '<fg=gray>already installed</>'];

            return true;
        }

        if (! $install || ! confirm('Install laravel/reverb with Composer?', true)) {
            $this->steps[] = ['laravel/reverb', '<fg=yellow>skipped</> (composer require laravel/reverb)'];

            return false;
        }

        $result = AppEnvironment::composerRequire(['laravel/reverb'], output: fn (string $buffer) => $this->output->write($buffer));
        $this->steps[] = ['laravel/reverb', $result->successful() ? '<fg=green>installed</>' : '<fg=red>failed</> (composer require laravel/reverb)'];

        return $result->successful();
    }

    /**
     * The REVERB_* keys `reverb:install` would add (so it finds them and keeps them), the
     * VITE_* copies Echo reads, and BROADCAST_CONNECTION=reverb.
     */
    protected function writeEnvironment(): void
    {
        $env = base_path('.env');

        if (! is_file($env)) {
            $this->steps[] = ['.env', '<fg=yellow>missing, skipped</>'];

            return;
        }

        $written = AppEnvironment::writeEnv([
            'REVERB_APP_ID' => (string) random_int(100_000, 999_999),
            'REVERB_APP_KEY' => Str::lower(Str::random(20)),
            'REVERB_APP_SECRET' => Str::lower(Str::random(20)),
            'REVERB_HOST' => 'localhost',
            'REVERB_PORT' => '8080',
            'REVERB_SCHEME' => 'http',
        ]);

        $written = [...$written, ...AppEnvironment::writeEnv(static::viteVariables(), raw: true)];

        if (AppEnvironment::envValue('BROADCAST_CONNECTION') !== 'reverb') {
            $written = [...$written, ...AppEnvironment::writeEnv(['BROADCAST_CONNECTION' => 'reverb'], overwrite: true)];
        }

        AppEnvironment::writeEnv([
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => '',
            'REVERB_APP_KEY' => '',
            'REVERB_APP_SECRET' => '',
            'REVERB_HOST' => 'localhost',
            'REVERB_PORT' => '8080',
            'REVERB_SCHEME' => 'http',
        ], base_path('.env.example'));
        AppEnvironment::writeEnv(static::viteVariables(), base_path('.env.example'), raw: true);

        $this->steps[] = ['.env', $written === [] ? '<fg=gray>already configured</>' : '<fg=green>'.count($written).' keys written</>'];
    }

    /**
     * @return array<string, string>
     */
    public static function viteVariables(): array
    {
        return [
            'VITE_REVERB_APP_KEY' => '"${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST' => '"${REVERB_HOST}"',
            'VITE_REVERB_PORT' => '"${REVERB_PORT}"',
            'VITE_REVERB_SCHEME' => '"${REVERB_SCHEME}"',
        ];
    }

    /**
     * Laravel's own installers: `install:broadcasting --reverb` (channels route, config,
     * bootstrap/app.php) and `reverb:install` (config/reverb.php). Run in a fresh process,
     * so a package required a moment ago is loaded.
     */
    protected function installBroadcasting(bool $hasReverb): void
    {
        $bootstrap = base_path('bootstrap/app.php');
        $hasChannels = is_file(base_path('routes/channels.php'))
            && (! is_file($bootstrap) || preg_match('/^\s*channels:/m', (string) file_get_contents($bootstrap)) === 1);

        if ($hasChannels && is_file(config_path('broadcasting.php'))) {
            $this->steps[] = ['install:broadcasting', '<fg=gray>already done</>'];
        } else {
            $result = AppEnvironment::artisan(['install:broadcasting', '--reverb', '--without-reverb', '--without-node', '--no-interaction'], fn (string $buffer) => $this->output->write($buffer));
            $this->steps[] = ['install:broadcasting', $result->successful() ? '<fg=green>done</>' : '<fg=red>failed</> (php artisan install:broadcasting --reverb)'];
        }

        if (! $hasReverb) {
            return;
        }

        if (is_file(config_path('reverb.php'))) {
            $this->steps[] = ['reverb:install', '<fg=gray>already done</>'];

            return;
        }

        $result = AppEnvironment::artisan(['reverb:install', '--no-interaction'], fn (string $buffer) => $this->output->write($buffer));
        $this->steps[] = ['reverb:install', $result->successful() ? '<fg=green>done</>' : '<fg=red>failed</> (php artisan reverb:install)'];
    }

    /**
     * `resources/js/alfakit/echo.ts`, imported first by the panel entry.
     */
    protected function installEchoClient(Filesystem $files): void
    {
        $echo = resource_path('js/alfakit/echo.ts');
        // Written files go through the app's formatter (vp fmt / oxfmt / Prettier), like
        // every other generated frontend file.
        $written = [];

        if (! $files->exists($echo) || $this->option('force')) {
            $files->ensureDirectoryExists(dirname($echo));
            $files->put($echo, $files->get($this->stubPath('realtime/echo')));
            $written[] = $echo;
            $this->steps[] = ['resources/js/alfakit/echo.ts', '<fg=green>created</>'];
        } else {
            $this->steps[] = ['resources/js/alfakit/echo.ts', '<fg=gray>exists</>'];
        }

        $entries = [
            resource_path('js/alfakit/app.tsx') => './echo',
            resource_path('js/alfakit/app.ts') => './echo',
            resource_path('js/app.tsx') => './alfakit/echo',
            resource_path('js/app.ts') => './alfakit/echo',
            resource_path('js/app.js') => './alfakit/echo',
        ];

        foreach ($entries as $entry => $import) {
            if (! $files->exists($entry)) {
                continue;
            }

            $relative = Str::after($entry, base_path().'/');
            $contents = $files->get($entry);

            if (str_contains($contents, "'{$import}'") || str_contains($contents, "\"{$import}\"")) {
                $this->steps[] = [$relative, '<fg=gray>imports Echo</>'];
            } else {
                $files->put($entry, "import '{$import}';\n".$contents);
                $written[] = $entry;
                $this->steps[] = [$relative, '<fg=green>imports Echo</>'];
            }

            FrontendFormatter::format($written);

            return;
        }

        FrontendFormatter::format($written);

        $this->steps[] = ['Echo import', '<fg=yellow>no panel entry found</>: import \'./alfakit/echo\' in your browser entry'];
    }

    protected function installNpmPackages(bool $install): void
    {
        $packages = ['laravel-echo', 'pusher-js'];

        // `install:broadcasting` configures Echo in a React or Vue app's main file with
        // Laravel's framework package.
        foreach (['react' => '@laravel/echo-react', 'vue' => '@laravel/echo-vue'] as $package) {
            foreach (['js/app.tsx', 'js/app.ts', 'js/app.jsx', 'js/app.js'] as $file) {
                if (is_file(resource_path($file)) && str_contains((string) file_get_contents(resource_path($file)), $package)) {
                    $packages[] = $package;

                    break;
                }
            }
        }

        $missing = array_values(array_filter(array_unique($packages), static fn (string $package): bool => ! AppEnvironment::hasNpmPackage($package)));

        if ($missing === []) {
            $this->steps[] = ['npm packages', '<fg=gray>already installed</>'];

            return;
        }

        $command = implode(' ', AppEnvironment::npmInstallCommand($missing, dev: true));

        if (! $install || ! confirm('Install '.implode(', ', $missing).' from npm?', true)) {
            $this->steps[] = ['npm packages', "<fg=yellow>skipped</> ({$command})"];

            return;
        }

        $result = AppEnvironment::npmInstall($missing, dev: true, output: fn (string $buffer) => $this->output->write($buffer));
        $this->steps[] = ['npm packages', $result->successful() ? '<fg=green>installed</>' : "<fg=red>failed</> ({$command})"];
    }

    /**
     * `->realtimeNotifications()`, plus `->realtime()` (live collaboration) when this AlfaKit
     * version has it.
     */
    protected function enableOnPanel(Filesystem $files, string $panelId): void
    {
        $path = PanelProviderEditor::path($panelId);
        $relative = Str::after($path, base_path().'/');
        $calls = static::panelCalls();
        $label = implode(' ', array_map(static fn (string $call): string => "->{$call}()", $calls));

        if (! $files->exists($path)) {
            $this->steps[] = [$label, "<fg=yellow>{$relative} not found</>: add it to your panel"];

            return;
        }

        $original = $files->get($path);
        $contents = $original;

        foreach ($calls as $call) {
            if (! PanelProviderEditor::hasCall($contents, $call)) {
                $contents = PanelProviderEditor::addCall($contents, "{$call}()") ?? $contents;
            }
        }

        if ($contents === $original) {
            $missing = array_filter($calls, static fn (string $call): bool => ! PanelProviderEditor::hasCall($original, $call));
            $this->steps[] = [$label, $missing === [] ? '<fg=gray>already enabled</>' : "<fg=yellow>add it to {$relative}</>"];

            return;
        }

        $files->put($path, $contents);
        $this->steps[] = [$label, "<fg=green>enabled on {$panelId}</>"];
    }

    /**
     * @return array<int, string>
     */
    public static function panelCalls(): array
    {
        return method_exists(\AlfaSix\Alfakit\Panel::class, 'realtime') ? ['realtime', 'realtimeNotifications'] : ['realtimeNotifications'];
    }
}
