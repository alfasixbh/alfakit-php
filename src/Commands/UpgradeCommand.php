<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller;
use AlfaSix\Alfakit\Support\PluginCompatibility;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Run after updating `alfasix/alfakit` (add `@php artisan alfakit:upgrade` to the
 * `post-autoload-dump` scripts of composer.json): clears Alfakit's caches, republishes
 * plugin assets, reports whether installed plugins and the `@alfakit` npm packages are
 * compatible with the new version, and points out what may need attention.
 *
 * The report never blocks the upgrade: the command only fails (exit code 1) when `--strict`
 * is passed and something is incompatible, so CI can enforce it.
 */
#[AsCommand(name: 'alfakit:upgrade', description: 'Clear caches, publish updated assets and show upgrade notes after updating Alfakit')]
class UpgradeCommand extends Command
{
    protected $signature = 'alfakit:upgrade
        {--strict : Fail when an installed plugin or @alfakit npm package is incompatible with the installed version}
        {--migrations : Publish the new migrations of AlfaKit packages whose migrations you published before}';

    public function handle(): int
    {
        $version = InstalledVersions::isInstalled('alfasix/alfakit') ? (string) InstalledVersions::getPrettyVersion('alfasix/alfakit') : 'dev';

        $this->components->info("Upgrading Alfakit ({$version})");

        $this->components->task('Cleared the cached panel components', fn (): bool => $this->callSilently('alfakit:optimize-clear') === self::SUCCESS);
        $this->components->task('Cleared compiled views', fn (): bool => $this->callSilently('view:clear') === self::SUCCESS);
        $this->components->task('Published plugin assets', fn (): bool => $this->callSilently('alfakit:assets') === self::SUCCESS);

        if ($this->option('migrations')) {
            $this->publishNewMigrations();
        }

        $compatibility = $this->compatibility();
        $this->reportCompatibility($compatibility);

        $notes = $this->notes();

        if ($notes !== []) {
            $this->components->bulletList($notes);
        }

        $this->components->info('Done. Rebuild your frontend (npm run build) if you updated the @alfakit npm packages.');

        if ($this->option('strict') && $compatibility->hasIncompatible()) {
            $this->components->error('Incompatible packages were found (--strict).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function compatibility(): PluginCompatibility
    {
        return PluginCompatibility::forApplication();
    }

    /**
     * Plugins declaring `extra.alfakit` and `@alfakit/*` npm packages, each as
     * compatible, incompatible or unknown against the installed PHP package.
     */
    protected function reportCompatibility(PluginCompatibility $compatibility): void
    {
        $plugins = $compatibility->plugins();
        $renderers = $compatibility->renderers();

        if ($plugins === [] && $renderers === []) {
            return;
        }

        $this->line('  <fg=gray>Compatibility with alfasix/alfakit '.($compatibility->getAlfakitVersion() ?? 'unknown').'</>');

        foreach ($plugins as $row) {
            $this->line('  '.$row['package'].($row['version'] === null ? '' : " {$row['version']}").': '.$this->statusLabel($row['status']));
            $this->line("    <fg=gray>{$row['reason']}</>");
        }

        foreach ($renderers as $row) {
            $this->line("  {$row['package']} is {$row['constraint']} in package.json: ".$this->statusLabel($row['status']));
            $this->line("    <fg=gray>{$row['reason']}</>");
        }

        $this->newLine();
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            PluginCompatibility::COMPATIBLE => '<fg=green>compatible</>',
            PluginCompatibility::INCOMPATIBLE => '<fg=red>incompatible</>',
            default => '<fg=yellow>unknown</>',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function notes(): array
    {
        $notes = [];

        if (is_dir(resource_path('views/vendor/alfakit'))) {
            $notes[] = 'You published Alfakit\'s views (resources/views/vendor/alfakit). Compare them with the package\'s new views: php artisan vendor:publish --tag=alfakit-views --force overwrites them.';
        }

        if (is_dir(base_path('stubs/alfakit'))) {
            $notes[] = 'You published Alfakit\'s stubs (stubs/alfakit); new generator options only apply to the package\'s stubs.';
        }

        if (is_dir(lang_path('vendor/alfakit'))) {
            $notes[] = 'You have translation overrides in lang/vendor/alfakit. Run php artisan alfakit:lang:check to find new strings.';
        }

        foreach ($this->starterKitHeadersWithoutLanguageSwitcher() as $header) {
            $notes[] = "Your app header ({$header}) has no <AlfakitLanguageSwitcher /> yet. Run php artisan alfakit:install --starter-kit --no-npm (with the --panel you installed with) to add it; it renders nothing until the panel has two or more locales.";
        }

        if (($menu = $this->starterKitUserMenuWithoutItems()) !== null) {
            $notes[] = "Your app user menu ({$menu}) has no <AlfakitUserMenuItems /> yet, so the panel's userMenuItems() (and plugin entries such as Billing's) don't show there. Run php artisan alfakit:install --starter-kit --no-npm (with the --panel you installed with) to add it.";
        }

        $direction = $this->starterKitFilesWithoutDirection();

        if ($direction !== []) {
            $notes[] = 'Your starter kit app ('.implode(', ', $direction).') does not follow the panel language\'s direction yet, so a right-to-left language (Arabic, Hebrew…) only mirrors AlfaKit\'s content, not the kit\'s sidebar and header. Run php artisan alfakit:install --starter-kit --no-npm (with the --panel you installed with) to add <html dir> and dock the sidebar on the right in right-to-left languages, then rebuild your frontend.';
        }

        $missing = $this->newMigrations();

        if ($missing !== []) {
            $notes[] = 'New migrations: '.implode(', ', array_values($missing)).'. Publish only these with php artisan alfakit:upgrade --migrations, then run php artisan migrate (vendor:publish would copy the migrations you already ran again).';
        }

        if (app()->routesAreCached() || app()->configurationIsCached()) {
            $notes[] = 'Routes or configuration are cached: run php artisan optimize again so new routes and options take effect.';
        }

        return $notes;
    }

    /**
     * Migrations of AlfaKit packages (`alfasix/*`) the app hasn't published yet, for
     * packages with at least one migration published already: what a release added.
     * Matched by name without the date, which `vendor:publish` rewrites.
     *
     * @return array<string, string> source file => migration name
     */
    protected function newMigrations(): array
    {
        $published = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $published[static::migrationName(basename($file))] = true;
        }

        $missing = [];

        foreach (ServiceProvider::publishableMigrationPaths() as $path) {
            $real = realpath($path);

            if ($real === false || ! str_contains(str_replace('\\', '/', $real), 'alfakit')) {
                continue;
            }

            $names = [];

            foreach (glob($real.'/*.php') ?: [] as $file) {
                $names[$file] = static::migrationName(basename($file));
            }

            $unpublished = array_filter($names, static fn (string $name): bool => ! isset($published[$name]));

            // A package none of whose migrations are published isn't set up: leave it.
            if ($unpublished !== [] && count($unpublished) < count($names)) {
                $missing = [...$missing, ...$unpublished];
            }
        }

        ksort($missing);

        return $missing;
    }

    protected function publishNewMigrations(): void
    {
        $missing = $this->newMigrations();

        if ($missing === []) {
            $this->components->task('No new migrations to publish');

            return;
        }

        $time = now();

        foreach ($missing as $source => $name) {
            $time = $time->addSecond();
            $target = database_path('migrations/'.$time->format('Y_m_d_His').'_'.$name);

            $this->components->task("Published {$name}", static fn (): bool => @copy($source, $target));
        }

        $this->components->info('Run php artisan migrate to create the new tables.');
    }

    protected static function migrationName(string $file): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $file);
    }

    /**
     * Starter kit headers the installer set up (its `alfakit:header` block) before
     * `<AlfakitLanguageSwitcher />` existed: re-running the installer adds it.
     *
     * @return list<string>
     */
    protected function starterKitHeadersWithoutLanguageSwitcher(): array
    {
        $kit = StarterKitInstaller::detect(base_path());

        if ($kit === null) {
            return [];
        }

        $files = StarterKitInstaller::files($kit);
        $headers = [];

        foreach ([$files['sidebarHeader'], $files['header']] as $relative) {
            $contents = is_file(base_path($relative)) ? (string) file_get_contents(base_path($relative)) : '';

            if (str_contains($contents, StarterKitInstaller::HEADER_MARKER) && ! str_contains($contents, '<AlfakitLanguageSwitcher')) {
                $headers[] = $relative;
            }
        }

        return $headers;
    }

    /**
     * The kit's user menu file when the installer set up the app (its `alfakit:nav` block)
     * before `<AlfakitUserMenuItems />` existed: re-running the installer adds it.
     */
    protected function starterKitUserMenuWithoutItems(): ?string
    {
        $kit = StarterKitInstaller::detect(base_path());

        if ($kit === null) {
            return null;
        }

        $files = StarterKitInstaller::files($kit);
        $sidebar = is_file(base_path($files['sidebar'])) ? (string) file_get_contents(base_path($files['sidebar'])) : '';
        $menu = is_file(base_path($files['userMenu'])) ? (string) file_get_contents(base_path($files['userMenu'])) : null;

        return str_contains($sidebar, StarterKitInstaller::MARKER) && $menu !== null && ! str_contains($menu, StarterKitInstaller::USER_MENU_MARKER)
            ? $files['userMenu']
            : null;
    }

    /**
     * The root view and app sidebar of a starter kit app the installer set up (its
     * `alfakit:nav` block) before the document followed the panel language's direction
     * (`<html dir>` from `StarterKitDocument::direction()`, the sidebar's `side` from
     * `useAlfakitDirection()`): re-running the installer adds them.
     *
     * @return list<string>
     */
    protected function starterKitFilesWithoutDirection(): array
    {
        $kit = StarterKitInstaller::detect(base_path());

        if ($kit === null) {
            return [];
        }

        $files = StarterKitInstaller::files($kit);
        $sidebar = is_file(base_path($files['sidebar'])) ? (string) file_get_contents(base_path($files['sidebar'])) : '';

        if (! str_contains($sidebar, StarterKitInstaller::MARKER)) {
            return [];
        }

        $missing = [];
        $view = 'resources/views/app.blade.php';

        if (is_file(base_path($view)) && ! str_contains((string) file_get_contents(base_path($view)), 'StarterKitDocument::direction(')) {
            $missing[] = $view;
        }

        if (! str_contains($sidebar, 'useAlfakitDirection')) {
            $missing[] = $files['sidebar'];
        }

        return $missing;
    }
}
