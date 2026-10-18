<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Icons\IconCatalog;
use AlfaSix\Alfakit\Support\Icons\IconifySet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Add or remove Iconify icon sets (`@iconify-json/{set}`) and register them in
 * `config/alfakit.php`, or search the registered icon sets from the terminal.
 *
 *   php artisan alfakit:icons mdi tabler
 *   php artisan alfakit:icons mdi --remove
 *   php artisan alfakit:icons --search=home
 */
class IconsCommand extends Command
{
    protected $signature = 'alfakit:icons
        {sets?* : Iconify set prefixes (mdi, tabler, ph…)}
        {--remove : Uninstall the sets and unregister them}
        {--no-install : Only update config/alfakit.php, without running the package manager}
        {--search= : List the icons matching this term in the registered sets}
        {--set=* : With --search, only search these sets}
        {--limit=50 : With --search, how many icons to list}';

    protected $description = 'Add, remove or search Iconify icon sets';

    public function handle(): int
    {
        $search = $this->option('search');

        if (is_string($search) && $search !== '') {
            return $this->search($search);
        }

        /** @var list<string> $sets */
        $sets = array_values(array_unique(array_map(static fn (mixed $set): string => strtolower(trim((string) $set)), (array) $this->argument('sets'))));

        if ($sets === []) {
            return $this->list();
        }

        foreach ($sets as $set) {
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $set) !== 1) {
                $this->components->error("[{$set}] is not an Iconify set prefix (letters, numbers and dashes).");

                return self::FAILURE;
            }
        }

        $remove = (bool) $this->option('remove');
        $packages = array_map(static fn (string $set): string => "@iconify-json/{$set}", $sets);

        if (! $this->option('no-install')) {
            $manager = MakeThemeCommand::detectPackageManager();
            $command = $remove ? self::removeCommand($manager, $packages) : AppEnvironment::npmInstallCommand($packages);
            $question = ($remove ? 'Remove ' : 'Install ').implode(', ', $packages).' with '.$manager.'?';

            if (! $this->confirm($question, true)) {
                $this->components->warn('Nothing changed.');

                return self::SUCCESS;
            }

            $result = Process::path(base_path())->timeout(900)->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

            if ($result->failed()) {
                $this->components->error(implode(' ', $command).' failed; fix the error above and run it again.');

                return self::FAILURE;
            }
        }

        if (! $this->updateConfig($sets, $remove)) {
            return self::FAILURE;
        }

        $this->components->info(($remove ? 'Unregistered ' : 'Registered ').implode(', ', $sets).' in config/alfakit.php.');

        if (! $remove) {
            $this->components->bulletList(array_map(static fn (string $set): string => "Use \"{$set}-{name}\" anywhere an icon is accepted, e.g. ->icon('{$set}-home').", $sets));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    public static function removeCommand(string $manager, array $packages): array
    {
        return match ($manager) {
            'npm' => ['npm', 'uninstall', ...$packages],
            default => [$manager, 'remove', ...$packages],
        };
    }

    private function search(string $search): int
    {
        $sets = array_values(array_filter(array_map(strval(...), (array) $this->option('set'))));
        $sets = $sets === [] ? IconCatalog::defaultSets() : array_values(array_filter($sets, IconCatalog::isListable(...)));
        $icons = [];

        foreach ($sets as $set) {
            foreach (IconCatalog::search(IconCatalog::icons($set), $search, $set) as $value) {
                $icons[] = [$value, $set];
            }
        }

        $total = count($icons);
        $limit = max(1, (int) $this->option('limit'));

        if ($total === 0) {
            $this->components->warn("No icons match [{$search}] in ".implode(', ', $sets).'.');

            return self::SUCCESS;
        }

        $this->table(['Icon', 'Set'], array_slice($icons, 0, $limit));

        if ($total > $limit) {
            $this->components->info("Showing {$limit} of {$total} icons; use --limit to see more.");
        }

        return self::SUCCESS;
    }

    private function list(): int
    {
        $rows = [['lucide', 'Lucide (built in)', count(IconCatalog::lucide())]];

        foreach (Icons::getSets() as $prefix => $source) {
            $rows[] = match (true) {
                $source instanceof IconifySet => [$prefix, $source->exists() ? $source->label() : "missing: {$source->path}", count($source->names())],
                is_string($source) => [$prefix, $source, count(IconCatalog::icons($prefix))],
                default => [$prefix, 'closure', '-'],
            };
        }

        $this->table(['Prefix', 'Source', 'Icons'], $rows);
        $this->line('  Add a set with <comment>php artisan alfakit:icons mdi</comment>; browse sets at https://icon-sets.iconify.design');

        return self::SUCCESS;
    }

    /**
     * Add or remove the sets in `config/alfakit.php` (`icons.iconify`), publishing the
     * config first when the app has none.
     *
     * @param  list<string>  $sets
     */
    private function updateConfig(array $sets, bool $remove): bool
    {
        $path = config_path('alfakit.php');

        if (! is_file($path)) {
            $this->callSilently('vendor:publish', ['--tag' => 'alfakit-config']);
        }

        if (! is_file($path)) {
            $this->components->error('Publish config/alfakit.php first: php artisan vendor:publish --tag=alfakit-config');

            return false;
        }

        $current = config('alfakit.icons.iconify', []);
        $current = is_array($current) ? $current : [];
        $entries = [];

        foreach ($current as $key => $value) {
            [$set, $file] = is_string($key) ? [$key, $value] : [(string) $value, null];

            if (! in_array($set, $sets, true) || ! $remove) {
                $entries[$set] = is_string($file) ? $file : null;
            }
        }

        if (! $remove) {
            foreach ($sets as $set) {
                $entries[$set] ??= null;
            }
        }

        $export = self::export($entries);
        $contents = (string) file_get_contents($path);
        $pattern = '/([\'"])iconify\1\s*=>\s*\[[^\[\]]*\]/';

        if (preg_match($pattern, $contents) === 1) {
            $contents = (string) preg_replace_callback($pattern, static fn (): string => "'iconify' => {$export}", $contents, 1);
        } elseif (preg_match('/([\'"])icons\1\s*=>\s*\[/', $contents) === 1) {
            $contents = (string) preg_replace_callback('/([\'"])icons\1\s*=>\s*\[/', static fn (array $match): string => $match[0]."\n        'iconify' => {$export},", $contents, 1);
        } else {
            $block = "    'icons' => [\n        'iconify' => {$export},\n    ],\n\n";
            $position = strrpos($contents, '];');

            if ($position === false) {
                $this->components->error('Could not update config/alfakit.php; add the sets to icons.iconify yourself.');

                return false;
            }

            $contents = substr($contents, 0, $position).$block.substr($contents, $position);
        }

        file_put_contents($path, $contents);

        $value = [];

        foreach ($entries as $set => $file) {
            if ($file === null) {
                $value[] = $set;
            } else {
                $value[$set] = $file;
            }
        }

        config(['alfakit.icons.iconify' => $value]);

        foreach ($sets as $set) {
            if ($remove) {
                Icons::forgetSet($set);
            } else {
                Icons::iconify($entries[$set] === null ? $set : [$set => $entries[$set]]);
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|null>  $entries
     */
    private static function export(array $entries): string
    {
        $items = [];

        foreach ($entries as $set => $file) {
            $items[] = $file === null ? var_export($set, true) : var_export($set, true).' => '.var_export($file, true);
        }

        return '['.implode(', ', $items).']';
    }
}
