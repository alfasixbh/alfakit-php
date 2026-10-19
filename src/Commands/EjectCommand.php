<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Copy a renderer component out of `@alfakit/<adapter>` into the application so it can be
 * changed freely, and register the copy as the override for its registry key.
 *
 * ```
 * php artisan alfakit:eject TextInput            # a field, layout component or entry
 * php artisan alfakit:eject column:TextColumn    # the table cell renderer
 * php artisan alfakit:eject widget:Chart
 * php artisan alfakit:eject layout:app
 * php artisan alfakit:eject panel:sidebar
 * ```
 *
 * The npm package ships its `src/` and a `dist/eject-manifest.json` (written at build time)
 * mapping registry keys to source files and listing which source exports are public. The
 * copy's internal imports are rewritten to `@alfakit/<adapter>` when the package exports
 * them; the handful of private helpers a component needs are copied next to it, under
 * `resources/js/alfakit/components/_alfakit/`.
 */
#[AsCommand(name: 'alfakit:eject', description: 'Copy a built-in renderer component into resources/js/alfakit/components and register it as the override')]
class EjectCommand extends Command
{
    /** How deep private helpers are followed when copying. */
    protected const MAX_DEPTH = 6;

    protected $signature = 'alfakit:eject {component : Registry key: TextInput, column:TextColumn, widget:Chart, layout:app, panel:sidebar…} {--adapter= : react, vue or svelte (detected from package.json)} {--force : Overwrite files ejected earlier}';

    /** @var array<int, string> */
    protected array $extensions = ['.ts', '.tsx', '.svelte.ts', '.vue', '.svelte', '.js', '.css'];

    /** @var array<string, mixed> */
    protected array $manifest = [];

    protected string $adapter = 'react';

    protected string $packageRoot = '';

    protected string $destination = '';

    protected Filesystem $files;

    /** @var array<string, string> Files copied (or kept) during this run: source path => destination path. */
    protected array $copied = [];

    /** @var array<int, string> Files this run wrote (not the existing ones it kept). */
    protected array $written = [];

    /** @var array<int, string> Entries (`app.*`, `ssr.*`) this run registered the component in. */
    protected array $editedEntries = [];

    public function handle(Filesystem $files): int
    {
        $this->files = $files;
        // Artisan reuses the command instance within a process (tests, tinker).
        $this->copied = [];
        $this->written = [];
        $this->editedEntries = [];
        $adapter = $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        $this->adapter = $adapter;
        $this->packageRoot = base_path("node_modules/@alfakit/{$adapter}");
        $this->destination = resource_path('js/alfakit/components');
        $manifestPath = "{$this->packageRoot}/dist/eject-manifest.json";

        if (! $files->exists($manifestPath)) {
            $this->components->error("No eject manifest at node_modules/@alfakit/{$adapter}/dist/eject-manifest.json. Install @alfakit/{$adapter} 0.1 or newer (it ships its source) and try again.");

            return self::FAILURE;
        }

        $manifest = json_decode($files->get($manifestPath), true);

        if (! is_array($manifest) || ! is_array($manifest['components'] ?? null)) {
            $this->components->error('The eject manifest could not be read.');

            return self::FAILURE;
        }

        $this->manifest = $manifest;
        $key = (string) $this->argument('component');
        $entry = $this->findComponent($key);

        if ($entry === null) {
            $this->components->error("Unknown component [{$key}]. Ejectable keys:\n".$this->describeAvailableKeys());

            return self::FAILURE;
        }

        $source = "{$this->packageRoot}/{$entry['file']}";

        if (! $files->exists($source)) {
            $this->components->error("The manifest points at {$entry['file']}, which is missing from the installed package.");

            return self::FAILURE;
        }

        $target = $this->destination.'/'.basename($entry['file']);
        $this->eject($source, $target, depth: 0, isRoot: true);

        $name = (string) ($entry['name'] ?? Str::studly(pathinfo($entry['file'], PATHINFO_FILENAME)));
        $import = './components/'.$this->importPath(basename($entry['file']));
        $statement = $entry['export'] === 'default'
            ? "import {$name} from '{$import}'"
            : "import { {$entry['export']}".($entry['export'] === $name ? '' : " as {$name}")." } from '{$import}'";

        foreach (['app', 'ssr'] as $entryName) {
            $this->registerIn($entryName, $key, $name, $statement);
        }

        // The copied renderer source and the edited entries take the app's own formatter
        // (`vp fmt`, Oxfmt or Prettier), so they pass its checks as written.
        Support\FrontendFormatter::format([...array_values($this->written), ...$this->editedEntries]);

        $copied = count(array_filter($this->copied, fn (string $path): bool => str_contains($path, '/_alfakit/')));

        $this->components->info("Ejected {$key} to ".Str::after($target, base_path().'/').($copied > 0 ? " with {$copied} private helper".($copied === 1 ? '' : 's').' under components/_alfakit/' : '').'. Rebuild the frontend to use it.');

        $this->warnAboutMissingDependencies($adapter);

        return self::SUCCESS;
    }

    /**
     * The copy imports `@alfakit/core`, the framework and the icon package directly. npm and
     * Yarn hoist them from `@alfakit/<adapter>`; pnpm's strict layout does not, and the build
     * then fails to resolve them, so name the ones missing from package.json.
     */
    protected function warnAboutMissingDependencies(string $adapter): void
    {
        $packageJson = base_path('package.json');

        if (! $this->files->exists($packageJson)) {
            return;
        }

        $json = json_decode($this->files->get($packageJson), true);
        $declared = is_array($json) ? [...(array) ($json['dependencies'] ?? []), ...(array) ($json['devDependencies'] ?? [])] : [];
        $missing = [];

        foreach ($this->copied as $path) {
            if (! $this->files->exists($path) || ! preg_match('/\.(tsx?|jsx?|vue|svelte)$/', $path)) {
                continue;
            }

            preg_match_all('/^\s*import\b[^\'"]*[\'"]([^\'"]+)[\'"]/m', $this->files->get($path), $matches);

            foreach ($matches[1] as $specifier) {
                if (str_starts_with($specifier, '.') || str_starts_with($specifier, '/') || str_starts_with($specifier, "@alfakit/{$adapter}")) {
                    continue;
                }

                $package = str_starts_with($specifier, '@') ? implode('/', array_slice(explode('/', $specifier), 0, 2)) : explode('/', $specifier)[0];

                if (! isset($declared[$package])) {
                    $missing[$package] = true;
                }
            }
        }

        if ($missing === []) {
            return;
        }

        $list = implode(' ', array_keys($missing));
        $pnpm = $this->files->exists(base_path('pnpm-lock.yaml'));

        $this->components->warn("The ejected code imports {$list} directly, which package.json does not list.".($pnpm ? ' With pnpm the build cannot resolve them until you add them:' : ' npm and Yarn hoist them from @alfakit/'.$adapter.'; with pnpm add them yourself:')."\n\n".($pnpm ? 'pnpm add' : 'npm install')." {$list}");
    }

    /**
     * The adapter from `--adapter`, or the one `package.json` depends on.
     */
    protected function resolveAdapter(): ?string
    {
        $adapter = $this->option('adapter') ?? $this->detectAdapter();

        if ($adapter === null) {
            $this->components->error('Could not detect the frontend adapter from package.json. Pass --adapter=react|vue|svelte.');

            return null;
        }

        if (! in_array($adapter, ['react', 'vue', 'svelte'], true)) {
            $this->components->error("Unknown adapter [{$adapter}]. Use react, vue or svelte.");

            return null;
        }

        return (string) $adapter;
    }

    protected function detectAdapter(): ?string
    {
        $packageJson = base_path('package.json');

        if (! $this->files->exists($packageJson)) {
            return null;
        }

        $json = json_decode($this->files->get($packageJson), true);
        $dependencies = is_array($json) ? [...(array) ($json['dependencies'] ?? []), ...(array) ($json['devDependencies'] ?? [])] : [];

        foreach (['react', 'vue', 'svelte'] as $adapter) {
            if (isset($dependencies["@alfakit/{$adapter}"])) {
                return $adapter;
            }
        }

        return match (true) {
            isset($dependencies['@inertiajs/react']) => 'react',
            isset($dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@inertiajs/svelte']) => 'svelte',
            default => null,
        };
    }

    /**
     * The manifest entry for a registry key. `column:<Type>` keys share one cell renderer.
     *
     * @return array{file: string, export: string, name?: string}|null
     */
    protected function findComponent(string $key): ?array
    {
        /** @var array<string, array{file: string, export: string, name?: string}> $components */
        $components = $this->manifest['components'];

        if (isset($components[$key])) {
            return $components[$key];
        }

        if (str_starts_with($key, 'column:') && isset($components['column:*'])) {
            return $components['column:*'];
        }

        foreach ($components as $candidate => $entry) {
            if (strcasecmp($candidate, $key) === 0 || strcasecmp(Str::after($candidate, ':'), $key) === 0) {
                return $entry;
            }
        }

        return null;
    }

    protected function describeAvailableKeys(): string
    {
        /** @var array<string, mixed> $components */
        $components = $this->manifest['components'];
        $groups = ['schema' => [], 'column' => [], 'widget' => [], 'layout' => [], 'panel' => []];

        foreach (array_keys($components) as $key) {
            $group = str_contains($key, ':') ? Str::before($key, ':') : 'schema';
            $groups[$group][] = $key === 'column:*' ? 'column:<Type> (one renderer for every column type)' : $key;
        }

        $lines = [];

        foreach (array_filter($groups) as $group => $keys) {
            $lines[] = '  '.($group === 'schema' ? 'fields, layout components and entries' : $group).': '.implode(', ', $keys);
        }

        return implode("\n", $lines);
    }

    /**
     * Copy a source file into the application, rewriting its relative imports: public exports
     * become `@alfakit/<adapter>` imports, private helpers are copied too.
     */
    protected function eject(string $source, string $target, int $depth, bool $isRoot): void
    {
        if (isset($this->copied[$source])) {
            return;
        }

        $this->copied[$source] = $target;
        $relativeTarget = Str::after($target, base_path().'/');

        if ($this->files->exists($target) && ! $this->option('force')) {
            $this->components->twoColumnDetail($relativeTarget, '<fg=yellow>exists (use --force to overwrite)</>');

            // Still follow its helpers so a partially ejected tree is completed.
            if ($depth < self::MAX_DEPTH) {
                $this->rewriteImports($this->files->get($source), $source, $target, $depth, $isRoot);
            }

            return;
        }

        $code = $this->files->get($source);
        $code = $depth < self::MAX_DEPTH ? $this->rewriteImports($code, $source, $target, $depth, $isRoot) : $code;

        if ($isRoot) {
            $code = $this->header($source).$code;
        }

        $this->files->ensureDirectoryExists(dirname($target));
        $this->files->put($target, $code);
        $this->written[] = $target;
        $this->components->twoColumnDetail($relativeTarget, '<fg=green>'.($depth === 0 ? 'ejected' : 'copied').'</>');
    }

    protected function header(string $source): string
    {
        $version = (string) ($this->manifest['version'] ?? '');
        $file = Str::after($source, $this->packageRoot.'/');
        $text = "Ejected from @alfakit/{$this->adapter}".($version !== '' ? "@{$version}" : '')." ({$file}) by `php artisan alfakit:eject`. Edit freely: this copy replaces the built-in component through createAlfakit({ components }).";

        return str_ends_with($source, '.vue') || str_ends_with($source, '.svelte')
            ? "<!-- {$text} -->\n"
            : "// {$text}\n";
    }

    /**
     * Rewrite the relative `import`/`export … from` statements of a copied file.
     */
    protected function rewriteImports(string $code, string $source, string $target, int $depth, bool $isRoot): string
    {
        $pattern = '/\b(import|export)\s+(type\s+)?([^\'";]*?)\s*from\s*([\'"])(\.\.?\/[^\'"]+)\4|\bimport\s+([\'"])(\.\.?\/[^\'"]+)\6/s';

        $result = preg_replace_callback($pattern, function (array $match) use ($source, $target, $depth, $isRoot): string {
            $sideEffect = ($match[7] ?? '') !== '';
            $specifier = $sideEffect ? $match[7] : $match[5];
            $resolved = $this->resolveSource($source, $specifier);

            if ($resolved === null) {
                return $match[0];
            }

            $publicName = $this->publicNamesFor($resolved);

            if (! $sideEffect && $match[1] === 'import' && $publicName !== null) {
                $rewritten = $this->rewriteToPackage($match[3], $match[2] !== '', $publicName);

                if ($rewritten !== null) {
                    return $rewritten;
                }
            }

            // Private: copy the helper, keeping its place in the source tree so the helpers'
            // own relative imports stay valid.
            $relative = Str::after($resolved, $this->packageRoot.'/src/');
            $helperTarget = $this->destination.'/_alfakit/'.$relative;

            $this->eject($resolved, $helperTarget, $depth + 1, isRoot: false);

            if (! $isRoot) {
                return $match[0];
            }

            $newSpecifier = $this->relativeSpecifier($target, $helperTarget, $specifier);

            return str_replace($specifier, $newSpecifier, $match[0]);
        }, $code);

        return $result ?? $code;
    }

    /**
     * Resolve an import specifier to a file of the package, or null for anything else.
     */
    protected function resolveSource(string $from, string $specifier): ?string
    {
        $base = $this->normalize(dirname($from).'/'.$specifier);
        $candidates = [$base];

        foreach ($this->extensions as $extension) {
            $candidates[] = $base.$extension;
        }

        foreach ($this->extensions as $extension) {
            $candidates[] = $base.'/index'.$extension;
        }

        foreach ($candidates as $candidate) {
            if ($this->files->isFile($candidate) && str_starts_with($candidate, $this->packageRoot.'/src/')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Public names of a source file's exports (`local => public`), or null when the package
     * entry exports nothing from it.
     *
     * @return array<string, string>|null
     */
    protected function publicNamesFor(string $file): ?array
    {
        $relative = Str::after($file, $this->packageRoot.'/');
        $public = $this->manifest['public'][$relative] ?? null;

        return is_array($public) && $public !== [] ? $public : null;
    }

    /**
     * `{ A, B as C }` / `Default` / `Default, { A }` → an import from the package, or null when a
     * binding is not public (namespace imports never are).
     *
     * @param  array<string, string>  $public
     */
    protected function rewriteToPackage(string $clause, bool $typeOnly, array $public): ?string
    {
        $clause = trim($clause);

        if ($clause === '' || str_contains($clause, '*')) {
            return null;
        }

        $bindings = [];
        $named = null;

        if (preg_match('/\{(.*)\}/s', $clause, $match) === 1) {
            $named = $match[1];
            $clause = trim((string) preg_replace('/\{.*\}/s', '', $clause), " ,\n\t");
        }

        if ($clause !== '') {
            // A default import (`import Button from './Button.vue'`).
            if (! isset($public['default'])) {
                return null;
            }

            $bindings[] = $public['default'] === $clause ? $clause : "{$public['default']} as {$clause}";
        }

        $parts = $named === null ? [] : (preg_split('/\s*,\s*/', trim($named)) ?: []);

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $isType = str_starts_with($part, 'type ');
            $part = (string) preg_replace('/^type\s+/', '', $part);
            [$imported, $local] = array_pad(array_map(trim(...), preg_split('/\s+as\s+/', $part) ?: [$part]), 2, null);
            $local ??= $imported;

            if (! isset($public[$imported])) {
                return null;
            }

            $publicName = $public[$imported];
            $bindings[] = ($isType ? 'type ' : '').($publicName === $local ? $local : "{$publicName} as {$local}");
        }

        return 'import '.($typeOnly ? 'type ' : '').'{ '.implode(', ', $bindings)." } from '@alfakit/{$this->adapter}'";
    }

    /**
     * The specifier that reaches `$helper` from `$from`, with the extension style of the original.
     */
    protected function relativeSpecifier(string $from, string $helper, string $original): string
    {
        $fromParts = explode('/', dirname($from));
        $toParts = explode('/', $helper);

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $specifier = ($fromParts === [] ? './' : str_repeat('../', count($fromParts))).implode('/', $toParts);

        // The original had no extension: strip it (and `/index.*`) again.
        if (preg_match('/\.(tsx?|js|vue|svelte|css)$/', $original) !== 1) {
            $specifier = (string) preg_replace('/(\/index)?\.(svelte\.ts|tsx?|js)$/', '', $specifier);
        }

        return $specifier;
    }

    protected function importPath(string $basename): string
    {
        return preg_match('/\.(vue|svelte)$/', $basename) === 1 ? $basename : (string) preg_replace('/\.(tsx?|jsx?)$/', '', $basename);
    }

    protected function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $parts);
    }

    /**
     * Register the override in `resources/js/alfakit/{app,ssr}.*` (idempotent).
     */
    protected function registerIn(string $entryName, string $key, string $component, string $statement): void
    {
        $entry = collect(['tsx', 'ts', 'jsx', 'js'])
            ->map(static fn (string $extension): string => resource_path("js/alfakit/{$entryName}.{$extension}"))
            ->first(fn (string $path): bool => $this->files->exists($path));

        if ($entry === null) {
            if ($entryName === 'app') {
                $this->components->warn("No resources/js/alfakit/app entry found. Register the component yourself:\n\n{$statement}\n\ncreateAlfakit({ components: { '{$key}': {$component} } })");
            }

            return;
        }

        $contents = $this->files->get($entry);
        $relativeEntry = Str::after($entry, base_path().'/');

        // Quoted only when needed (`'column:TextColumn'`), as the formatters write keys.
        $property = preg_match('/^[A-Za-z_$][\w$]*$/', $key) === 1 ? $key : "'{$key}'";

        if (preg_match('/(?<![\w$\'"-])([\'"]?)'.preg_quote($key, '/').'\1\s*:/', $contents) === 1) {
            $this->components->twoColumnDetail($relativeEntry, "<fg=yellow>'{$key}' already registered</>");

            return;
        }

        $withComponent = preg_replace('/(\bcomponents\s*:\s*\{)/', "$1\n        {$property}: {$component},", $contents, 1, $replaced);

        if (! is_string($withComponent) || $replaced !== 1) {
            $this->components->warn("Could not find `components: {` in {$relativeEntry}. Register the component yourself:\n\n{$statement}\n\ncreateAlfakit({ components: { '{$key}': {$component} } })");

            return;
        }

        $semicolon = str_contains($contents, "';\n") || str_contains($contents, "\";\n") ? ';' : '';
        $importLine = $statement.$semicolon;

        if (! str_contains($withComponent, $statement)) {
            $withComponent = preg_match_all('/^import\s(?:[^\'"]*?\sfrom\s)?[\'"][^\'"]+[\'"];?/m', $withComponent, $matches, PREG_OFFSET_CAPTURE) > 0
                ? substr_replace($withComponent, "\n{$importLine}", (int) end($matches[0])[1] + strlen(end($matches[0])[0]), 0)
                : "{$importLine}\n{$withComponent}";
        }

        $this->files->put($entry, $withComponent);
        $this->editedEntries[] = $entry;
        $this->components->twoColumnDetail($relativeEntry, "<fg=green>registered '{$key}'</>");
    }
}
