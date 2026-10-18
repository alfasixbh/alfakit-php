<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Support\PluginCompatibility;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
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
    protected $signature = 'alfakit:upgrade {--strict : Fail when an installed plugin or @alfakit npm package is incompatible with the installed version}';

    public function handle(): int
    {
        $version = InstalledVersions::isInstalled('alfasix/alfakit') ? (string) InstalledVersions::getPrettyVersion('alfasix/alfakit') : 'dev';

        $this->components->info("Upgrading Alfakit ({$version})");

        $this->components->task('Cleared the cached panel components', fn (): bool => $this->callSilently('alfakit:optimize-clear') === self::SUCCESS);
        $this->components->task('Cleared compiled views', fn (): bool => $this->callSilently('view:clear') === self::SUCCESS);
        $this->components->task('Published plugin assets', fn (): bool => $this->callSilently('alfakit:assets') === self::SUCCESS);

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

        if (app()->routesAreCached() || app()->configurationIsCached()) {
            $notes[] = 'Routes or configuration are cached: run php artisan optimize again so new routes and options take effect.';
        }

        return $notes;
    }
}
