<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration;

/**
 * Everything `alfakit:migrate-from-filament` changed and could not convert, as
 * `file:line — what — suggested change` entries. Each entry is either **manual** (needs a
 * decision; the file gets a `TODO(alfakit-migration)` marker) or a **note** (converted, worth
 * knowing). Alongside the entries the report counts the Filament constructs found per file
 * (class references, method calls, render hooks, views, themes, config keys, plugins), so the
 * share converted without a TODO can be measured, and collects the first-party plugins to add
 * with `php artisan alfakit:add`. Printed to the console and written to
 * `alfakit-migration-report.md`, both by section and grouped by file.
 */
final class MigrationReport
{
    public const SECTION_CLASSES = 'Classes without an AlfaKit equivalent';

    public const SECTION_METHODS = 'Methods removed or changed';

    public const SECTION_VIEWS = 'Blade views, Livewire components and Alpine';

    public const SECTION_PANEL = 'Panel configuration';

    public const SECTION_PLUGINS = 'Plugins';

    public const SECTION_THEMES = 'Themes';

    public const SECTION_CONFIG = 'Configuration and scripts';

    public const SECTION_TESTS = 'Tests';

    public const SECTION_ICONS = 'Icons to check';

    public const SECTION_NOTES = 'Notes';

    /** The marker written into migrated files next to what needs a manual decision. */
    public const TODO_MARKER = 'TODO(alfakit-migration)';

    /** @var array<string, array<int, array{file: string, line: int|null, what: string, suggestion: string, manual: bool}>> */
    private array $entries = [];

    /** @var array<int, string> */
    private array $actions = [];

    /** @var array<string, int> file → constructs converted without a report entry (class references, …) */
    private array $converted = [];

    /** @var array<string, int> file → TODO markers written */
    private array $markers = [];

    /** @var array<string, array<int, string>> plugin slug → why it is suggested */
    private array $plugins = [];

    public function __construct(private readonly string $basePath) {}

    /**
     * Add an entry. `$file` may be absolute (made relative to the app root) or already relative.
     * `$manual = false` records something that was converted but is worth knowing; null
     * classifies the entry from its wording ({@see isManual()}).
     */
    public function add(string $section, string $file, ?int $line, string $what, string $suggestion, ?bool $manual = null): void
    {
        $file = $this->relative($file);
        $manual ??= self::isManual($section, $what, $suggestion);

        foreach ($this->entries[$section] ?? [] as $existing) {
            if ($existing['file'] === $file && $existing['line'] === $line && $existing['what'] === $what) {
                return;
            }
        }

        $this->entries[$section][] = ['file' => $file, 'line' => $line, 'what' => $what, 'suggestion' => $suggestion, 'manual' => $manual];
    }

    /**
     * Whether an entry needs a manual step. Conversions (`… became …`), icons to double-check,
     * harmless removals and config keys AlfaKit has an equivalent for are notes.
     */
    public static function isManual(string $section, string $what, string $suggestion): bool
    {
        if ($section === self::SECTION_ICONS || str_contains($what, ' became ') || $what === 'Panel middleware stack') {
            return false;
        }

        if (preg_match('/^`[^`]+` removed from the panel$/', $what) === 1) {
            return false;
        }

        if (preg_match('/^`->(\w+)\(\)`$/', $what, $matches) === 1 && in_array($matches[1], FilamentClassMap::harmlessRemovedMethods(), true)) {
            return false;
        }

        if ($section === self::SECTION_CONFIG && str_starts_with($suggestion, 'AlfaKit: ')) {
            return false;
        }

        return true;
    }

    /**
     * Record something converted that is worth reading (`->lazy()` became `->live(onBlur: true)`).
     */
    public function note(string $section, string $file, ?int $line, string $what, string $suggestion = ''): void
    {
        $this->add($section, $file, $line, $what, $suggestion, false);
    }

    /**
     * Count constructs in `$file` that were converted silently (class references, icons, …).
     */
    public function converted(string $file, int $count = 1): void
    {
        if ($count > 0) {
            $file = $this->relative($file);
            $this->converted[$file] = ($this->converted[$file] ?? 0) + $count;
        }
    }

    /**
     * Constructs counted as converted in `$file` so far.
     */
    public function convertedIn(string $file): int
    {
        return $this->converted[$this->relative($file)] ?? 0;
    }

    /**
     * Record `$count` TODO markers written into `$file`.
     */
    public function markers(string $file, int $count): void
    {
        if ($count > 0) {
            $file = $this->relative($file);
            $this->markers[$file] = ($this->markers[$file] ?? 0) + $count;
        }
    }

    /**
     * Suggest a first-party plugin (`php artisan alfakit:add <slug>`).
     */
    public function suggestPlugin(string $slug, string $reason): void
    {
        if (! in_array($reason, $this->plugins[$slug] ?? [], true)) {
            $this->plugins[$slug][] = $reason;
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function suggestedPlugins(): array
    {
        ksort($this->plugins);

        return $this->plugins;
    }

    /**
     * Record something the command did (or would do with --dry-run).
     */
    public function action(string $description): void
    {
        $this->actions[] = $description;
    }

    /**
     * @return array<int, string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return array<string, array<int, array{file: string, line: int|null, what: string, suggestion: string, manual: bool}>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<int, array{file: string, line: int|null, what: string, suggestion: string, manual: bool}>
     */
    public function section(string $section): array
    {
        return $this->entries[$section] ?? [];
    }

    /**
     * Entries grouped by file (sorted), each with its section.
     *
     * @return array<string, array<int, array{file: string, line: int|null, what: string, suggestion: string, manual: bool, section: string}>>
     */
    public function byFile(): array
    {
        $files = [];

        foreach ($this->entries as $section => $entries) {
            foreach ($entries as $entry) {
                $files[$entry['file']][] = [...$entry, 'section' => $section];
            }
        }

        foreach (array_keys($this->converted) as $file) {
            $files[$file] ??= [];
        }

        foreach ($files as &$entries) {
            usort($entries, static fn (array $a, array $b): int => [$b['manual'], $a['line'] ?? 0] <=> [$a['manual'], $b['line'] ?? 0]);
        }

        unset($entries);
        ksort($files);

        return $files;
    }

    public function count(): int
    {
        return array_sum(array_map(count(...), $this->entries));
    }

    public function manualCount(): int
    {
        $count = 0;

        foreach ($this->entries as $entries) {
            foreach ($entries as $entry) {
                $count += $entry['manual'] ? 1 : 0;
            }
        }

        return $count;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * How much was migrated without a manual step. A construct is a Filament class reference,
     * fluent method, render hook, view, theme, config key, composer script or plugin the
     * migrator found; it is manual when it left a TODO (a manual report entry).
     *
     * @return array{constructs: int, automatic: int, manual: int, percent: float, files: int, markers: int}
     */
    public function metrics(): array
    {
        $manual = $this->manualCount();
        $notes = $this->count() - $manual;
        $constructs = array_sum($this->converted) + $notes + $manual;
        $automatic = $constructs - $manual;

        return [
            'constructs' => $constructs,
            'automatic' => $automatic,
            'manual' => $manual,
            'percent' => $constructs === 0 ? 100.0 : round($automatic / $constructs * 100, 1),
            'files' => count($this->byFile()),
            'markers' => array_sum($this->markers),
        ];
    }

    /**
     * Per-file counts: constructs, converted automatically, manual.
     *
     * @return array{constructs: int, automatic: int, manual: int}
     */
    public function fileMetrics(string $file): array
    {
        $file = $this->relative($file);
        $manual = 0;
        $notes = 0;

        foreach ($this->entries as $entries) {
            foreach ($entries as $entry) {
                if ($entry['file'] === $file) {
                    $entry['manual'] ? $manual++ : $notes++;
                }
            }
        }

        $constructs = ($this->converted[$file] ?? 0) + $notes + $manual;

        return ['constructs' => $constructs, 'automatic' => $constructs - $manual, 'manual' => $manual];
    }

    /**
     * Merge entries written by the Rector rules (one JSON object per line).
     */
    public function mergeJsonLines(string $contents): void
    {
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $entry = json_decode($line, true);

            if (! is_array($entry) || ! isset($entry['section'], $entry['file'], $entry['what'])) {
                continue;
            }

            $this->add((string) $entry['section'], (string) $entry['file'], isset($entry['line']) ? (int) $entry['line'] : null, (string) $entry['what'], (string) ($entry['suggestion'] ?? ''), (bool) ($entry['manual'] ?? true));
        }
    }

    /**
     * Encode one entry for the Rector rules' report file.
     *
     * @param  array{section: string, file: string, line: int|null, what: string, suggestion: string, manual?: bool}  $entry
     */
    public static function jsonLine(array $entry): string
    {
        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    /**
     * The `php artisan alfakit:add …` command for every suggested plugin, or null.
     */
    public function pluginCommand(): ?string
    {
        return $this->plugins === [] ? null : 'php artisan alfakit:add '.implode(' ', array_keys($this->suggestedPlugins()));
    }

    public function toMarkdown(string $version, bool $dryRun, string $adapter): string
    {
        $metrics = $this->metrics();
        $lines = [
            '# Filament → AlfaKit migration report',
            '',
            sprintf('Generated by `php artisan alfakit:migrate-from-filament` on %s%s. Detected Filament %s; frontend adapter: %s.', date('Y-m-d H:i'), $dryRun ? ' (dry run: nothing was changed)' : '', $version, $adapter),
            '',
            '## Summary',
            '',
            '| | |',
            '| --- | --- |',
            sprintf('| Filament constructs found | %d (class references, method calls, render hooks, views, themes, config keys, scripts, plugins) |', $metrics['constructs']),
            sprintf('| Converted automatically | %d (%s%%) |', $metrics['automatic'], self::percent($metrics['percent'])),
            sprintf('| Need a manual step | %d, marked `%s` in %d place(s) in the code |', $metrics['manual'], self::TODO_MARKER, $metrics['markers']),
            sprintf('| Files | %d |', $metrics['files']),
            '',
            sprintf('Search the project for `%s` to find every place that needs a decision.', self::TODO_MARKER),
            '',
        ];

        if ($this->actions !== []) {
            $lines[] = $dryRun ? '## What would be done' : '## What was done';
            $lines[] = '';

            foreach ($this->actions as $action) {
                $lines[] = "- {$action}";
            }

            $lines[] = '';
        }

        if ($this->plugins !== []) {
            $lines[] = '## First-party plugins to add';
            $lines[] = '';
            $lines[] = 'These Filament plugins have an AlfaKit plugin. Add them (composer, panel registration, migrations) with:';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = (string) $this->pluginCommand();
            $lines[] = '```';
            $lines[] = '';

            foreach ($this->suggestedPlugins() as $slug => $reasons) {
                $lines[] = "- `{$slug}` (alfasix/alfakit-{$slug}): ".implode(' ', array_unique($reasons));
            }

            $lines[] = '';
        }

        if ($this->entries === [] && $this->converted === []) {
            $lines[] = 'Everything was converted. Review the diff, run your tests and `php artisan alfakit:optimize-clear`.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        $lines[] = '## By file';
        $lines[] = '';
        $lines[] = 'Each file lists what needs a manual step (**TODO**) first, then what was converted and is worth checking (note).';
        $lines[] = '';

        foreach ($this->byFile() as $file => $entries) {
            $fileMetrics = $this->fileMetrics($file);
            $lines[] = sprintf('### `%s`', $file);
            $lines[] = '';
            $lines[] = sprintf('%d construct(s): %d converted, %d manual.', $fileMetrics['constructs'], $fileMetrics['automatic'], $fileMetrics['manual']);
            $lines[] = '';

            foreach ($entries as $entry) {
                $lines[] = '- '.($entry['manual'] ? '**TODO** ' : 'note: ').self::formatEntry($entry, withFile: false);
            }

            $lines[] = '';
        }

        $lines[] = '## By topic';
        $lines[] = '';
        $lines[] = sprintf('%d item(s), %d of them manual. Each line is `file:line — what — suggested change`.', $this->count(), $this->manualCount());
        $lines[] = '';

        foreach ($this->entries as $section => $entries) {
            $lines[] = "### {$section}";
            $lines[] = '';

            foreach ($entries as $entry) {
                $lines[] = '- '.($entry['manual'] ? '**TODO** ' : '').self::formatEntry($entry);
            }

            $lines[] = '';
        }

        $lines[] = '## After the migration';
        $lines[] = '';
        $lines[] = '1. Build the frontend: `npm install && npm run build` (the renderer package was added by `alfakit:install`).';
        $step = 2;

        if ($this->plugins !== []) {
            $lines[] = $step++.'. Add the first-party plugins: `'.$this->pluginCommand().'`.';
        }

        $lines[] = $step++.'. Port the scaffolded custom components (`resources/js/alfakit/components/`) and replace custom Blade views with frontend components (`make:alfakit-field|column|entry|widget`, `View::make()`, or `alfakit:eject`).';
        $lines[] = $step++.'. Recreate themes with `php artisan make:alfakit-theme <name>` and register them with `->theme()`.';
        $lines[] = $step++.'. Rewrite Livewire tests with `alfakit()->testPage(...)` (docs: advanced/testing).';
        $lines[] = $step.'. Run `php artisan alfakit:optimize-clear`, then your test suite.';
        $lines[] = '';
        $lines[] = 'Docs: '.config('alfakit.docs_url', 'https://alfakit.dev/docs').'/getting-started/migrating-from-filament';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array{file: string, line: int|null, what: string, suggestion: string, manual?: bool}  $entry
     */
    public static function formatEntry(array $entry, bool $withFile = true): string
    {
        $location = $withFile ? '`'.$entry['file'].($entry['line'] !== null ? ':'.$entry['line'] : '').'` — ' : ($entry['line'] !== null ? 'line '.$entry['line'].' — ' : '');

        return $location.$entry['what'].($entry['suggestion'] !== '' ? " — {$entry['suggestion']}" : '');
    }

    /**
     * The one-line text of a TODO marker for an entry (no Filament class names, so a second run
     * does not find the file again).
     *
     * @param  array{what: string, suggestion: string}  $entry
     */
    public static function todoText(array $entry): string
    {
        $text = $entry['what'].($entry['suggestion'] !== '' ? ' — '.$entry['suggestion'] : '');
        $text = (string) preg_replace_callback('/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)+/', static function (array $matches): string {
            $name = ltrim($matches[0], '\\');

            return FilamentClassMap::isFilament($name) || str_contains($name, 'Filament') ? (string) substr((string) strrchr('\\'.$name, '\\'), 1) : $matches[0];
        }, $text);
        $text = str_replace(['filament:', '*/', "\r", "\n"], ['filament :', '* /', ' ', ' '], $text);

        return self::TODO_MARKER.': '.trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function relative(string $file): string
    {
        foreach (array_unique([rtrim($this->basePath, '/'), rtrim((string) realpath($this->basePath), '/')]) as $base) {
            if ($base !== '' && str_starts_with($file, $base.'/')) {
                return substr($file, strlen($base) + 1);
            }
        }

        return $file;
    }
}
