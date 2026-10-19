<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\InstallsStarterKit;
use AlfaSix\Alfakit\Commands\Concerns\ShowsBanner;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

#[AsCommand(name: 'alfakit:install', description: 'Install Alfakit: config, panel provider, frontend entry for your JavaScript framework')]
class InstallCommand extends Command
{
    use CanGenerateFiles;
    use InstallsStarterKit;
    use ShowsBanner;

    protected $signature = 'alfakit:install
        {--adapter= : react, vue or svelte}
        {--panel=admin : The id of the first panel}
        {--path= : The URL path of the first panel (defaults to the id)}
        {--ssr : Set up server-side rendering (an SSR entry and the Vite SSR build)}
        {--no-npm : Do not install the npm package}
        {--no-panel : Do not create a panel provider (used by alfakit:migrate-from-filament, which brings its own)}
        {--import-export : Publish the migrations for ImportAction and ExportAction}
        {--standalone : Use Alfakit components in your own Inertia pages instead of a panel (styles, an example form and page)}
        {--starter-kit : Run the panel inside an app made from a Laravel React, Vue or Svelte starter kit (its auth, layout, sidebar and theme)}
        {--no-header-widgets : With --starter-kit, do not add the AlfaKit search and notifications bell to the app header}
        {--scaffold : Set up the frontend toolchain of a fresh Laravel app (Inertia, the framework, its Vite plugin, Tailwind, TypeScript)}
        {--no-composer-script : Do not add alfakit:upgrade to the post-autoload-dump composer script}
        {--force : Overwrite existing files}';

    public function handle(Filesystem $filesystem): int
    {
        $adapter = $this->option('adapter') ?? $this->detectAdapter() ?? select('Which frontend adapter are you using?', [
            'react' => 'React (shadcn/ui)',
            'vue' => 'Vue 3 (shadcn-vue)',
            'svelte' => 'Svelte 5 (shadcn-svelte)',
        ], 'react');

        $panelId = (string) $this->option('panel');
        $path = (string) ($this->option('path') ?? $panelId);
        $force = (bool) $this->option('force');

        $this->banner();
        $this->components->info("Installing AlfaKit for {$adapter}…");

        // 1. Config
        $this->callSilently('vendor:publish', ['--tag' => 'alfakit-config', '--force' => $force]);
        $this->components->task('Published config/alfakit.php');

        if (! $this->option('no-composer-script')) {
            $this->addComposerUpgradeScript($filesystem);
        }

        if ($this->option('scaffold')) {
            $this->scaffold($filesystem, $adapter);
        }

        if ($this->option('import-export')) {
            $this->callSilently('vendor:publish', ['--tag' => 'alfakit-import-export-migrations', '--force' => $force]);
            $this->components->task('Published the import/export migrations (run php artisan migrate)');
        }

        if ($this->option('standalone')) {
            return $this->installStandalone($filesystem, $adapter, $force);
        }

        if ($this->option('starter-kit')) {
            return $this->installStarterKit($adapter, $panelId, $path, $force);
        }

        // 2. Panel provider
        if (! $this->option('no-panel')) {
            $this->call('make:alfakit-panel', ['id' => $panelId, '--path' => $path, '--force' => $force]);
            $this->createNotificationsTable();
        }

        // 3. Frontend entry, stylesheet and tsconfig path
        $entryExtension = $adapter === 'react' ? 'tsx' : 'ts';
        $entryPath = resource_path("js/alfakit/app.{$entryExtension}");

        $this->writeStub("frontend/{$adapter}/app", $entryPath, [], $force);
        $this->writeStub('frontend/alfakit.css', resource_path('css/alfakit.css'), ['adapter' => $adapter], $force);

        if ($adapter !== 'react') {
            $this->updateEnv('ALFAKIT_VITE_ENTRY', 'resources/js/alfakit/app.ts');
        }

        // 4. Vite inputs
        $this->addViteInputs($filesystem, ['resources/css/alfakit.css', "resources/js/alfakit/app.{$entryExtension}"]);

        // 5. Server-side rendering (opt-in)
        if ($this->option('ssr')) {
            $this->installSsr($filesystem, $adapter, $entryExtension, $force);
        }

        // 6. npm package
        if (! $this->option('no-npm')) {
            $this->installNpmPackage($adapter);
        }

        $this->newLine();
        $this->components->info('Alfakit is installed.');
        $this->components->bulletList([
            'Register your panel in bootstrap/providers.php if it was not added automatically: App\\Providers\\Alfakit\\'.Str::studly($panelId).'PanelProvider',
            'Create a user: php artisan make:alfakit-user',
            'Create a resource: php artisan make:alfakit-resource Post --generate',
            'Run the migrations: php artisan migrate',
            'Run your dev server (npm run dev) and visit /'.$path,
        ]);

        return self::SUCCESS;
    }

    /**
     * The generated panel turns on database notifications (and the starter-kit install adds
     * the bell), so the app needs Laravel's `notifications` table: create its migration
     * unless the app already has one.
     */
    protected function createNotificationsTable(): void
    {
        if (glob(database_path('migrations/*_create_notifications_table.php')) !== []) {
            return;
        }

        $this->callSilently('make:notifications-table');
        $this->components->task('Created the notifications table migration');
    }

    /**
     * `--standalone`: no panel. The renderer's stylesheet joins the application's own
     * Tailwind entry, and an example form plus page show the wiring.
     */
    protected function installStandalone(Filesystem $filesystem, string $adapter, bool $force): int
    {
        $css = resource_path('css/app.css');

        if ($filesystem->exists($css)) {
            $contents = $filesystem->get($css);

            if (str_contains($contents, "@alfakit/{$adapter}/standalone.css")) {
                $this->components->task('Alfakit styles already imported in resources/css/app.css');
            } else {
                $filesystem->put($css, static::addStandaloneStyles($contents, $adapter));
                $this->components->task('Imported the Alfakit styles in resources/css/app.css');
            }
        } else {
            $this->components->warn("resources/css/app.css not found. Add `@import '@alfakit/{$adapter}/standalone.css';` and `@source '../../node_modules/@alfakit/{$adapter}/dist';` to your stylesheet.");
        }

        $this->call('make:alfakit-form', ['name' => 'ContactForm', '--force' => $force]);

        $pageExtension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$adapter];
        $pageName = $adapter === 'react' ? 'contact' : 'Contact';
        $this->writeStub("standalone/pages/{$adapter}", resource_path("js/pages/{$pageName}.{$pageExtension}"), [], $force);

        if (! $this->option('no-npm')) {
            $this->installNpmPackage($adapter);
        }

        $this->newLine();
        $this->components->info('Alfakit is installed for use outside panels.');
        $this->components->bulletList([
            "Add a route: Route::inertia('/contact', '{$pageName}', ['form' => \\App\\Alfakit\\Standalone\\ContactForm::make()]);",
            'Generate more: php artisan make:alfakit-table OrdersTable --model=Order, make:alfakit-infolist, make:alfakit-component',
            'Place <AlfakitToaster /> once in your app layout for notifications (rendered automatically otherwise)',
            'Docs: '.config('alfakit.docs_url').'/standalone/overview',
        ]);

        return self::SUCCESS;
    }

    /**
     * Add the renderer's stylesheet import and `@source` after the Tailwind import.
     */
    public static function addStandaloneStyles(string $contents, string $adapter): string
    {
        $lines = "@import '@alfakit/{$adapter}/standalone.css';\n@source '../../node_modules/@alfakit/{$adapter}/dist';\n";

        $updated = preg_replace('/^(@import\s+[\'"]tailwindcss[\'"];[ \t]*\n)/m', "$1{$lines}", $contents, 1, $count);

        return $count === 1 && $updated !== null ? $updated : $lines.$contents;
    }

    protected function detectAdapter(): ?string
    {
        $packageJson = base_path('package.json');

        if (! file_exists($packageJson)) {
            return null;
        }

        $dependencies = json_decode((string) file_get_contents($packageJson), true)['dependencies'] ?? [];

        return match (true) {
            isset($dependencies['@inertiajs/react']) => 'react',
            isset($dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@inertiajs/svelte']) => 'svelte',
            default => null,
        };
    }

    /**
     * @param  array<string>  $inputs
     */
    protected function addViteInputs(Filesystem $filesystem, array $inputs): void
    {
        foreach (['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'] as $candidate) {
            $configPath = base_path($candidate);

            if (! $filesystem->exists($configPath)) {
                continue;
            }

            $contents = $filesystem->get($configPath);

            if (str_contains($contents, 'resources/js/alfakit/')) {
                $this->components->task('Vite inputs already configured');

                return;
            }

            $updated = preg_replace_callback(
                '/input:\s*\[([^\]]*)\]/s',
                static fn (array $matches): string => 'input: ['.rtrim($matches[1]).(trim($matches[1]) === '' ? '' : ', ')."'".implode("', '", $inputs)."']",
                $contents,
                1,
                $count,
            );

            if ($count === 1 && $updated !== null) {
                $filesystem->put($configPath, $updated);
                $this->components->task("Added Alfakit entries to {$candidate}");
            } else {
                $this->components->warn("Could not update {$candidate} automatically. Add these inputs to laravel-vite-plugin: ".implode(', ', $inputs));
            }

            return;
        }

        $this->components->warn('No vite.config found. Add resources/css/alfakit.css and the alfakit entry to your Vite inputs.');
    }

    /**
     * Write the SSR entry and point laravel-vite-plugin and @inertiajs/vite at it, so
     * `vite build --ssr` emits bootstrap/ssr/ssr.js for `php artisan inertia:start-ssr`.
     */
    protected function installSsr(Filesystem $filesystem, string $adapter, string $extension, bool $force): void
    {
        $entry = "resources/js/alfakit/ssr.{$extension}";

        $this->writeStub("frontend/{$adapter}/ssr", base_path($entry), [], $force);

        foreach (['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'] as $candidate) {
            $configPath = base_path($candidate);

            if (! $filesystem->exists($configPath)) {
                continue;
            }

            $updated = static::addViteSsrEntry($filesystem->get($configPath), $entry);

            if ($updated === null) {
                $this->components->warn("Could not update {$candidate} automatically. Add `ssr: '{$entry}'` to laravel() and `inertia({ ssr: '{$entry}' })`.");
            } else {
                $filesystem->put($configPath, $updated);
                $this->components->task("Added the SSR entry to {$candidate}");
            }

            break;
        }

        $packageJson = base_path('package.json');

        if ($filesystem->exists($packageJson)) {
            $package = json_decode($filesystem->get($packageJson), true);

            if (is_array($package) && ! isset($package['scripts']['build:ssr'])) {
                $package['scripts'] = [...($package['scripts'] ?? []), 'build:ssr' => 'vite build && vite build --ssr'];
                $filesystem->put($packageJson, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                $this->components->task('Added the build:ssr script to package.json');
            }
        }

        $this->components->bulletList([
            'Build both bundles: npm run build:ssr',
            'Start the SSR server: php artisan inertia:start-ssr (keep it running, e.g. with Supervisor)',
            'SSR is enabled by config/inertia.php (ssr.enabled, INERTIA_SSR_ENABLED)',
        ]);
    }

    /**
     * Point laravel-vite-plugin (`ssr: '…'`) and @inertiajs/vite (`inertia({ ssr: '…' })`)
     * at the SSR entry. Returns null when the config does not have the expected shape.
     */
    public static function addViteSsrEntry(string $contents, string $entry): ?string
    {
        if (str_contains($contents, $entry)) {
            return $contents;
        }

        // laravel({ input: [...], ... }) gains `ssr: '…'` right after its inputs.
        $updated = preg_replace(
            '/(laravel\(\{\s*input:\s*(?:\[[^\]]*\]|[\'"][^\'"]*[\'"]),)/s',
            "$1\n            ssr: '{$entry}',",
            $contents,
            1,
            $count,
        );

        if ($updated === null || $count !== 1) {
            return null;
        }

        // inertia() → inertia({ ssr: '…' }); an inertia({...}) call with options is left alone.
        if (preg_match('/\binertia\(\s*\)/', $updated) === 1) {
            $updated = (string) preg_replace('/\binertia\(\s*\)/', "inertia({ ssr: '{$entry}' })", $updated, 1);
        }

        return $updated;
    }

    /**
     * Add `@php artisan alfakit:upgrade` to composer.json's `post-autoload-dump`, so plugin
     * assets are republished and caches cleared on every `composer install`/`update`.
     */
    protected function addComposerUpgradeScript(Filesystem $filesystem): void
    {
        $path = base_path('composer.json');

        if (! $filesystem->exists($path)) {
            return;
        }

        $composer = json_decode($filesystem->get($path), true);

        if (! is_array($composer)) {
            $this->components->warn('Could not read composer.json. Add "@php artisan alfakit:upgrade" to its post-autoload-dump scripts.');

            return;
        }

        $updated = static::addUpgradeScript($composer);

        if ($updated === $composer) {
            return;
        }

        $filesystem->put($path, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        $this->components->task('Added alfakit:upgrade to the post-autoload-dump composer script');
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return array<string, mixed>
     */
    public static function addUpgradeScript(array $composer): array
    {
        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $dump = $scripts['post-autoload-dump'] ?? [];
        $dump = is_array($dump) ? array_values($dump) : [$dump];

        foreach ($dump as $command) {
            if (is_string($command) && str_contains($command, 'alfakit:upgrade')) {
                return $composer;
            }
        }

        $dump[] = '@php artisan alfakit:upgrade';
        $scripts['post-autoload-dump'] = $dump;
        $composer['scripts'] = $scripts;

        return $composer;
    }

    /**
     * The npm packages `--scaffold` installs for an adapter.
     *
     * @return array{dependencies: list<string>, devDependencies: list<string>}
     */
    public static function scaffoldPackages(string $adapter): array
    {
        return match ($adapter) {
            'vue' => ['dependencies' => ['@inertiajs/vue3', 'vue'], 'devDependencies' => ['@vitejs/plugin-vue', 'tailwindcss', '@tailwindcss/vite', 'typescript', 'vue-tsc']],
            'svelte' => ['dependencies' => ['@inertiajs/svelte', 'svelte'], 'devDependencies' => ['@sveltejs/vite-plugin-svelte', 'tailwindcss', '@tailwindcss/vite', 'typescript']],
            default => ['dependencies' => ['@inertiajs/react', 'react', 'react-dom'], 'devDependencies' => ['@vitejs/plugin-react', '@types/react', '@types/react-dom', 'tailwindcss', '@tailwindcss/vite', 'typescript']],
        };
    }

    /**
     * Add the framework's Vite plugin to a `vite.config` (import and `plugins: [...]`
     * entry). Returns the contents unchanged when it is there, null when the config has no
     * `plugins` array to add it to.
     */
    public static function addVitePlugin(string $contents, string $adapter): ?string
    {
        [$package, $import, $call] = match ($adapter) {
            'vue' => ['@vitejs/plugin-vue', "import vue from '@vitejs/plugin-vue';", 'vue()'],
            'svelte' => ['@sveltejs/vite-plugin-svelte', "import { svelte } from '@sveltejs/vite-plugin-svelte';", 'svelte()'],
            default => ['@vitejs/plugin-react', "import react from '@vitejs/plugin-react';", 'react()'],
        };

        if (str_contains($contents, $package)) {
            return $contents;
        }

        $updated = preg_replace('/(plugins:\s*\[)/', "$1\n        {$call},", $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            return null;
        }

        // After the last import, or at the top.
        if (preg_match_all('/^import [^;]+;[ \t]*$/m', $updated, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($matches[0]);

            return substr_replace($updated, "\n{$import}", (int) $last[1] + strlen($last[0]), 0);
        }

        return "{$import}\n{$updated}";
    }

    /**
     * `--scaffold`: the frontend toolchain of a fresh Laravel app, so the panel (and
     * standalone components) can build: Inertia, the framework, its Vite plugin, Tailwind
     * CSS 4 and TypeScript.
     */
    protected function scaffold(Filesystem $filesystem, string $adapter): void
    {
        foreach (['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'] as $candidate) {
            $configPath = base_path($candidate);

            if (! $filesystem->exists($configPath)) {
                continue;
            }

            $updated = static::addVitePlugin($filesystem->get($configPath), $adapter);

            if ($updated === null) {
                $this->components->warn("Could not add the {$adapter} plugin to {$candidate}; add it to `plugins` yourself.");
            } else {
                $filesystem->put($configPath, $updated);
                $this->components->task("Added the {$adapter} Vite plugin to {$candidate}");
            }

            break;
        }

        if ($this->option('no-npm')) {
            $packages = static::scaffoldPackages($adapter);
            $this->components->warn('Skipped npm. Install: '.implode(' ', $packages['dependencies']).' and, as dev dependencies, '.implode(' ', $packages['devDependencies']));

            return;
        }

        foreach (static::scaffoldPackages($adapter) as $kind => $packages) {
            $manager = MakeThemeCommand::detectPackageManager();
            $command = match ($manager) {
                'npm' => ['npm', 'install', ...($kind === 'devDependencies' ? ['--save-dev'] : []), ...$packages],
                default => [$manager, 'add', ...($kind === 'devDependencies' ? ['-D'] : []), ...$packages],
            };

            $process = new Process($command, base_path(), timeout: 600);
            $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

            if (! $process->isSuccessful()) {
                $this->components->error('Failed to install the scaffold packages. Run `'.implode(' ', $command).'` manually.');

                return;
            }
        }

        $this->components->task('Installed the frontend toolchain');
    }

    protected function installNpmPackage(string $adapter): void
    {
        $package = "@alfakit/{$adapter}";

        $manager = match (true) {
            file_exists(base_path('pnpm-lock.yaml')) => ['pnpm', 'add', $package],
            file_exists(base_path('bun.lockb')), file_exists(base_path('bun.lock')) => ['bun', 'add', $package],
            file_exists(base_path('yarn.lock')) => ['yarn', 'add', $package],
            default => ['npm', 'install', $package],
        };

        if (! confirm("Install {$package} with {$manager[0]}?", true)) {
            $this->components->warn("Skipped. Install it yourself: {$manager[0]} {$manager[1]} {$package}");

            return;
        }

        $process = new Process($manager, base_path(), timeout: 300);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->components->error("Failed to install {$package}. Run `".implode(' ', $manager).'` manually.');
        }
    }

    protected function updateEnv(string $key, string $value): void
    {
        foreach (['.env', '.env.example'] as $file) {
            $path = base_path($file);

            if (! file_exists($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (str_contains($contents, "{$key}=")) {
                $contents = (string) preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $contents);
            } else {
                $contents = rtrim($contents).PHP_EOL.PHP_EOL."{$key}={$value}".PHP_EOL;
            }

            file_put_contents($path, $contents);
        }
    }
}
