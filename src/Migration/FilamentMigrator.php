<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration;

use Closure;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The file-level part of `alfakit:migrate-from-filament`: backs up (without git), moves
 * `app/Filament` → `app/Alfakit` and `app/Providers/Filament` → `app/Providers/Alfakit`,
 * rewrites every PHP file that references Filament (Rector when the app has it, then the
 * token rewriter for whatever is left), registers the panel provider, converts
 * `config/filament.php` and `filament:*` composer scripts, and reports Blade views,
 * Livewire components, themes, plugins and tests. With `$dryRun` nothing is written except
 * what the caller decides to do with the report.
 */
final class FilamentMigrator
{
    public const REPORT_FILE = 'alfakit-migration-report.md';

    public const BACKUP_DIRECTORY = 'storage/alfakit-migration-backup-';

    public const DIRECTORY_MOVES = [
        'app/Filament' => 'app/Alfakit',
        'app/Providers/Filament' => 'app/Providers/Alfakit',
    ];

    public readonly MigrationReport $report;

    public ?FilamentDiscovery $discovery = null;

    public ?string $backupPath = null;

    /** @var array<int, string> */
    public array $rewrittenFiles = [];

    /** @var array<int, string> provider classes registered in bootstrap/providers.php */
    public array $providers = [];

    private ?Closure $logger = null;

    private string $engine = 'built-in rewriter';

    private bool $changed = false;

    private bool $backedUp = false;

    private string $adapter = 'react';

    /** @var array<int, string> scaffolded custom components (relative paths) */
    public array $scaffolded = [];

    /** @var array<string, string> */
    private array $moved = [];

    public function __construct(
        private readonly string $basePath,
        private readonly Filesystem $files,
        private readonly bool $dryRun = false,
        private readonly bool $force = false,
        private readonly ?string $rectorBinary = null,
        private readonly bool $useRector = true,
    ) {
        $this->report = new MigrationReport($this->basePath);
    }

    /**
     * @param  Closure(string): void  $logger
     */
    public function onLog(Closure $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * The renderer the Livewire scaffolds are written for (`react`, `vue`, `svelte`).
     */
    public function adapter(string $adapter): static
    {
        $this->adapter = in_array($adapter, ['react', 'vue', 'svelte'], true) ? $adapter : 'react';

        return $this;
    }

    public function engine(): string
    {
        return $this->engine;
    }

    public function discover(): FilamentDiscovery
    {
        return $this->discovery ??= FilamentDiscovery::scan($this->basePath, $this->files);
    }

    /**
     * Refuse to touch a dirty git working tree (unless forced). Returns the problem, or null.
     */
    public function checkWorkingTree(): ?string
    {
        if (! $this->discover()->hasGit || $this->force || $this->dryRun) {
            return null;
        }

        $process = new Process(['git', 'status', '--porcelain'], $this->basePath);
        $process->run();

        if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
            return 'The git working tree has uncommitted changes. Commit or stash them first so the migration is a single reviewable diff, or pass --force.';
        }

        return null;
    }

    /**
     * Run every file-level step. Returns false when there was nothing to do.
     */
    public function run(): bool
    {
        $discovery = $this->discover();

        if ($discovery->isEmpty()) {
            return false;
        }

        $this->moveDirectories();
        $this->rewritePhp();
        $this->scaffoldLivewireComponents();
        $this->registerProviders();
        $this->convertConfig();
        $this->convertComposerScripts();
        $this->reportNonConvertible();
        $this->markNonPhpFiles();

        return $this->changed;
    }

    public function writeReport(string $adapter): string
    {
        $path = $this->basePath.'/'.self::REPORT_FILE;
        $this->files->put($path, $this->report->toMarkdown($this->discover()->version, $this->dryRun, $adapter));

        return $path;
    }

    // ----------------------------------------------------------------------------------

    /**
     * Without git there is no undo: copy everything the migration touches to storage first.
     */
    private function backUp(): void
    {
        $discovery = $this->discover();
        $this->changed = true;

        if ($discovery->hasGit || $this->backedUp) {
            return;
        }

        $this->backedUp = true;

        $this->backupPath = $this->basePath.'/'.self::BACKUP_DIRECTORY.date('Ymd-His');
        $this->log("No git repository: backing up the touched files to {$this->relative($this->backupPath)}");
        $this->report->action("Backed up the touched files to `{$this->relative($this->backupPath)}` (no git repository).");

        if ($this->dryRun) {
            return;
        }

        $paths = [...$discovery->directories, ...array_map($this->relative(...), [...$discovery->phpFiles, ...$discovery->livewireComponents, ...$discovery->livewireClasses, ...$discovery->tests, ...array_column($discovery->bladeViews, 'file'), ...$discovery->themes])];

        foreach (['config/filament.php', 'composer.json', 'bootstrap/providers.php'] as $file) {
            if ($this->files->exists($this->basePath.'/'.$file)) {
                $paths[] = $file;
            }
        }

        foreach (array_unique($paths) as $relative) {
            $source = $this->basePath.'/'.$relative;
            $target = $this->backupPath.'/'.$relative;

            if ($this->files->isDirectory($source)) {
                $this->files->copyDirectory($source, $target);
            } elseif ($this->files->exists($source)) {
                $this->files->ensureDirectoryExists(dirname($target));
                $this->files->copy($source, $target);
            }
        }
    }

    private function moveDirectories(): void
    {
        $discovery = $this->discover();

        foreach (self::DIRECTORY_MOVES as $from => $to) {
            if (! in_array($from, $discovery->directories, true)) {
                continue;
            }

            $this->backUp();
            $this->log("Moving {$from} → {$to}");
            $this->report->action("Moved `{$from}` to `{$to}` (namespace `".str_replace('/', '\\', ucfirst($from)).'` → `'.str_replace('/', '\\', ucfirst($to)).'`).');

            $source = $this->basePath.'/'.$from;
            $target = $this->basePath.'/'.$to;

            foreach ($this->files->allFiles($source, true) as $file) {
                $relative = $file->getRelativePathname();
                $destination = $target.'/'.$relative;

                if ($this->files->exists($destination)) {
                    $this->report->add(MigrationReport::SECTION_NOTES, $to.'/'.$relative, null, 'Already exists; the Filament version was left at '.$from.'/'.$relative, 'Merge the two files by hand and delete the Filament one.');

                    continue;
                }

                $this->remap($file->getPathname(), $destination);

                if ($this->dryRun) {
                    continue;
                }

                $this->files->ensureDirectoryExists(dirname($destination));
                $this->files->move($file->getPathname(), $destination);
            }

            if (! $this->dryRun && $this->files->allFiles($source, true) === []) {
                $this->files->deleteDirectory($source);
            }
        }
    }

    /**
     * Keep the discovery's file lists pointing at the moved files.
     */
    private function remap(string $from, string $to): void
    {
        $discovery = $this->discover();

        foreach (['phpFiles', 'livewireComponents', 'tests'] as $list) {
            foreach ($discovery->{$list} as $index => $file) {
                if ($file === $from) {
                    $discovery->{$list}[$index] = $to;
                }
            }
        }

        $this->moved[$from] = $to;
    }

    /**
     * Where a file ended up after the directory moves (for reading in dry runs).
     */
    private function sourceOf(string $file): string
    {
        $reverse = array_flip($this->moved);

        return $this->dryRun && isset($reverse[$file]) ? $reverse[$file] : $file;
    }

    private function rewritePhp(): void
    {
        $discovery = $this->discover();
        $files = [...$discovery->phpFiles, ...$discovery->livewireComponents, ...$discovery->tests];

        if ($files === []) {
            return;
        }

        $rector = new RectorRunner($this->basePath, $this->rectorBinary);

        if ($this->useRector && $rector->isAvailable() && ! $this->dryRun) {
            $this->engine = 'Rector ('.$this->relative((string) $rector->binary()).') + built-in rewriter';

            // Count the Filament constructs before Rector renames them (the built-in rewriter
            // measures them; after Rector it only sees what is left).
            foreach ($files as $file) {
                if ($this->files->exists($file)) {
                    $scratch = new MigrationReport($this->basePath);
                    (new TokenRewriter($scratch))->rewrite($file, $this->files->get($file));
                    $this->report->converted($file, $scratch->convertedIn($file));

                    foreach ($scratch->suggestedPlugins() as $slug => $reasons) {
                        foreach ($reasons as $reason) {
                            $this->report->suggestPlugin($slug, $reason);
                        }
                    }
                }
            }

            $this->backUp();
            $this->log('Rewriting PHP with Rector ('.count($files).' files)');
            $rector->run($files, $this->report);
        } elseif ($this->useRector && $rector->isAvailable()) {
            $this->engine = 'Rector ('.$this->relative((string) $rector->binary()).') + built-in rewriter (dry run previews with the built-in rewriter)';
        }

        $rewriter = new TokenRewriter($this->report);

        foreach ($files as $file) {
            $source = $this->sourceOf($file);

            if (! $this->files->exists($source)) {
                continue;
            }

            $original = $this->files->get($source);
            $rewritten = $rewriter->rewrite($file, $original, force: str_starts_with($this->engine, 'Rector'));

            if ($rewritten === $original) {
                continue;
            }

            $this->backUp();
            $this->rewrittenFiles[] = $file;
            $this->log('Rewrote '.$this->relative($file));

            if (! $this->dryRun) {
                $this->files->put($file, $rewritten);
            }
        }

        if ($this->rewrittenFiles !== [] || str_starts_with($this->engine, 'Rector')) {
            $this->report->action(sprintf('Rewrote %d PHP file(s) from Filament to AlfaKit with the %s.', max(count($this->rewrittenFiles), str_starts_with($this->engine, 'Rector') ? count($files) : 0), $this->engine));
        }

        foreach ($discovery->livewireComponents as $file) {
            $this->report->add(MigrationReport::SECTION_VIEWS, $file, $this->firstLine($this->sourceOf($file), '/InteractsWith(Forms|Table|Actions|Infolists|Schemas)|HasForms|HasTable|HasActions/'), 'Livewire component using Filament forms/tables', 'Move it to a standalone component: `php artisan make:alfakit-form '.pathinfo($file, PATHINFO_FILENAME).'` (or `make:alfakit-table` / `make:alfakit-component`) and render it in your Inertia page with `<AlfakitComponent component={prop} />` (docs: standalone/overview). The Blade view and Livewire class can then be deleted.');
        }

        foreach ($discovery->tests as $file) {
            $line = $this->firstLine($this->sourceOf($file), '/\blivewire\(|Livewire::test\(/');

            if ($line !== null) {
                $this->report->add(MigrationReport::SECTION_TESTS, $file, $line, 'Livewire test helpers (`livewire()` / `Livewire::test()`)', 'Use `alfakit()->testPage(ListPosts::class)->assertCanSeeTableRecords(...)`, `->fillForm([...])->call(\'save\')`, `->callAction()`, `->callTableAction()` (docs: advanced/testing).');
            }
        }
    }

    private function registerProviders(): void
    {
        $providers = $this->basePath.'/bootstrap/providers.php';
        $directory = $this->basePath.'/'.self::DIRECTORY_MOVES['app/Providers/Filament'];
        $source = $this->dryRun ? $this->basePath.'/app/Providers/Filament' : $directory;

        if (! $this->files->isDirectory($source)) {
            return;
        }

        foreach ($this->files->files($source) as $file) {
            $class = 'App\\Providers\\Alfakit\\'.$file->getFilenameWithoutExtension();
            $this->providers[] = $class;

            if (! $this->files->exists($providers)) {
                $this->report->add(MigrationReport::SECTION_CONFIG, 'bootstrap/providers.php', null, "Register `{$class}`", 'Add it to the providers list (`bootstrap/providers.php` or `config/app.php`).');

                continue;
            }

            $contents = $this->files->get($providers);
            $filamentClass = 'App\\Providers\\Filament\\'.$file->getFilenameWithoutExtension();

            // The PHP rewrite renamed the Filament provider entry (and counted it), or will in
            // a real run: a dry run must not count it a second time.
            if (str_contains($contents, $class) || str_contains($contents, $filamentClass)) {
                $this->report->action("Registered `{$class}` in `bootstrap/providers.php` (the Filament provider entry was renamed).");

                continue;
            }

            $this->backUp();
            $this->log("Registering {$class} in bootstrap/providers.php");
            $this->report->action("Registered `{$class}` in `bootstrap/providers.php`.");
            $this->report->converted('bootstrap/providers.php');

            if ($this->dryRun) {
                continue;
            }

            $updated = preg_replace('/\];\s*$/', "    {$class}::class,\n];\n", $contents, 1);

            if ($updated !== null && $updated !== $contents) {
                $this->files->put($providers, $updated);
            }
        }
    }

    private function convertConfig(): void
    {
        $config = $this->discover()->configFile;

        if ($config === null) {
            return;
        }

        $values = [];

        try {
            $loaded = include $config;
            $values = is_array($loaded) ? $loaded : [];
        } catch (Throwable) {
            // Report the keys we know about instead.
        }

        $keys = FilamentClassMap::configKeys();

        foreach (array_keys($values) as $key) {
            $key = (string) $key;
            $this->report->add(MigrationReport::SECTION_CONFIG, 'config/filament.php', $this->firstLine($config, '/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>/'), "`{$key}`", isset($keys[$key]) ? "AlfaKit: {$keys[$key]}." : 'No AlfaKit equivalent; check `config/alfakit.php`.');
        }

        $this->backUp();
        $this->log('Removing config/filament.php');
        $this->report->action('Removed `config/filament.php` (its keys are listed in the report; AlfaKit uses `config/alfakit.php`).');

        if (! $this->dryRun) {
            $this->files->delete($config);
        }
    }

    private function convertComposerScripts(): void
    {
        $discovery = $this->discover();

        if ($discovery->composerScripts === []) {
            return;
        }

        $path = $this->basePath.'/composer.json';
        $contents = $this->files->get($path);
        $commands = FilamentClassMap::commands();

        foreach ($discovery->composerScripts as ['script' => $script, 'command' => $command]) {
            if (isset($commands[$command])) {
                $this->backUp();
                $contents = str_replace($command, $commands[$command], $contents);
                $this->report->action("Replaced `{$command}` with `{$commands[$command]}` in the composer `{$script}` script.");
                $this->report->converted('composer.json');
            } else {
                $this->report->add(MigrationReport::SECTION_CONFIG, 'composer.json', $this->firstLine($path, '/'.preg_quote($command, '/').'/'), "`{$command}` in the `{$script}` script", 'No AlfaKit command replaces it; remove it.');
            }
        }

        if (! $this->dryRun && $contents !== $this->files->get($path)) {
            $this->files->put($path, $contents);
        }
    }

    private function reportNonConvertible(): void
    {
        $discovery = $this->discover();

        foreach ($discovery->bladeViews as ['file' => $file, 'line' => $line, 'what' => $what]) {
            $name = str_replace('/', '.', substr($this->relative($file), strlen('resources/views/'), -strlen('.blade.php')));
            $this->report->add(MigrationReport::SECTION_VIEWS, $file, $line, "Blade view `{$name}` uses `{$what}`", 'Filament Blade components are not available. Pages render schema components (`form()`, `infolist()`, widgets); for custom markup register a frontend component in `createAlfakit({ components })` and point `protected static ?string $component` or `View::make()` at it. Delete the view afterwards.');
        }

        foreach ($discovery->publishedViews as $directory) {
            $this->report->add(MigrationReport::SECTION_VIEWS, $directory, null, 'Published Filament views', 'Delete the directory; customise AlfaKit through themes, slots and ejected components (`alfakit:eject`).');
        }

        $panels = [];

        foreach ($discovery->themes as $file) {
            $relative = $this->relative($file);
            $panel = explode('/', substr($relative, strlen('resources/css/filament/')))[0];
            $panels[$panel] = true;
            $this->report->add(MigrationReport::SECTION_THEMES, $file, null, 'Filament theme file', "Run `php artisan make:alfakit-theme {$panel}` and move your custom CSS into `resources/css/alfakit/themes/{$panel}.css`; AlfaKit themes override shadcn CSS variables, not Tailwind config (docs: design/themes). Remove the file and its Vite input afterwards.");
        }

        foreach (array_keys($panels) as $panel) {
            $this->report->add(MigrationReport::SECTION_THEMES, 'vite.config.js', null, "Vite input `resources/css/filament/{$panel}/theme.css`", 'Remove it from the `laravel()` inputs; `alfakit:install` added `resources/css/alfakit.css`.');
        }

        $plugins = FilamentClassMap::plugins();
        $firstParty = FilamentClassMap::pluginPackages();

        foreach ([...$discovery->packages, ...$discovery->devPackages] as $package => $constraint) {
            $suggestion = $plugins[$package] ?? 'No AlfaKit equivalent yet: implement the feature with the plugin API (`make:alfakit-plugin`) or a custom component.';
            $line = $this->firstLine($this->basePath.'/composer.json', '/"'.preg_quote($package, '/').'"/');

            if (isset($firstParty[$package])) {
                $this->report->suggestPlugin($firstParty[$package][0], "Replaces `{$package}`.");
            }

            // Filament itself and plugins AlfaKit has built in are handled by removing the package.
            $manual = isset($firstParty[$package]) || ! isset($plugins[$package]);
            $this->report->add(MigrationReport::SECTION_PLUGINS, 'composer.json', $line, "`{$package}` ({$constraint})", $suggestion, $manual);
        }
    }

    /**
     * Every Livewire component becomes an AlfaKit custom component scaffold: a schema
     * component class in `app/Alfakit/Components` and its frontend component in the
     * auto-registered `resources/js/alfakit/components` folder, both with TODO comments that
     * list the Livewire state, methods and `wire:` bindings to port. Existing files are kept.
     */
    private function scaffoldLivewireComponents(): void
    {
        $extension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$this->adapter];

        foreach ($this->discover()->livewireClasses as $file) {
            $relativeSource = $this->relative($file);
            $class = pathinfo($file, PATHINFO_FILENAME);
            $subNamespace = str_replace('/', '\\', trim(dirname(substr($relativeSource, strlen('app/Livewire/'))), '.'));
            $namespace = 'App\\Alfakit\\Components'.($subNamespace === '' ? '' : '\\'.$subNamespace);
            $livewireClass = 'App\\Livewire\\'.($subNamespace === '' ? '' : $subNamespace.'\\').$class;
            $folder = $subNamespace === '' ? '' : str_replace('\\', '/', $subNamespace).'/';
            $phpPath = "app/Alfakit/Components/{$folder}{$class}.php";
            $frontendPath = "resources/js/alfakit/components/{$folder}{$class}.{$extension}";
            $type = $folder.$class;

            if ($this->files->exists($this->basePath.'/'.$phpPath)) {
                continue;
            }

            $source = $this->files->get($file);
            [$properties, $methods] = self::livewireMembers($source);
            $view = preg_match('/view\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1 ? $matches[1] : 'livewire.'.\Illuminate\Support\Str::kebab($class);
            $viewPath = 'resources/views/'.str_replace('.', '/', $view).'.blade.php';
            $bindings = $this->files->exists($this->basePath.'/'.$viewPath) ? self::wireBindings($this->files->get($this->basePath.'/'.$viewPath)) : [];

            $props = [];
            $propTypes = [];

            foreach ($properties as $name => [$phpType, $default]) {
                $props[] = "            '{$name}' => ".($default ?? 'null').',';
                $propTypes[] = "  {$name}?: ".self::typescriptType($phpType);
            }

            $replacements = [
                '{{ namespace }}' => $namespace,
                '{{ class }}' => $class,
                '{{ component }}' => $class,
                '{{ type }}' => $type,
                '{{ phpClass }}' => $namespace.'\\'.$class,
                '{{ livewireClass }}' => $livewireClass,
                '{{ view }}' => $view,
                '{{ viewPath }}' => $viewPath,
                '{{ extension }}' => $extension,
                '{{ todo }}' => MigrationReport::TODO_MARKER,
                '{{ properties }}' => $properties === [] ? 'none' : implode(', ', array_map(static fn (string $name): string => '$'.$name, array_keys($properties))),
                '{{ methods }}' => $methods === [] ? 'none' : implode(', ', array_map(static fn (string $name): string => $name.'()', $methods)),
                '{{ bindings }}' => $bindings === [] ? 'none' : implode(', ', $bindings),
                '{{ props }}' => $props === [] ? '            // no public properties' : implode("\n", $props),
            ];

            $php = strtr((string) file_get_contents(dirname(__DIR__, 2).'/stubs/migration/livewire-component.stub'), $replacements);
            $indent = $this->adapter === 'react' ? '  ' : ($this->adapter === 'vue' ? '    ' : '      ');
            $frontend = strtr((string) file_get_contents(dirname(__DIR__, 2)."/stubs/migration/{$this->adapter}/livewire-component.stub"), [
                ...$replacements,
                '{{ propTypes }}' => $propTypes === [] ? $indent.'// no public properties' : implode("\n", array_map(static fn (string $line): string => $indent.ltrim($line), $propTypes)),
            ]);

            $this->backUp();
            $this->scaffolded[] = $phpPath;
            $this->scaffolded[] = $frontendPath;
            $this->log("Scaffolding {$phpPath} and {$frontendPath} from {$relativeSource}");
            $this->report->action("Scaffolded the custom component `{$namespace}\\{$class}` (`{$phpPath}`, `{$frontendPath}`) from the Livewire component `{$livewireClass}`.");
            $this->report->add(MigrationReport::SECTION_VIEWS, $relativeSource, $this->firstLine($file, '/\bclass\s+'.preg_quote($class, '/').'\b/'), "Livewire component `{$class}` was scaffolded as an AlfaKit custom component", "Port its state, methods and Blade markup into `{$phpPath}` and `{$frontendPath}` (TODO comments list what to move), use `{$class}::make()` in a schema, then delete the Livewire class and `{$viewPath}`.");

            if ($this->dryRun) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($this->basePath.'/'.$phpPath));
            $this->files->ensureDirectoryExists(dirname($this->basePath.'/'.$frontendPath));
            $this->files->put($this->basePath.'/'.$phpPath, $php);
            $this->files->put($this->basePath.'/'.$frontendPath, $frontend);

            // Mark the Livewire class itself, above its declaration.
            $marker = '// '.MigrationReport::todoText(['what' => "Ported to {$namespace}\\{$class} ({$frontendPath})", 'suggestion' => 'move the remaining logic there, then delete this class and its view.']);
            $updated = preg_replace('/^(final\s+|abstract\s+|readonly\s+)*class\s+'.preg_quote($class, '/').'\b/m', $marker."\n$0", $this->files->get($file), 1);

            if (is_string($updated) && ! str_contains($this->files->get($file), $marker)) {
                $this->files->put($file, $updated);
                $this->report->markers($file, 1);
            }
        }
    }

    /**
     * The public properties (name → [type, literal default]) and public methods of a Livewire class.
     *
     * @return array{0: array<string, array{0: string|null, 1: string|null}>, 1: array<int, string>}
     */
    private static function livewireMembers(string $source): array
    {
        $properties = [];
        $methods = [];

        preg_match_all('/^\s*public\s+(?!function)(?:readonly\s+)?(\??[\w\\\\|]+\s+)?\$(\w+)\s*(?:=\s*([^;]+))?;/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $default = isset($match[3]) ? trim($match[3]) : null;
            $literal = $default !== null && preg_match('/^(-?\d+(\.\d+)?|true|false|null|\[\s*\]|\'[^\']*\'|"[^"$]*")$/i', $default) === 1 ? $default : null;
            $properties[$match[2]] = [trim($match[1]) === '' ? null : trim($match[1]), $literal];
        }

        preg_match_all('/^\s*public\s+function\s+(\w+)\s*\(/m', $source, $matches);

        foreach ($matches[1] as $method) {
            if (! in_array($method, ['mount', 'render', 'boot', 'booted', 'hydrate', 'dehydrate', 'form', 'table', 'infolist'], true)) {
                $methods[] = $method;
            }
        }

        return [$properties, $methods];
    }

    /**
     * The `wire:*` bindings of a Blade view (`wire:click="increment"`), deduplicated.
     *
     * @return array<int, string>
     */
    private static function wireBindings(string $view): array
    {
        preg_match_all('/\b(wire:[\w.-]+)(?:="([^"]*)")?/', $view, $matches, PREG_SET_ORDER);

        $bindings = array_map(static fn (array $match): string => '`'.$match[1].(isset($match[2]) && $match[2] !== '' ? '="'.$match[2].'"' : '').'`', $matches);

        return array_values(array_unique($bindings));
    }

    private static function typescriptType(?string $phpType): string
    {
        $type = ltrim((string) $phpType, '?');
        $nullable = str_starts_with((string) $phpType, '?') ? ' | null' : '';

        return match (strtolower($type)) {
            'int', 'float' => 'number',
            'string' => 'string',
            'bool' => 'boolean',
            'array' => 'unknown[] | Record<string, unknown>',
            default => 'unknown',
        }.$nullable;
    }

    /**
     * Blade views and theme files that need porting get a TODO marker at the top, so a search
     * for `TODO(alfakit-migration)` finds everything that is left.
     */
    private function markNonPhpFiles(): void
    {
        $discovery = $this->discover();
        $targets = [];

        foreach ($discovery->bladeViews as ['file' => $file]) {
            $targets[$file] = ['{{-- ', ' --}}', 'Port this view to a frontend component (custom component, page or widget) and delete it.'];
        }

        foreach ($discovery->themes as $file) {
            if (str_ends_with($file, '.css')) {
                $targets[$file] = ['/* ', ' */', 'Move the custom CSS into an AlfaKit theme (`php artisan make:alfakit-theme`) and delete this file.'];
            }
        }

        foreach ($targets as $file => [$open, $close, $text]) {
            $marker = $open.MigrationReport::TODO_MARKER.': '.$text.$close;

            if (! $this->files->exists($file) || str_contains($this->files->get($file), MigrationReport::TODO_MARKER)) {
                continue;
            }

            $this->report->markers($file, 1);
            $this->backUp();

            if (! $this->dryRun) {
                $this->files->put($file, $marker."\n".$this->files->get($file));
            }
        }
    }

    // ----------------------------------------------------------------------------------

    private function firstLine(string $file, string $pattern): ?int
    {
        if (! $this->files->exists($file)) {
            return null;
        }

        foreach (preg_split('/\R/', $this->files->get($file)) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->basePath.'/') ? substr($path, strlen($this->basePath) + 1) : $path;
    }

    private function log(string $message): void
    {
        if ($this->logger instanceof Closure) {
            ($this->logger)($message);
        }
    }

    /**
     * @throws RuntimeException
     */
    public static function assertDirectory(string $path): void
    {
        if (! is_dir($path) || ! is_file($path.'/composer.json')) {
            throw new RuntimeException("{$path} is not a Laravel application (no composer.json).");
        }
    }
}
