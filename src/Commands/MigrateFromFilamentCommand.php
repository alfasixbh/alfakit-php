<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Migration\FilamentDiscovery;
use AlfaSix\Alfakit\Migration\FilamentMigrator;
use AlfaSix\Alfakit\Migration\MigrationReport;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * One command from a FilamentPHP v3/v4/v5 application to AlfaKit:
 *
 * 1. discover the Filament code (directories, PHP files, Livewire components, Blade views,
 *    themes, config, plugins, composer scripts) and the installed version;
 * 2. rewrite PHP (Rector set when `vendor/bin/rector` exists, the built-in token rewriter
 *    otherwise), move `app/Filament` → `app/Alfakit` and the panel providers, register them;
 * 3. write `alfakit-migration-report.md` with every non-convertible item (file:line, what,
 *    suggested change);
 * 4. install the frontend (`alfakit:install`), remove the Filament packages and clear caches.
 *
 * `--dry-run` prints everything and writes the report without touching files. The command is
 * idempotent: a second run finds nothing to migrate.
 */
#[AsCommand(name: 'alfakit:migrate-from-filament', description: 'Migrate a FilamentPHP application to AlfaKit: rewrite PHP, move directories, install the frontend and report what needs a manual decision')]
class MigrateFromFilamentCommand extends Command
{
    protected $signature = 'alfakit:migrate-from-filament
        {--adapter= : react, vue or svelte (detected from package.json when omitted)}
        {--dry-run : Show what would change and write the report without modifying files}
        {--path= : The application root to migrate (defaults to this application)}
        {--keep-filament : Do not remove the filament/* packages from composer.json}
        {--force : Run on a dirty git working tree}
        {--no-npm : Do not install the @alfakit npm package}
        {--rector= : Also run the Rector set first: a path to the Rector binary, or "auto" for the app\'s vendor/bin/rector (default: the built-in rewriter only)}';

    public function handle(Filesystem $files): int
    {
        $basePath = rtrim((string) ($this->option('path') ?: $this->laravel->basePath()), '/');
        $dryRun = (bool) $this->option('dry-run');

        try {
            FilamentMigrator::assertDirectory($basePath);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rector = (string) $this->option('rector');

        $migrator = (new FilamentMigrator(
            $basePath,
            $files,
            dryRun: $dryRun,
            force: (bool) $this->option('force'),
            rectorBinary: ! in_array($rector, ['', 'none', 'auto'], true) ? $rector : null,
            useRector: ! in_array($rector, ['', 'none'], true),
        ))->onLog(fn (string $message) => $this->components->task(($dryRun ? '[dry run] ' : '').$message));

        $discovery = $migrator->discover();
        $adapter = (string) ($this->option('adapter') ?: $discovery->adapter ?: 'react');
        $migrator->adapter($adapter);

        $this->components->info(sprintf('%sMigrating %s from Filament %s to AlfaKit (%s)', $dryRun ? '[dry run] ' : '', $basePath, $discovery->version, $adapter));
        $this->summarizeDiscovery($discovery);

        if (($problem = $migrator->checkWorkingTree()) !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        $originalBasePath = $this->laravel->basePath();
        $this->laravel->setBasePath($basePath);

        try {
            $migrated = $migrator->run();

            if (! $migrated) {
                $this->components->info('Nothing to migrate: no Filament code found.');
            }

            $installed = $this->installFrontend($discovery, $migrator->report, $adapter, $dryRun);

            if ($installed && ! $this->option('keep-filament')) {
                $this->removeFilamentPackages($discovery, $migrator->report, $dryRun);
            }

            if ($installed && ! $dryRun && ($migrated || $discovery->allPackages() !== [])) {
                $this->callSilently('alfakit:optimize-clear');
                $migrator->report->action('Cleared the cached panel components (`alfakit:optimize-clear`).');

                // Views compiled while Livewire was installed call its Blade precompiler, so
                // the first `npm run build` (Wayfinder renders a Blade view) failed once
                // Filament was gone (W-S1-11). Like `php artisan view:clear`.
                foreach (glob($this->laravel->basePath('storage/framework/views/*.php')) ?: [] as $compiled) {
                    @unlink($compiled);
                }

                $migrator->report->action('Cleared the compiled Blade views (`php artisan view:clear`).');
            }

            $reportPath = $migrator->writeReport($adapter);
        } finally {
            $this->laravel->setBasePath($originalBasePath);
        }

        $this->printReport($migrator->report, $reportPath, $dryRun);

        return self::SUCCESS;
    }

    private function summarizeDiscovery(FilamentDiscovery $discovery): void
    {
        $rows = [
            ['Directories', $discovery->directories === [] ? '-' : implode(', ', $discovery->directories)],
            ['PHP files referencing Filament', (string) count($discovery->phpFiles)],
            ['Livewire components', (string) count($discovery->livewireComponents)],
            ['Tests', (string) count($discovery->tests)],
            ['Blade views', (string) count($discovery->bladeViews)],
            ['Theme files', (string) count($discovery->themes)],
            ['config/filament.php', $discovery->configFile === null ? 'no' : 'yes'],
            ['Composer packages', $discovery->allPackages() === [] ? '-' : implode(', ', $discovery->allPackages())],
            ['Frontend', $discovery->hasInertia ? "Inertia ({$discovery->adapter})" : 'no Inertia app detected'],
        ];

        $this->table(['What', 'Found'], $rows);
    }

    /**
     * Run the installer's steps (config, frontend entry, Vite inputs, npm package) without
     * creating a second panel provider. Returns false when there is no Inertia app to install into.
     */
    private function installFrontend(FilamentDiscovery $discovery, MigrationReport $report, string $adapter, bool $dryRun): bool
    {
        if (! $discovery->hasInertia && ! $this->option('adapter')) {
            $this->newLine();
            $this->components->warn('No Inertia app detected (no @inertiajs/* dependency in package.json). The PHP code '.($dryRun ? 'would be' : 'was').' migrated; the frontend needs Inertia:');
            $this->components->bulletList([
                'run: php artisan alfakit:migrate-from-filament --adapter='.$adapter.($this->option('keep-filament') ? ' --keep-filament' : '').' (sets up Inertia, '.ucfirst($adapter).', its Vite plugin, Tailwind CSS and TypeScript in this app)',
                'or set up Inertia yourself first (https://inertiajs.com/server-side-setup), then run the same command',
            ]);
            $report->add(MigrationReport::SECTION_NOTES, 'package.json', null, 'No Inertia app detected', 'Run `alfakit:migrate-from-filament --adapter='.$adapter.'`: it sets up Inertia, '.ucfirst($adapter).', Vite, Tailwind CSS and TypeScript in this app, installs the AlfaKit frontend and removes the Filament packages (or set up Inertia yourself first, then run it).');

            return false;
        }

        $entry = $this->laravel->resourcePath('js/alfakit/app.'.($adapter === 'react' ? 'tsx' : 'ts'));

        if (file_exists($entry)) {
            $this->components->task('AlfaKit frontend already installed ('.str_replace($this->laravel->basePath().'/', '', $entry).')');

            return true;
        }

        // A Filament app is Blade + Livewire: with an explicit --adapter and no Inertia app yet,
        // install the toolchain (Inertia, the framework and its Vite plugin, Tailwind) too.
        $scaffold = ! $discovery->hasInertia;
        $flags = '--no-panel'.($scaffold ? ' --scaffold' : '').($this->option('no-npm') ? ' --no-npm' : '');

        $report->action("Installed the AlfaKit frontend for {$adapter}: `config/alfakit.php`, `resources/js/alfakit/app.*`, `resources/css/alfakit.css`, the Vite inputs".($scaffold ? ', the Inertia and '.$adapter.' toolchain' : '').($this->option('no-npm') ? '' : " and the `@alfakit/{$adapter}` npm package")." (`alfakit:install {$flags}`).");

        if ($dryRun) {
            $this->components->task("Would run alfakit:install --adapter={$adapter} {$flags}");

            return true;
        }

        $this->call('alfakit:install', array_filter([
            '--adapter' => $adapter,
            '--no-panel' => true,
            '--scaffold' => $scaffold,
            '--no-npm' => (bool) $this->option('no-npm'),
            '--no-interaction' => true,
        ]));

        // With --path the publish paths were resolved for the running app at boot; copy the config directly.
        $config = $this->laravel->configPath('alfakit.php');

        if (! file_exists($config)) {
            $files = new Filesystem;
            $files->ensureDirectoryExists(dirname($config));
            $files->copy(dirname(__DIR__, 2).'/config/alfakit.php', $config);
        }

        return true;
    }

    private function removeFilamentPackages(FilamentDiscovery $discovery, MigrationReport $report, bool $dryRun): void
    {
        foreach ([[$discovery->packages, []], [$discovery->devPackages, ['--dev']]] as [$packages, $flags]) {
            if ($packages === []) {
                continue;
            }

            $command = ['composer', 'remove', ...array_keys($packages), ...$flags, '--no-interaction', '--no-scripts'];
            $report->action('`'.implode(' ', $command).'`');

            if ($dryRun) {
                $this->components->task('Would run '.implode(' ', $command));

                continue;
            }

            $this->components->task('Running '.implode(' ', $command));
            $process = new Process($command, $this->laravel->basePath(), timeout: 600);
            $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

            if (! $process->isSuccessful()) {
                $this->components->error('composer remove failed; run it yourself: '.implode(' ', $command));
                $report->add(MigrationReport::SECTION_CONFIG, 'composer.json', null, 'Filament packages still installed', 'Run `'.implode(' ', $command).'`.');

                continue;
            }

            // `--no-scripts` skips `package:discover`: the cached manifest still lists the removed
            // packages' providers, so the app wouldn't boot. Laravel rebuilds both on the next boot.
            foreach (['packages.php', 'services.php'] as $cache) {
                @unlink($this->laravel->basePath("bootstrap/cache/{$cache}"));
            }
        }
    }

    private function printReport(MigrationReport $report, string $reportPath, bool $dryRun): void
    {
        $this->newLine();
        $this->components->info($dryRun ? 'What would be done:' : 'What was done:');

        foreach ($report->actions() as $action) {
            $this->line('  - '.$action);
        }

        if ($report->isEmpty()) {
            $this->newLine();
            $this->components->info('Everything was converted. Review the diff and run your tests.');
        } else {
            foreach ($report->byFile() as $file => $entries) {
                $metrics = $report->fileMetrics($file);
                $this->newLine();
                $this->components->twoColumnDetail("<fg=yellow>{$file}</>", "{$metrics['automatic']} converted, ".($metrics['manual'] > 0 ? "<fg=red>{$metrics['manual']} manual</>" : '0 manual'));

                foreach ($entries as $entry) {
                    $this->line('  '.($entry['manual'] ? '<fg=red>TODO</> ' : '<fg=gray>note</> ').MigrationReport::formatEntry($entry, withFile: false));
                }
            }
        }

        if (($command = $report->pluginCommand()) !== null) {
            $this->newLine();
            $this->components->info('First-party plugins replace these Filament plugins:');

            foreach ($report->suggestedPlugins() as $slug => $reasons) {
                $this->components->twoColumnDetail($slug, implode(' ', array_unique($reasons)));
            }

            $this->line("  Add them: <fg=cyan>{$command}</>");
        }

        $metrics = $report->metrics();
        $this->newLine();
        $this->components->twoColumnDetail('Filament constructs found', (string) $metrics['constructs']);
        $this->components->twoColumnDetail('Converted automatically', "{$metrics['automatic']} ({$metrics['percent']}%)");
        $this->components->twoColumnDetail('Need a manual step', "{$metrics['manual']} (search for ".MigrationReport::TODO_MARKER.", {$metrics['markers']} marker(s))");
        $this->newLine();
        $this->components->info('Next steps:');
        $this->components->bulletList([
            'Build the frontend: <fg=cyan>npm install && npm run build</>',
            ...(($command = $report->pluginCommand()) !== null ? ["Add the first-party plugins: <fg=cyan>{$command}</>"] : []),
            'Run <fg=cyan>php artisan alfakit:optimize-clear</>, then your test suite.',
        ]);
        $this->components->info('Report written to '.$reportPath);
    }
}
