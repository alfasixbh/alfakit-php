<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\StarterKit;

use AlfaSix\Alfakit\Commands\InstallCommand;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
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
 * - `resources/js/pages/dashboard.tsx` (`Dashboard.vue`, `Dashboard.svelte`): the row of
 *   placeholder boxes wrapped in `<AlfakitWidgets>`, so the panel's widgets show on the
 *   app's dashboard, in a marked block (`--no-dashboard-widgets` skips it).
 * - `resources/css/app.css`: the starter kit stylesheet (`standalone.css`, no shadcn palette,
 *   so the host's tokens stay) and the `@source` of the renderer's compiled components.
 * - `app/Providers/Alfakit/{Id}PanelProvider.php` with `->starterKit()`, registered in
 *   `bootstrap/providers.php`.
 */
final class StarterKitInstaller
{
    /** Marks the lines the installer adds to the host's files (so a second run skips them). */
    public const MARKER = 'alfakit:nav';

    /** Marks the header components the installer adds (`<AlfakitSearch />`, `<AlfakitNotifications />`, `<AlfakitCopilot />`, `<AlfakitLanguageSwitcher />`). */
    public const HEADER_MARKER = 'alfakit:header';

    /** Marks the dashboard widgets the installer adds to the host's dashboard (`<AlfakitWidgets>`). */
    public const DASHBOARD_MARKER = 'alfakit:widgets';

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
     * @return array{layout: string, sidebar: string, sidebarHeader: string, header: string, dashboard: string, entry: string, page: string, nav: string, navImport: string, css: string}
     */
    public static function files(string $kit): array
    {
        return match ($kit) {
            'vue' => [
                'layout' => 'resources/js/layouts/AppLayout.vue',
                'sidebar' => 'resources/js/components/AppSidebar.vue',
                'sidebarHeader' => 'resources/js/components/AppSidebarHeader.vue',
                'header' => 'resources/js/components/AppHeader.vue',
                'dashboard' => 'resources/js/pages/Dashboard.vue',
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
                'dashboard' => 'resources/js/pages/Dashboard.svelte',
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
                'dashboard' => 'resources/js/pages/dashboard.tsx',
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
    public function install(string $panelId = 'admin', ?string $path = null, bool $headerWidgets = true, bool $dashboardWidgets = true): array
    {
        $this->changes = [];
        $files = self::files($this->kit);

        $this->writeFile($files['page'], self::stub("{$this->kit}/page"), 'the Alfakit/Page Inertia page, in the app layout');
        $this->writeFile($files['nav'], self::stub("{$this->kit}/nav"), 'the panel navigation for the app sidebar');
        $this->patchFile($files['sidebar'], fn (string $contents): ?string => self::addNavToSidebar($contents, $this->kit), 'added <AlfakitNav /> after <NavMain />');
        if ($headerWidgets) {
            $this->patchFile($files['sidebarHeader'], fn (string $contents): ?string => self::addWidgetsToHeader($contents, $this->kit, sidebarLayout: true), 'added <AlfakitSearch />, <AlfakitNotifications />, <AlfakitCopilot /> and <AlfakitLanguageSwitcher /> to the header');
            $this->patchFile($files['header'], fn (string $contents): ?string => self::addWidgetsToHeader($contents, $this->kit, sidebarLayout: false), 'added <AlfakitSearch />, <AlfakitNotifications />, <AlfakitCopilot /> and <AlfakitLanguageSwitcher /> before the user menu');
        }

        if ($dashboardWidgets) {
            $this->patchFile($files['dashboard'], fn (string $contents): ?string => self::addWidgetsToDashboard($contents, $this->kit), 'wrapped the placeholder boxes in <AlfakitWidgets>: the panel\'s widgets show there');
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
     * Add AlfaKit's search field, notifications bell, AI copilot (which renders nothing
     * without the AI plugin's `copilot()`) and language switcher (nothing with fewer than two
     * `locales()`) to a header of the starter kit, in a marked block: at the end of the
     * sidebar layout's header (`app-sidebar-header`), or before the user menu of the header
     * layout (`app-header`). Their import goes with the host's package imports. Null when the
     * header does not have the starter kit's shape; a block added by an earlier version gets
     * the copilot (`addCopilotToHeader()`) and the language switcher
     * (`addLanguageSwitcherToHeader()`), so re-running `alfakit:install --starter-kit`
     * brings an existing app's header up to date.
     */
    public static function addWidgetsToHeader(string $contents, string $kit, bool $sidebarLayout): ?string
    {
        if (str_contains($contents, self::HEADER_MARKER)) {
            $contents = self::addCopilotToHeader($contents, $kit) ?? $contents;

            return self::addLanguageSwitcherToHeader($contents, $kit) ?? $contents;
        }

        $comment = $kit === 'react'
            ? '{/* '.self::HEADER_MARKER.': AlfaKit search, notifications, AI copilot and language switcher (php artisan alfakit:install --starter-kit) */}'
            : '<!-- '.self::HEADER_MARKER.': AlfaKit search, notifications, AI copilot and language switcher (php artisan alfakit:install --starter-kit) -->';
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
                        .$indent."    <AlfakitCopilot />\n"
                        .$indent."    <AlfakitLanguageSwitcher />\n"
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
                    .$matches[2]."<AlfakitCopilot />\n"
                    .$matches[2]."<AlfakitLanguageSwitcher />\n"
                    .$matches[2].'<DropdownMenu>',
                $contents,
                1,
                $count,
            );
        }

        if ($updated === null || $count !== 1) {
            return null;
        }

        return self::addImport($updated, "@alfakit/{$kit}", 'import { AlfakitCopilot, AlfakitLanguageSwitcher, AlfakitNotifications, AlfakitSearch } from \'@alfakit/'.$kit.'\';');
    }

    /**
     * Add `<AlfakitCopilot />` after `<AlfakitNotifications />` in a header block an earlier
     * version of the installer wrote, and to the import of the other header components.
     * Unchanged when it is there; null when the block has no `<AlfakitNotifications />` (or
     * the file no AlfaKit import) to place it by.
     */
    public static function addCopilotToHeader(string $contents, string $kit): ?string
    {
        if (str_contains($contents, '<AlfakitCopilot')) {
            return $contents;
        }

        $updated = preg_replace('/^([ \t]*)<AlfakitNotifications\b[^\n]*\/>[ \t]*\n/m', "$0$1<AlfakitCopilot />\n", $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            return null;
        }

        $package = preg_quote("@alfakit/{$kit}", '/');
        $updated = preg_replace_callback(
            '/^([ \t]*import\s*\{)([^}]*)(\}\s*from\s*[\'"]'.$package.'[\'"])/m',
            static function (array $matches): string {
                $names = array_values(array_filter(array_map('trim', explode(',', $matches[2])), static fn (string $name): bool => $name !== ''));
                $names[] = 'AlfakitCopilot';
                sort($names);

                return $matches[1].' '.implode(', ', $names).' '.$matches[3];
            },
            $updated,
            1,
            $imports,
        );

        if ($updated === null) {
            return null;
        }

        return $imports === 1 ? $updated : self::addImport($updated, "@alfakit/{$kit}", "import { AlfakitCopilot } from '@alfakit/{$kit}';");
    }

    /**
     * Add `<AlfakitLanguageSwitcher />` after `<AlfakitCopilot />` (else after
     * `<AlfakitNotifications />`) in a header block an earlier version of the installer wrote,
     * and to the import of the other header components. It renders nothing until the panel
     * has two or more `locales()`, so every header gets it. Unchanged when it is there; null
     * when the block has neither component to place it by.
     */
    public static function addLanguageSwitcherToHeader(string $contents, string $kit): ?string
    {
        if (str_contains($contents, '<AlfakitLanguageSwitcher')) {
            return $contents;
        }

        foreach (['AlfakitCopilot', 'AlfakitNotifications'] as $anchor) {
            $updated = preg_replace('/^([ \t]*)<'.$anchor.'\b[^\n]*\/>[ \t]*\n/m', "$0$1<AlfakitLanguageSwitcher />\n", $contents, 1, $count);

            if ($updated !== null && $count === 1) {
                return self::addNamedImport($updated, $kit, 'AlfakitLanguageSwitcher');
            }
        }

        return null;
    }

    /**
     * Add `<AlfakitTenantSwitcher />` before `<AlfakitSearch />` in the header block (for
     * `alfakit:add teams`: a team workspace needs its team menu in the app header; it
     * follows the tenancy panel by itself, also a second `starterKit()` panel), and to the
     * import of the other header components. Unchanged when it is there; null when the
     * block has no `<AlfakitSearch />` to place it by.
     */
    public static function addTenantSwitcherToHeader(string $contents, string $kit): ?string
    {
        if (str_contains($contents, '<AlfakitTenantSwitcher')) {
            return $contents;
        }

        $updated = preg_replace('/^([ \t]*)(<AlfakitSearch\b[^\n]*\/>[ \t]*\n)/m', "$1<AlfakitTenantSwitcher />\n$1$2", $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            return null;
        }

        return self::addNamedImport($updated, $kit, 'AlfakitTenantSwitcher');
    }

    /**
     * Add a name to the `@alfakit/{kit}` import of the header components (or a new import).
     */
    private static function addNamedImport(string $contents, string $kit, string $name): ?string
    {
        $package = preg_quote("@alfakit/{$kit}", '/');
        $updated = preg_replace_callback(
            '/^([ \t]*import\s*\{)([^}]*)(\}\s*from\s*[\'"]'.$package.'[\'"])/m',
            static function (array $matches) use ($name): string {
                $names = array_values(array_filter(array_map('trim', explode(',', $matches[2])), static fn (string $item): bool => $item !== ''));
                $names[] = $name;
                sort($names);

                return $matches[1].' '.implode(', ', array_unique($names)).' '.$matches[3];
            },
            $contents,
            1,
            $imports,
        );

        if ($updated === null) {
            return null;
        }

        return $imports === 1 ? $updated : self::addImport($updated, "@alfakit/{$kit}", "import { {$name} } from '@alfakit/{$kit}';");
    }

    /**
     * Show the panel's dashboard widgets on the starter kit's dashboard: the row of
     * placeholder boxes (`grid auto-rows-min gap-4 md:grid-cols-3`) is wrapped in
     * `<AlfakitWidgets>`, in a marked block, so the widgets take its place and the boxes stay
     * while the panel has no widgets (or the user may not open it). The import goes with the
     * host's package imports (in Svelte, the instance script's). Null when the page does not
     * have the starter kit's shape; unchanged when the block is there.
     */
    public static function addWidgetsToDashboard(string $contents, string $kit): ?string
    {
        if (str_contains($contents, self::DASHBOARD_MARKER)) {
            return $contents;
        }

        $class = $kit === 'react' ? 'className' : 'class';
        $comment = $kit === 'react'
            ? '{/* '.self::DASHBOARD_MARKER.': AlfaKit dashboard widgets, the boxes show while there are none (php artisan alfakit:install --starter-kit) */}'
            : '<!-- '.self::DASHBOARD_MARKER.': AlfaKit dashboard widgets, the boxes show while there are none (php artisan alfakit:install --starter-kit) -->';

        // The placeholder row and its closing tag, at the same indentation.
        $pattern = '/^([ \t]*)<div '.$class.'="grid auto-rows-min gap-4 md:grid-cols-3">[ \t]*\n(?:.*\n)*?\1<\/div>[ \t]*\n/m';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $indent = $match[1][0];
        $block = (string) preg_replace('/^(?=[ \t]*\S)/m', '    ', $match[0][0]);
        $wrapped = $indent.$comment."\n".$indent."<AlfakitWidgets>\n".$block.$indent."</AlfakitWidgets>\n";
        $updated = substr_replace($contents, $wrapped, $match[0][1], strlen($match[0][0]));
        $import = "import { AlfakitWidgets } from '@alfakit/{$kit}';";

        if ($kit !== 'svelte') {
            return self::addImport($updated, "@alfakit/{$kit}", $import);
        }

        // Svelte: the instance script (not `<script module>`).
        if (preg_match('/<script(?![^>]*\bmodule\b)[^>]*>\n/', $updated, $script, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $script[0][1] + strlen($script[0][0]);
        $instance = self::addImport(substr($updated, $offset), "@alfakit/{$kit}", $import);

        return $instance === null ? null : substr($updated, 0, $offset).$instance;
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

            return PanelProviderEditor::addBootstrapProvider($contents, $provider);
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
