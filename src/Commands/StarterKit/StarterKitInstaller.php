<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\StarterKit;

use AlfaSix\Alfakit\Commands\InstallCommand;
use Illuminate\Support\Str;

/**
 * `alfakit:install --starter-kit`: wires Alfakit into an application made from Laravel's
 * React, Vue or Svelte starter kit. Every step is idempotent and reports what it changed.
 *
 * - `resources/js/pages/Alfakit/Page.{tsx,vue,svelte}`: the `Alfakit/Page` Inertia page.
 *   Inertia's Vite plugin resolves pages from `resources/js/pages` and the root view preloads
 *   `resources/js/pages/{component}`, so a page file (not a custom resolver in the entry) is
 *   what lets host and Alfakit pages coexist, with SSR too. It renders in the host's
 *   `AppLayout`, which is why the entry (`app.tsx` / `app.ts`) needs no change.
 * - `resources/js/components/alfakit-nav.tsx` (`AlfakitNav.vue`, `AlfakitNav.svelte`): the
 *   panel navigation built from the host's own sidebar primitives, added to the app sidebar
 *   in a marked block.
 * - `resources/js/components/app-sidebar-header.tsx` and `app-header.tsx` (`AppSidebarHeader`,
 *   `AppHeader` in Vue and Svelte): `<AlfakitSearch />` and `<AlfakitNotifications />` in the
 *   header of both app layouts, in a marked block (`--no-header-widgets` skips it).
 * - `resources/css/app.css`: the starter kit stylesheet (`standalone.css`, no shadcn palette,
 *   so the host's tokens stay) and the `@source` of the renderer's compiled components.
 * - `app/Providers/Alfakit/{Id}PanelProvider.php` with `->starterKit()`, registered in
 *   `bootstrap/providers.php`.
 */
final class StarterKitInstaller
{
    /** Marks the lines the installer adds to the host's files (so a second run skips them). */
    public const MARKER = 'alfakit:nav';

    /** Marks the header components the installer adds (`<AlfakitSearch />`, `<AlfakitNotifications />`). */
    public const HEADER_MARKER = 'alfakit:header';

    /** @var list<array{status: 'created'|'updated'|'unchanged'|'skipped', path: string, note: string}> */
    private array $changes = [];

    public function __construct(
        private readonly string $basePath,
        private readonly string $kit,
        private readonly bool $force = false,
    ) {}

    /**
     * The starter kit the application was made from, by its layout and sidebar files.
     *
     * @return 'react'|'vue'|'svelte'|null
     */
    public static function detect(string $basePath): ?string
    {
        foreach (['react', 'vue', 'svelte'] as $kit) {
            $files = self::files($kit);

            if (is_file($basePath.'/'.$files['layout']) && is_file($basePath.'/'.$files['sidebar'])) {
                return $kit;
            }
        }

        return null;
    }

    /**
     * The host files of a starter kit the installer reads or writes, relative to the app root.
     *
     * @return array{layout: string, sidebar: string, sidebarHeader: string, header: string, entry: string, page: string, nav: string, navImport: string, css: string}
     */
    public static function files(string $kit): array
    {
        return match ($kit) {
            'vue' => [
                'layout' => 'resources/js/layouts/AppLayout.vue',
                'sidebar' => 'resources/js/components/AppSidebar.vue',
                'sidebarHeader' => 'resources/js/components/AppSidebarHeader.vue',
                'header' => 'resources/js/components/AppHeader.vue',
                'entry' => 'resources/js/app.ts',
                'page' => 'resources/js/pages/Alfakit/Page.vue',
                'nav' => 'resources/js/components/AlfakitNav.vue',
                'navImport' => "import AlfakitNav from '@/components/AlfakitNav.vue';",
                'css' => 'resources/css/app.css',
            ],
            'svelte' => [
                'layout' => 'resources/js/layouts/AppLayout.svelte',
                'sidebar' => 'resources/js/components/AppSidebar.svelte',
                'sidebarHeader' => 'resources/js/components/AppSidebarHeader.svelte',
                'header' => 'resources/js/components/AppHeader.svelte',
                'entry' => 'resources/js/app.ts',
                'page' => 'resources/js/pages/Alfakit/Page.svelte',
                'nav' => 'resources/js/components/AlfakitNav.svelte',
                'navImport' => "import AlfakitNav from '@/components/AlfakitNav.svelte';",
                'css' => 'resources/css/app.css',
            ],
            default => [
                'layout' => 'resources/js/layouts/app-layout.tsx',
                'sidebar' => 'resources/js/components/app-sidebar.tsx',
                'sidebarHeader' => 'resources/js/components/app-sidebar-header.tsx',
                'header' => 'resources/js/components/app-header.tsx',
                'entry' => 'resources/js/app.tsx',
                'page' => 'resources/js/pages/Alfakit/Page.tsx',
                'nav' => 'resources/js/components/alfakit-nav.tsx',
                'navImport' => "import { AlfakitNav } from '@/components/alfakit-nav';",
                'css' => 'resources/css/app.css',
            ],
        };
    }

    /**
     * Run every step.
     *
     * @return list<array{status: 'created'|'updated'|'unchanged'|'skipped', path: string, note: string}>
     */
    public function install(string $panelId = 'admin', ?string $path = null, bool $headerWidgets = true): array
    {
        $this->changes = [];
        $files = self::files($this->kit);

        $this->writeFile($files['page'], self::stub("{$this->kit}/page"), 'the Alfakit/Page Inertia page, in the app layout');
        $this->writeFile($files['nav'], self::stub("{$this->kit}/nav"), 'the panel navigation for the app sidebar');
        $this->patchFile($files['sidebar'], fn (string $contents): ?string => self::addNavToSidebar($contents, $this->kit), 'added <AlfakitNav /> after <NavMain />');
        if ($headerWidgets) {
            $this->patchFile($files['sidebarHeader'], fn (string $contents): ?string => self::addWidgetsToHeader($contents, $this->kit, sidebarLayout: true), 'added <AlfakitSearch /> and <AlfakitNotifications /> to the header');
            $this->patchFile($files['header'], fn (string $contents): ?string => self::addWidgetsToHeader($contents, $this->kit, sidebarLayout: false), 'added <AlfakitSearch /> and <AlfakitNotifications /> before the user menu');
        }

        $this->patchFile($files['css'], fn (string $contents): string => self::addStyles($contents, $this->kit), 'imported the Alfakit starter kit styles');
        $this->record('unchanged', $files['entry'], 'no change needed: Inertia resolves '.$files['page'].' like the app\'s other pages');
        $this->installPanelProvider($panelId, $path ?? $panelId);

        return $this->changes;
    }

    /**
     * Add the navigation component to the app sidebar: its import after `NavMain`'s and the
     * component after `<NavMain … />`, in a marked block. Null when the sidebar does not have
     * the starter kit's shape; unchanged when the block is there.
     */
    public static function addNavToSidebar(string $contents, string $kit): ?string
    {
        if (str_contains($contents, self::MARKER)) {
            return $contents;
        }

        $import = self::files($kit)['navImport'];

        // The import goes after the line importing NavMain, with the same indentation.
        $updated = preg_replace_callback(
            '/^([ \t]*)import[^\n]*NavMain[^\n]*\n/m',
            static fn (array $matches): string => $matches[0].$matches[1].$import."\n",
            $contents,
            1,
            $importCount,
        );

        if ($updated === null || $importCount !== 1) {
            return null;
        }

        $comment = $kit === 'react'
            ? '{/* '.self::MARKER.': AlfaKit panel navigation (php artisan alfakit:install --starter-kit) */}'
            : '<!-- '.self::MARKER.': AlfaKit panel navigation (php artisan alfakit:install --starter-kit) -->';

        $updated = preg_replace_callback(
            '/^([ \t]*)<NavMain\b[^>]*\/>[ \t]*\n/m',
            static fn (array $matches): string => $matches[0].$matches[1].$comment."\n".$matches[1]."<AlfakitNav />\n",
            $updated,
            1,
            $componentCount,
        );

        return $updated === null || $componentCount !== 1 ? null : $updated;
    }

    /**
     * Add AlfaKit's search field and notifications bell to a header of the starter kit, in a
     * marked block: at the end of the sidebar layout's header (`app-sidebar-header`), or
     * before the user menu of the header layout (`app-header`). Their import goes with the
     * host's package imports. Null when the header does not have the starter kit's shape;
     * unchanged when the block is there.
     */
    public static function addWidgetsToHeader(string $contents, string $kit, bool $sidebarLayout): ?string
    {
        if (str_contains($contents, self::HEADER_MARKER)) {
            return $contents;
        }

        $comment = $kit === 'react'
            ? '{/* '.self::HEADER_MARKER.': AlfaKit search and notifications (php artisan alfakit:install --starter-kit) */}'
            : '<!-- '.self::HEADER_MARKER.': AlfaKit search and notifications (php artisan alfakit:install --starter-kit) -->';
        $class = $kit === 'react' ? 'className' : 'class';

        if ($sidebarLayout) {
            // The last element of the header: a group pushed to the end of the row.
            $updated = preg_replace_callback(
                '/^([ \t]*)<\/header>/m',
                static function (array $matches) use ($comment, $class): string {
                    $indent = $matches[1].'    ';

                    return $indent.$comment."\n"
                        .$indent."<div {$class}=\"ms-auto flex items-center gap-2\">\n"
                        .$indent."    <AlfakitSearch />\n"
                        .$indent."    <AlfakitNotifications />\n"
                        .$indent."</div>\n"
                        .$matches[0];
                },
                $contents,
                1,
                $count,
            );
        } else {
            // Before the user menu, inside the `ml-auto` group at the end of the header.
            $updated = preg_replace_callback(
                '/(\bml-auto\b[^\n]*\n(?:(?!^[ \t]*<DropdownMenu>)[^\n]*\n)*?)^([ \t]*)<DropdownMenu>/m',
                static fn (array $matches): string => $matches[1]
                    .$matches[2].$comment."\n"
                    .$matches[2]."<AlfakitSearch />\n"
                    .$matches[2]."<AlfakitNotifications />\n"
                    .$matches[2].'<DropdownMenu>',
                $contents,
                1,
                $count,
            );
        }

        if ($updated === null || $count !== 1) {
            return null;
        }

        return self::addImport($updated, "@alfakit/{$kit}", 'import { AlfakitNotifications, AlfakitSearch } from \'@alfakit/'.$kit.'\';');
    }

    /**
     * Insert an import among the file's imports, sorted with the host's package imports: before
     * the first import of a later package or of the app's own modules (`@/`, `./`), with its
     * indentation. Null when the file has no import to place it by.
     */
    private static function addImport(string $contents, string $source, string $import): ?string
    {
        if (preg_match_all('/^([ \t]*)import\b[^;]*?from\s+[\'"]([^\'"]+)[\'"];?[ \t]*\n/m', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        foreach ($matches as $match) {
            $from = $match[2][0];

            if (str_starts_with($from, '@/') || str_starts_with($from, '.') || strcmp($from, $source) > 0) {
                return substr_replace($contents, $match[1][0].$import."\n", $match[0][1], 0);
            }
        }

        $last = $matches[array_key_last($matches)];

        return substr_replace($contents, $last[1][0].$import."\n", $last[0][1] + strlen($last[0][0]), 0);
    }

    /**
     * The starter kit stylesheet and the `@source` of the renderer's compiled components,
     * after the Tailwind import. `standalone.css` has no shadcn palette, so the host's own
     * tokens (`--primary`, `--sidebar`, `--radius`…) and dark mode apply to Alfakit pages.
     */
    public static function addStyles(string $contents, string $kit): string
    {
        if (str_contains($contents, "@alfakit/{$kit}/standalone.css")) {
            return $contents;
        }

        return InstallCommand::addStandaloneStyles($contents, $kit);
    }

    private function installPanelProvider(string $panelId, string $path): void
    {
        $id = Str::lower($panelId);
        $class = Str::studly($id).'PanelProvider';
        $relative = "app/Providers/Alfakit/{$class}.php";

        $this->writeFile($relative, strtr(self::stub('panel-provider'), [
            '{{ class }}' => $class,
            '{{ id }}' => $id,
            '{{ path }}' => trim($path, '/'),
            '{{ namespaceSuffix }}' => '',
            '{{ directorySuffix }}' => '',
        ]), 'a panel with ->starterKit() at /'.trim($path, '/'));

        $provider = "App\\Providers\\Alfakit\\{$class}";

        $this->patchFile('bootstrap/providers.php', static function (string $contents) use ($provider): ?string {
            if (str_contains($contents, $provider)) {
                return $contents;
            }

            $updated = preg_replace('/\];\s*$/', "    {$provider}::class,\n];\n", $contents, 1, $count);

            return $updated === null || $count !== 1 ? null : $updated;
        }, "registered {$provider}");
    }

    private function writeFile(string $relative, string $contents, string $note): void
    {
        $path = $this->basePath.'/'.$relative;

        if (is_file($path) && ! $this->force) {
            $this->record('unchanged', $relative, 'already exists (use --force to overwrite)');

            return;
        }

        $existed = is_file($path);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
        $this->record($existed ? 'updated' : 'created', $relative, $note);
    }

    /**
     * @param  callable(string): ?string  $patch  Returns the new contents, or null when the file has an unexpected shape.
     */
    private function patchFile(string $relative, callable $patch, string $note): void
    {
        $path = $this->basePath.'/'.$relative;

        if (! is_file($path)) {
            $this->record('skipped', $relative, 'not found');

            return;
        }

        $contents = (string) file_get_contents($path);
        $updated = $patch($contents);

        if ($updated === null) {
            $this->record('skipped', $relative, 'unexpected contents, '.$note.' yourself');

            return;
        }

        if ($updated === $contents) {
            $this->record('unchanged', $relative, 'already up to date');

            return;
        }

        file_put_contents($path, $updated);
        $this->record('updated', $relative, $note);
    }

    /**
     * @param  'created'|'updated'|'unchanged'|'skipped'  $status
     */
    private function record(string $status, string $path, string $note): void
    {
        $this->changes[] = ['status' => $status, 'path' => $path, 'note' => $note];
    }

    private static function stub(string $name): string
    {
        $published = function_exists('base_path') ? base_path("stubs/alfakit/starter-kit/{$name}.stub") : null;

        return (string) file_get_contents($published !== null && is_file($published) ? $published : __DIR__."/../../../stubs/starter-kit/{$name}.stub");
    }
}
