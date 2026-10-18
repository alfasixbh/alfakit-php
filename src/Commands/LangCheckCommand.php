<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Localization\TranslationChecker;
use AlfaSix\Alfakit\Localization\Translations;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:lang:check')]
class LangCheckCommand extends Command
{
    protected $signature = 'alfakit:lang:check
        {locales?* : Locales to check (default: every locale with translation files)}
        {--namespace=alfakit : Translation namespace}
        {--strict : Also fail on keys that no longer exist in English}';

    protected $description = 'Report missing translations and broken :placeholders for Alfakit (or a plugin namespace)';

    public function handle(): int
    {
        $namespace = (string) $this->option('namespace');
        $checker = new TranslationChecker(app('translator')->getLoader(), $namespace);

        if (Translations::groups($namespace) === []) {
            $this->components->error("No English translations are registered for the [{$namespace}] namespace.");

            return self::FAILURE;
        }

        /** @var array<int, string> $requested */
        $requested = (array) $this->argument('locales');
        $locales = $requested !== []
            ? array_map(Locale::normalize(...), $requested)
            : array_values(array_diff(Translations::availableLocales($namespace, $this->laravel->langPath("vendor/{$namespace}")), ['en']));

        if ($locales === []) {
            $this->components->info('Only English is available. Add a language with `php artisan alfakit:lang:add {locale}`.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($locales as $locale) {
            $report = $checker->check($locale);
            $translated = $report['total'] - count($report['missing']);
            $hasProblems = $report['missing'] !== [] || $report['placeholders'] !== [] || ($this->option('strict') && $report['extra'] !== []);
            $failed = $failed || $hasProblems;

            $rows[] = [
                $locale,
                Locale::make($locale)->getLabelIn('en'),
                sprintf('%d%%', $report['total'] === 0 ? 100 : (int) floor($translated / $report['total'] * 100)),
                count($report['missing']),
                count($report['placeholders']),
                count($report['extra']),
            ];

            if ($this->output->isVerbose()) {
                foreach (['missing' => 'Missing', 'placeholders' => 'Placeholder mismatch', 'extra' => 'Not in English'] as $type => $label) {
                    foreach ($report[$type] as $key) {
                        $this->line("  <comment>{$locale}</comment> {$label}: {$key}");
                    }
                }
            }
        }

        $this->table(['Locale', 'Language', 'Translated', 'Missing', 'Placeholders', 'Obsolete'], $rows);

        if ($failed && ! $this->output->isVerbose()) {
            $this->line('Run with <info>-v</info> to list the keys.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
