<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Localization\TranslationChecker;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:lang:add')]
class LangAddCommand extends Command
{
    protected $signature = 'alfakit:lang:add
        {locale : Locale code, e.g. sw, pt_BR or zh-Hant}
        {--namespace=alfakit : Translation namespace}
        {--all : Write every string, not only the ones no installed package translates}';

    protected $description = 'Scaffold translation files for a new language in lang/vendor/alfakit';

    public function handle(Filesystem $files): int
    {
        $namespace = (string) $this->option('namespace');
        $locale = Locale::make((string) $this->argument('locale'));
        $code = $locale->getCode();
        $checker = new TranslationChecker(app('translator')->getLoader(), $namespace);
        $source = $checker->source();

        if ($source === []) {
            $this->components->error("No English translations are registered for the [{$namespace}] namespace.");

            return self::FAILURE;
        }

        $existing = $checker->lines($code);
        $keys = $this->option('all')
            ? array_keys($source)
            : array_values(array_filter(array_keys($source), static fn (string $key): bool => trim($existing[$key] ?? '') === ''));

        $directory = $this->laravel->langPath("vendor/{$namespace}/{$code}");

        if ($keys === []) {
            $this->components->info("{$locale->getLabelIn('en')} ({$code}) is already fully translated by the installed packages.");
            $this->printNextSteps($code);

            return self::SUCCESS;
        }

        $byGroup = [];

        foreach ($keys as $key) {
            [$group, $path] = explode('.', $key, 2);
            Arr::set($byGroup[$group], $path, $this->option('all') && isset($existing[$key]) ? $existing[$key] : $source[$key]);
        }

        $files->ensureDirectoryExists($directory);

        foreach ($byGroup as $group => $lines) {
            $file = "{$directory}/{$group}.php";
            $current = is_file($file) ? (array) require $file : [];

            $files->put($file, $this->export(array_replace_recursive($lines, $current), $locale, $group));
        }

        $this->components->info(sprintf(
            'Wrote %d string(s) to %s. They are in English until you translate them.',
            count($keys),
            str_replace($this->laravel->basePath().DIRECTORY_SEPARATOR, '', $directory),
        ));

        $this->printNextSteps($code);

        return self::SUCCESS;
    }

    protected function printNextSteps(string $code): void
    {
        $this->line("  Enable it on your panel: <info>->locales([..., '{$code}'])</info>");
        $this->line("  Track progress with:     <info>php artisan alfakit:lang:check {$code}</info>");
    }

    /**
     * @param  array<mixed>  $lines
     */
    protected function export(array $lines, Locale $locale, string $group): string
    {
        $direction = $locale->getDirection()->value;

        return "<?php\n\ndeclare(strict_types=1);\n\n// {$locale->getLabelIn('en')} ({$locale->getCode()}, {$direction}) — alfakit::{$group}\n"
            ."// Keep :placeholders unchanged. Plurals use Laravel's `one|other` syntax.\n\n"
            .'return '.$this->exportArray($lines, 0).";\n";
    }

    /**
     * @param  array<mixed>  $lines
     */
    protected function exportArray(array $lines, int $depth): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $output = "[\n";

        foreach ($lines as $key => $value) {
            $output .= $indent.var_export((string) $key, true).' => '
                .(is_array($value) ? $this->exportArray($value, $depth + 1) : var_export((string) $value, true))
                .",\n";
        }

        return $output.str_repeat('    ', $depth).']';
    }
}
