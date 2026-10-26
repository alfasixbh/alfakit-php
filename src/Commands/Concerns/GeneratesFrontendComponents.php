<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Shared by the generators that pair a PHP class with a renderer component (fields,
 * columns, entries, widgets, pages): the adapter and the component file in its
 * auto-registered folder (`resources/js/alfakit/fields/…`), or, for entries without
 * `createAlfakit({ discover })`, its registration in `createAlfakit({ components })`.
 */
trait GeneratesFrontendComponents
{
    use CanGenerateFiles;
    use DetectsFrontendAdapter;

    /** @var array<string, string> */
    protected array $componentExtensions = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'];

    /**
     * The auto-registered folder of each kind, under `resources/js/alfakit`.
     *
     * @var array<string, string>
     */
    protected array $componentFolders = [
        'field' => 'fields',
        'shadcn-field' => 'fields',
        'column' => 'columns',
        'entry' => 'entries',
        'schema-component' => 'components',
        'widget' => 'widgets',
    ];

    /**
     * Generate the PHP class and (unless `--no-frontend`) the component for the adapter.
     *
     * @param  string  $kind  `field`, `column`, `entry` or `schema-component` (the stub names)
     * @param  string  $subNamespace  Below the generation namespace (`Forms\Components`)
     * @param  string  $key  The registry key the component is registered under
     * @param  array<string, string>  $replacements  Extra stub replacements (both stubs)
     * @param  string|null  $frontendStub  The component stub, when not `$kind`
     */
    protected function generateComponent(string $kind, string $name, string $subNamespace, string $type, string $key, array $replacements = [], ?string $frontendStub = null): int
    {
        $force = (bool) $this->option('force');
        $class = Str::studly($name);
        $namespace = $this->getGenerationNamespace().'\\'.$subNamespace;
        $path = $this->getGenerationPath().'/'.str_replace('\\', '/', $subNamespace)."/{$class}.php";

        $this->writeStub("components/{$kind}", $path, [
            'rule' => 'string',
            ...$replacements,
            'namespace' => $namespace,
            'class' => $class,
            'type' => $type,
        ], $force);

        if ($this->option('no-frontend')) {
            $this->components->info("Add its component as resources/js/alfakit/{$this->componentFolders[$kind]}/{$class}.(tsx|vue|svelte), or register one under the key '{$key}' in createAlfakit({ components }).");

            return self::SUCCESS;
        }

        $adapter = $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        return $this->writeFrontendComponent($adapter, $frontendStub ?? $kind, $class, $namespace.'\\'.$class, $key, $replacements, $force)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Write the component into its auto-registered folder (`fields/ColorWheel.tsx`), or, when
     * the entry doesn't auto-register (`createAlfakit({ discover })`), register it there.
     *
     * @param  array<string, string>  $replacements
     */
    protected function writeFrontendComponent(string $adapter, string $stub, string $class, string $phpClass, string $key, array $replacements, bool $force, string $section = 'components'): bool
    {
        $extension = $this->componentExtensions[$adapter];
        $folder = $section === 'pages' ? 'pages' : $this->componentFolders[$stub];
        $relative = "{$folder}/{$class}.{$extension}";

        $this->writeStub("{$section}/{$adapter}/{$stub}", resource_path("js/alfakit/{$relative}"), [
            ...$replacements,
            'component' => $class,
            'phpClass' => $phpClass,
            'key' => $key,
        ], $force);

        if ($this->discoversComponents()) {
            $this->components->task("Auto-registered '{$key}' from resources/js/alfakit/{$relative}");

            return true;
        }

        $import = "./{$folder}/{$class}".($adapter === 'react' ? '' : ".{$extension}");
        $identifier = $section === 'pages' ? "{$class}Page" : $class;

        foreach ($section === 'pages' ? ['app', 'ssr'] : ['app'] as $entry) {
            $this->registerInEntry($adapter, $key, $identifier, $import, $section, $entry);
        }

        $hasAppEntry = collect(['tsx', 'ts', 'jsx', 'js'])->contains(static fn (string $extension): bool => file_exists(resource_path("js/alfakit/app.{$extension}")));

        if ($hasAppEntry || (AppEnvironment::starterKitPageEntry() === null && ! AppEnvironment::usesStandaloneStyles())) {
            $this->components->info('Skip this step next time: add `discover: '.$this->discoveryGlob($adapter).'` to createAlfakit() in resources/js/alfakit/app.* (and createAlfakitServer() in ssr.*), and every file in fields/, columns/, entries/, widgets/, pages/, components/ and blocks/ registers itself.');
        }

        return true;
    }

    /** The `import.meta.glob()` call of an entry that auto-registers the adapter's components. */
    public static function discoveryGlob(string $adapter): string
    {
        $extension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$adapter] ?? 'tsx';

        return "import.meta.glob('./{fields,columns,entries,widgets,pages,components,blocks}/**/*.{$extension}')";
    }

    /**
     * Whether `resources/js/alfakit/app.*` (or, in a starter kit app, the host page
     * `resources/js/pages/Alfakit/Page.*`) passes `discover: import.meta.glob(…)`.
     */
    protected function discoversComponents(): bool
    {
        foreach (['tsx', 'ts', 'jsx', 'js'] as $extension) {
            $entry = resource_path("js/alfakit/app.{$extension}");

            if (file_exists($entry)) {
                return preg_match('/\bdiscover\s*:\s*import\.meta\.glob\(/', (string) file_get_contents($entry)) === 1;
            }
        }

        $page = AppEnvironment::starterKitPageEntry();

        return $page !== null && preg_match('/\bdiscover\s*:\s*import\.meta\.glob\(/', (string) file_get_contents($page)) === 1;
    }

    /** The `import.meta.glob()` of the starter kit page (relative to `resources/js/pages/Alfakit`). */
    public static function starterKitDiscoveryGlob(string $adapter): string
    {
        return str_replace("'./{", "'../../alfakit/{", static::discoveryGlob($adapter));
    }

    /**
     * Import the component in `resources/js/alfakit/{entry}.*` and add it to the `components`
     * (or `pages`) of `createAlfakit()`. Idempotent; entries that don't have that shape get
     * the snippet printed. A missing SSR entry is skipped quietly.
     */
    protected function registerInEntry(string $adapter, string $key, string $component, string $import, string $section = 'components', string $entryName = 'app'): void
    {
        $filesystem = app(Filesystem::class);
        $snippet = "import {$component} from '{$import}'\n\ncreateAlfakit({ {$section}: { '{$key}': {$component} } })";
        $entry = collect(['tsx', 'ts', 'jsx', 'js'])
            ->map(static fn (string $extension): string => resource_path("js/alfakit/{$entryName}.{$extension}"))
            ->first(static fn (string $path): bool => file_exists($path));

        if ($entry === null) {
            $page = AppEnvironment::starterKitPageEntry();

            if ($entryName === 'app' && $page !== null) {
                // Starter kit mode: the host page creates Alfakit's pages, there is no app entry.
                $file = Str::after($page, base_path().'/');
                $starterKitImport = '../../alfakit/'.Str::after($import, './');
                $factory = $adapter === 'svelte' ? 'createStarterKitLayout' : 'createStarterKitPage';

                $this->components->warn("{$file} doesn't auto-register components. Add `discover: ".static::starterKitDiscoveryGlob($adapter)."` to {$factory}() there, or register this one yourself:\n\nimport {$component} from '{$starterKitImport}'\n\n{$factory}({ layout: AppLayout, {$section}: { '{$key}': {$component} } })");
            } elseif ($entryName === 'app' && AppEnvironment::usesStandaloneStyles()) {
                // Outside panels there is no createAlfakit(): components are registered once
                // in the app's own entry with configureAlfakit().
                $appImport = './alfakit/'.Str::after($import, './');

                $this->components->warn("Outside panels, register the component once in your app entry (resources/js/app.*), or per page with the `components` prop of <AlfakitComponent>:\n\nimport { configureAlfakit } from '@alfakit/{$adapter}'\nimport {$component} from '{$appImport}'\n\nconfigureAlfakit({ {$section}: { '{$key}': {$component} } })");
            } elseif ($entryName === 'app') {
                $this->components->warn("No resources/js/alfakit/app entry found. Register the component yourself:\n\n{$snippet}");
            }

            return;
        }

        $contents = $filesystem->get($entry);

        if (preg_match('/[\'"]'.preg_quote($key, '/').'[\'"]\s*:/', $contents) === 1) {
            return;
        }

        $withComponent = preg_replace('/(\b'.preg_quote($section, '/').'\s*:\s*\{)/', "$1\n        '{$key}': {$component},", $contents, 1, $replaced);

        if (! is_string($withComponent) || $replaced !== 1) {
            $this->components->warn("Could not find `{$section}: {` in ".Str::after($entry, base_path().'/').". Register the component yourself:\n\n{$snippet}");

            return;
        }

        $semicolon = str_contains($contents, "';\n") ? ';' : '';
        $importLine = "import {$component} from '{$import}'{$semicolon}";

        // After the last import statement (which may span lines), or at the top.
        $withImport = preg_match_all('/^import\\s(?:[^\'"]*?\\sfrom\\s)?[\'"][^\'"]+[\'"];?/m', $withComponent, $matches, PREG_OFFSET_CAPTURE) > 0
            ? substr_replace($withComponent, "\n{$importLine}", (int) end($matches[0])[1] + strlen(end($matches[0])[0]), 0)
            : "{$importLine}\n{$withComponent}";

        $filesystem->put($entry, $withImport);
        \AlfaSix\Alfakit\Commands\Support\FrontendFormatter::format([$entry]);

        $this->components->task("Registered '{$key}' in ".Str::after($entry, base_path().'/'));
    }
}
