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
 * - `resources/views/app.blade.php` and the app sidebar: `<html dir>` and the sidebar's `side`
 *   follow the panel language's direction, so the whole page mirrors in a right-to-left
 *   language; the kit's logo, user info, menus, header layout (whose mobile menu opens from
 *   the end of the line too), password field, appearance tabs, two-factor setup and split auth
 *   layout get logical margins, padding and alignment (`logicalClassFiles()`).
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

    public const ASSETS_MARKER = 'alfakit:assets';

    /** Marks the user menu entries the installer added to the kit's user menu. */
    public const USER_MENU_MARKER = 'alfakit:user-menu';

    /** Marks the document direction the installer adds to the root view and the app sidebar. */
    public const DIRECTION_MARKER = 'alfakit:direction';

    /** What the root view's `<html dir>` calls. */
    public const DIRECTION_CALL = '\AlfaSix\Alfakit\Support\StarterKitDocument::direction()';

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
     * @return array{layout: string, sidebar: string, sidebarHeader: string, header: string, dashboard: string, entry: string, page: string, nav: string, navImport: string, userMenu: string, userMenuItems: string, userMenuItemsImport: string, css: string}
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
                'userMenu' => 'resources/js/components/UserMenuContent.vue',
                'userMenuItems' => 'resources/js/components/AlfakitUserMenuItems.vue',
                'userMenuItemsImport' => "import AlfakitUserMenuItems from '@/components/AlfakitUserMenuItems.vue';",
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
                'userMenu' => 'resources/js/components/UserMenuContent.svelte',
                'userMenuItems' => 'resources/js/components/AlfakitUserMenuItems.svelte',
                'userMenuItemsImport' => "import AlfakitUserMenuItems from '@/components/AlfakitUserMenuItems.svelte';",
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
                'userMenu' => 'resources/js/components/user-menu-content.tsx',
                'userMenuItems' => 'resources/js/components/alfakit-user-menu-items.tsx',
                'userMenuItemsImport' => "import { AlfakitUserMenuItems } from '@/components/alfakit-user-menu-items';",
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
        $this->writeFile($files['userMenuItems'], self::stub("{$this->kit}/user-menu-items"), 'the panel\'s user menu entries (userMenuItems(), Billing…) for the app user menu');
        // With logical margins in its icons, like the sidebar header's trigger below.
        $this->patchFile($files['userMenu'], fn (string $contents): ?string => self::withLogicalClasses(self::addUserMenuItems($contents, $this->kit), $this->kit), 'added <AlfakitUserMenuItems /> before Log out');
        if ($headerWidgets) {
            $this->patchFile($files['sidebarHeader'], fn (string $contents): ?string => self::withLogicalClasses(self::addWidgetsToHeader($contents, $this->kit, sidebarLayout: true), $this->kit), 'added {added} to the header');
            $this->patchFile($files['header'], fn (string $contents): ?string => self::addWidgetsToHeader($contents, $this->kit, sidebarLayout: false), 'added {added} before the user menu');
        }

        if ($dashboardWidgets) {
            $this->patchFile($files['dashboard'], fn (string $contents): ?string => self::addWidgetsToDashboard($contents, $this->kit), 'wrapped the placeholder boxes in <AlfakitWidgets>: the panel\'s widgets show there');
        }

        $this->patchFile($files['css'], fn (string $contents): string => self::addStyles($contents, $this->kit), 'imported the Alfakit starter kit styles');
        $this->patchFile('resources/views/app.blade.php', fn (string $contents): ?string => self::addAssetsToRootView($contents), 'added the plugin stylesheets and scripts (Assets::renderTags()) before @vite');
        $this->patchFile('resources/views/app.blade.php', fn (string $contents): ?string => self::addDirectionToRootView($contents), 'added dir="{{ '.self::DIRECTION_CALL.' }}" to <html>: the page follows the panel language\'s direction from the first paint');
        $this->patchFile($files['sidebar'], fn (string $contents): ?string => self::addDirectionToSidebar($contents, $this->kit), 'docked the sidebar on the end of the line (side follows useAlfakitDirection()): the right in a right-to-left language');

        foreach (self::logicalClassFiles($this->kit) as $relative) {
            $this->patchFile($relative, fn (string $contents): ?string => self::patchLogicalClasses($contents, $this->kit), 'logical margins, padding and alignment (ms-, me-, pe-, end-, text-start), so it mirrors in a right-to-left language');
        }

        $this->patchFile($files['header'], fn (string $contents): ?string => self::addDirectionToHeader($contents, $this->kit), 'the mobile menu opens from the end of the line (the sheet\'s side follows useAlfakitDirection()): the right in a right-to-left language');

        $this->record('unchanged', $files['entry'], 'no change needed: Inertia resolves '.$files['page'].' like the app\'s other pages');
        $this->installPanelProvider($panelId, $path ?? $panelId);

        return $this->changes;
    }

    /**
     * Plugins' stylesheets and scripts (`Assets::register()`) go in the page head; the panel's
     * own view renders them, a starter kit's `app.blade.php` doesn't. Added once, before
     * the first `@vite`; null when there is none.
     */
    public static function addAssetsToRootView(string $contents): ?string
    {
        if (str_contains($contents, 'Assets::renderTags(')) {
            return $contents;
        }

        $updated = preg_replace_callback(
            '/^([ \t]*)@vite(ReactRefresh)?\b/m',
            static fn (array $matches): string => $matches[1].'{{-- '.self::ASSETS_MARKER.': plugin stylesheets and scripts (php artisan alfakit:install --starter-kit) --}}'."\n"
                .$matches[1].'{!! \\AlfaSix\\Alfakit\\Support\\Assets\\Assets::renderTags(alfakit()->getCurrentPanel()?->getId(), \\Illuminate\\Support\\Facades\\Vite::cspNonce()) !!}'."\n"
                .$matches[0],
            $contents,
            1,
            $count,
        );

        return $updated === null || $count !== 1 ? null : $updated;
    }

    /**
     * `<html dir>` in the kit's root view, from the panel language's direction
     * (`StarterKitDocument::direction()`), so a right-to-left language mirrors the first paint
     * and SSR, before the renderer keeps it in step. Added once, after the `lang` attribute,
     * with a marker comment above the tag. Null when there is no `<html>` tag or it already
     * has a `dir` of its own; unchanged when the call is there.
     */
    public static function addDirectionToRootView(string $contents): ?string
    {
        if (str_contains($contents, 'StarterKitDocument::direction(')) {
            return $contents;
        }

        if (preg_match('/^([ \t]*)<html\b/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $indent = $match[1][0];
        $start = $match[0][1] + strlen($indent);
        $head = stripos($contents, '<head', $start);
        // Everything up to `<head>`: the kit's `@class(['dark' => …])` holds a `>`.
        $tag = substr($contents, $start, ($head === false ? strlen($contents) : $head) - $start);

        if (preg_match('/\sdir\s*=/i', $tag) === 1) {
            return null;
        }

        $offset = preg_match('/^<html\s+lang="[^"]*"/', $tag, $lang) === 1 ? strlen($lang[0]) : strlen('<html');
        $updated = substr_replace($contents, ' dir="{{ '.self::DIRECTION_CALL.' }}"', $start + $offset, 0);
        $comment = $indent.'{{-- '.self::DIRECTION_MARKER.': <html dir> follows the panel language (php artisan alfakit:install --starter-kit) --}}'."\n";

        return substr_replace($updated, $comment, $match[0][1], 0);
    }

    /**
     * Dock the kit's shadcn `<Sidebar>` on the end of the line: its `side` is physical, so it
     * follows `useAlfakitDirection()` (the right in a right-to-left panel language; the
     * container, rail, border and the mobile sheet all key off it). Adds the hook's import, a
     * marked `const direction = useAlfakitDirection()` in the component and the `side` prop.
     * Null when the sidebar does not have the starter kit's shape or sets `side` itself;
     * unchanged when the hook is there.
     */
    public static function addDirectionToSidebar(string $contents, string $kit): ?string
    {
        if (str_contains($contents, 'useAlfakitDirection')) {
            return $contents;
        }

        if (preg_match('/<Sidebar(\s[^>]*)?>/', $contents, $tag, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $attributes = $tag[1][0] ?? '';

        if (preg_match('/(^|\s)(:|bind:)?side\s*=/', $attributes) === 1) {
            return null;
        }

        $end = $tag[0][1] + strlen($tag[0][0]) - (str_ends_with($tag[0][0], '/>') ? 2 : 1);
        $updated = rtrim(substr($contents, 0, $end)).' '.self::directionalSide($kit).substr($contents, $end);

        return self::addDirectionHook($updated, $kit, 'AppSidebar', 'the sidebar docks on the right in a right-to-left language');
    }

    /**
     * Open the mobile menu of the kit's header layout (`<SheetContent side="left">`) from the
     * end of the line, like the sidebar: its `side` follows `useAlfakitDirection()`, so the
     * sheet slides in from the right in a right-to-left language, next to the menu button.
     * Null when the header has no sheet with the kit's `side="left"`; unchanged when the hook
     * is there.
     */
    public static function addDirectionToHeader(string $contents, string $kit): ?string
    {
        if (str_contains($contents, 'useAlfakitDirection')) {
            return $contents;
        }

        $side = self::directionalSide($kit);
        $updated = preg_replace_callback('/(<SheetContent\b[^>]*?\s)side="left"/', static fn (array $matches): string => $matches[1].$side, $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            return null;
        }

        return self::addDirectionHook($updated, $kit, 'AppHeader', 'the mobile menu opens from the right in a right-to-left language');
    }

    /** The `side` prop that follows `useAlfakitDirection()`: the right in a right-to-left language. */
    private static function directionalSide(string $kit): string
    {
        return match ($kit) {
            'vue' => ':side="direction === \'rtl\' ? \'right\' : \'left\'"',
            'svelte' => 'side={direction.current === \'rtl\' ? \'right\' : \'left\'}',
            default => 'side={direction === \'rtl\' ? \'right\' : \'left\'}',
        };
    }

    /**
     * A marked `const direction = useAlfakitDirection()` in the component (React: the start of
     * the exported function `$component`; Vue and Svelte: the end of the component's script)
     * and the hook's import. Null when the file does not have the starter kit's shape.
     */
    private static function addDirectionHook(string $updated, string $kit, string $component, string $why): ?string
    {
        $comment = '// '.self::DIRECTION_MARKER.': '.$why.' (php artisan alfakit:install --starter-kit)';

        if ($kit === 'react') {
            $updated = preg_replace_callback(
                '/^([ \t]*)export (?:default )?function '.$component.'\([^)]*\)[^{\n]*\{[ \t]*\n/m',
                static fn (array $matches): string => $matches[0]
                    .$matches[1].'    '.$comment."\n"
                    .$matches[1]."    const direction = useAlfakitDirection();\n\n",
                $updated,
                1,
                $count,
            );

            if ($updated === null || $count !== 1) {
                return null;
            }
        } else {
            // At the end of the component's script (Vue's `<script setup>`, Svelte's instance
            // script), with the indentation of its first line.
            $open = $kit === 'vue' ? '/<script\b[^>]*\bsetup\b[^>]*>\n/' : '/<script(?![^>]*\bmodule\b)[^>]*>\n/';

            if (preg_match($open, $updated, $script, PREG_OFFSET_CAPTURE) !== 1) {
                return null;
            }

            $bodyStart = $script[0][1] + strlen($script[0][0]);
            $close = strpos($updated, '</script>', $bodyStart);

            if ($close === false) {
                return null;
            }

            $indent = preg_match('/^([ \t]*)\S/m', substr($updated, $bodyStart, $close - $bodyStart), $first) === 1 ? $first[1] : '';
            $body = rtrim(substr($updated, $bodyStart, $close - $bodyStart))."\n\n"
                .$indent.$comment."\n"
                .$indent."const direction = useAlfakitDirection();\n";
            $updated = substr($updated, 0, $bodyStart).$body.substr($updated, $close);
        }

        return self::addNamedImport($updated, $kit, 'useAlfakitDirection');
    }

    /**
     * The kit's components that use physical margins, padding, positions or alignment: the
     * sidebar's logo, user info and user button, the header layout's header, the password
     * field's reveal button, the appearance tabs, the two-factor setup's copy button, the
     * split auth layout and (React) the login page's "Forgot password" link. The user menu
     * and the sidebar header get logical classes with their own patches.
     *
     * @return list<string>
     */
    public static function logicalClassFiles(string $kit): array
    {
        if ($kit === 'react') {
            return [
                'resources/js/components/app-logo.tsx',
                'resources/js/components/user-info.tsx',
                'resources/js/components/nav-user.tsx',
                'resources/js/components/app-header.tsx',
                'resources/js/components/password-input.tsx',
                'resources/js/components/appearance-tabs.tsx',
                'resources/js/components/two-factor-setup-modal.tsx',
                'resources/js/layouts/auth/auth-split-layout.tsx',
                'resources/js/pages/auth/login.tsx',
            ];
        }

        $names = ['components/AppLogo', 'components/UserInfo', 'components/NavUser', 'components/AppHeader', 'components/PasswordInput', 'components/AppearanceTabs', 'components/TwoFactorSetupModal', 'layouts/auth/AuthSplitLayout'];

        return array_map(static fn (string $name): string => "resources/js/{$name}.{$kit}", $names);
    }

    /**
     * The starter kits' physical classes (`ml-1 … text-left`, `ml-auto`, `-ml-1`, `mr-2`,
     * `pr-10`, `right-0 … rounded-r-md`, `border-l`, `dark:border-r`) as their logical twins,
     * which are the same left to right and mirror right to left. Only these exact class
     * lists of the kits' files change; others are left as they are.
     */
    public static function useLogicalClasses(string $contents, string $kit): string
    {
        return strtr($contents, self::logicalClasses($kit));
    }

    /**
     * `useLogicalClasses()` for a file of `logicalClassFiles()`: null when it has none of the
     * kit's class lists, physical or logical (the app changed it, so it is left alone with a
     * note); unchanged when it already has the logical ones.
     */
    public static function patchLogicalClasses(string $contents, string $kit): ?string
    {
        $updated = self::useLogicalClasses($contents, $kit);

        if ($updated !== $contents) {
            return $updated;
        }

        foreach (self::logicalClasses($kit) as $logical) {
            if (str_contains($contents, $logical)) {
                return $contents;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function logicalClasses(string $kit): array
    {
        $attribute = $kit === 'react' ? 'className' : 'class';

        return [
            // Sidebar: logo, user info, user button and menu, sidebar header.
            '"ml-1 grid flex-1 text-left text-sm"' => '"ms-1 grid flex-1 text-start text-sm"',
            '"grid flex-1 text-left text-sm leading-tight"' => '"grid flex-1 text-start text-sm leading-tight"',
            '"flex items-center gap-2 px-1 py-1.5 text-left text-sm"' => '"flex items-center gap-2 px-1 py-1.5 text-start text-sm"',
            "<ChevronsUpDown {$attribute}=\"ml-auto " => "<ChevronsUpDown {$attribute}=\"ms-auto ",
            "<SidebarTrigger {$attribute}=\"-ml-1\"" => "<SidebarTrigger {$attribute}=\"-ms-1\"",
            "<LogOut {$attribute}=\"mr-2" => "<LogOut {$attribute}=\"me-2",
            "<Settings {$attribute}=\"mr-2" => "<Settings {$attribute}=\"me-2",
            // Header layout: menu button, sheet header, navigation and its icons, active
            // underline, the group at the end of the row.
            '"mr-2 h-[34px] w-[34px]"' => '"me-2 h-[34px] w-[34px]"',
            '"mr-2 h-9 w-9"' => '"me-2 h-9 w-9"',
            '"flex justify-start text-left"' => '"flex justify-start text-start"',
            '"ml-6 hidden h-full items-center space-x-6 lg:flex"' => '"ms-6 hidden h-full items-center space-x-6 lg:flex"',
            '"ml-10 flex h-full items-stretch"' => '"ms-10 flex h-full items-stretch"',
            '"mr-2 h-4 w-4"' => '"me-2 h-4 w-4"',
            '"absolute bottom-0 left-0 h-0.5 w-full translate-y-px' => '"absolute bottom-0 start-0 h-0.5 w-full translate-y-px',
            '"ml-auto flex items-center space-x-2"' => '"ms-auto flex items-center space-x-2"',
            '"ml-1 hidden gap-1 lg:flex"' => '"ms-1 hidden gap-1 lg:flex"',
            // Password field: room for the reveal button, and the button at the end.
            "cn('pr-10'," => "cn('pe-10',",
            'inset-y-0 right-0 flex items-center rounded-r-md px-3' => 'inset-y-0 end-0 flex items-center rounded-e-md px-3',
            // Appearance tabs: the icon and the label.
            '"-ml-1 h-4 w-4"' => '"-ms-1 h-4 w-4"',
            '"ml-1.5 text-sm"' => '"ms-1.5 text-sm"',
            // Two-factor setup: the copy button next to the setup key.
            'border-l border-border px-3 hover:bg-muted"' => 'border-s border-border px-3 hover:bg-muted"',
            // Split auth layout: the panel's border, the logo and the heading.
            'lg:flex dark:border-r"' => 'lg:flex dark:border-e"',
            '"mr-2 size-8 fill-current text-white"' => '"me-2 size-8 fill-current text-white"',
            '"flex flex-col items-start gap-2 text-left sm:items-center sm:text-center"' => '"flex flex-col items-start gap-2 text-start sm:items-center sm:text-center"',
            // Login (React): "Forgot password" at the end of the label row.
            '"ml-auto text-sm"' => '"ms-auto text-sm"',
        ];
    }

    private static function withLogicalClasses(?string $contents, string $kit): ?string
    {
        return $contents === null ? null : self::useLogicalClasses($contents, $kit);
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
     * Add the panel's user menu entries (`<AlfakitUserMenuItems />`) to the kit's user menu,
     * in a marked block before the separator above Log out, and its import after the
     * dropdown-menu import. Null when the file does not have the starter kit's shape;
     * unchanged when the block is there.
     */
    public static function addUserMenuItems(string $contents, string $kit): ?string
    {
        if (str_contains($contents, self::USER_MENU_MARKER)) {
            return $contents;
        }

        $logout = strpos($contents, 'logout-button');
        $separator = $logout === false ? false : strrpos(substr($contents, 0, $logout), '<DropdownMenuSeparator');

        if ($separator === false) {
            return null;
        }

        $lineStart = strrpos(substr($contents, 0, $separator), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = substr($contents, $lineStart, $separator - $lineStart);

        if (trim($indent) !== '') {
            return null;
        }

        $comment = $kit === 'react'
            ? '{/* '.self::USER_MENU_MARKER.': AlfaKit panel user menu entries (php artisan alfakit:install --starter-kit) */}'
            : '<!-- '.self::USER_MENU_MARKER.': AlfaKit panel user menu entries (php artisan alfakit:install --starter-kit) -->';
        $updated = substr_replace($contents, $indent.$comment."\n".$indent."<AlfakitUserMenuItems />\n", $lineStart, 0);

        // The import goes before the (multi-line) import of the app's dropdown menu, which
        // keeps the app's `@/components/…` imports sorted.
        $import = self::files($kit)['userMenuItemsImport'];
        $result = preg_replace_callback(
            '/^([ \t]*)import\b[^;]*?from\s+[\'"]@\/components\/ui\/dropdown-menu[\'"];?[ \t]*\n/m',
            static fn (array $matches): string => $matches[1].$import."\n".$matches[0],
            $updated,
            1,
            $count,
        );

        return $result === null || $count !== 1 ? null : $result;
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
            // Before the user menu, inside the `ml-auto` group at the end of the header (`ms-auto`
            // once the installer made the header's classes logical).
            $updated = preg_replace_callback(
                '/(\bm[ls]-auto\b[^\n]*\n(?:(?!^[ \t]*<DropdownMenu>)[^\n]*\n)*?)^([ \t]*)<DropdownMenu>/m',
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
            $this->record('skipped', $relative, 'unexpected contents, '.str_replace('{added}', '<AlfakitSearch />, <AlfakitNotifications />, <AlfakitCopilot /> and <AlfakitLanguageSwitcher />', $note).' yourself');

            return;
        }

        if ($updated === $contents) {
            $this->record('unchanged', $relative, 'already up to date');

            return;
        }

        file_put_contents($path, $updated);
        $this->record('updated', $relative, str_replace('{added}', self::addedComponents($contents, $updated), $note));
    }

    /**
     * The header components a patch added, as "<A />, <B /> and <C />": a re-run that only
     * adds the language switcher names only the switcher.
     */
    public static function addedComponents(string $before, string $after): string
    {
        $added = [];

        foreach (['AlfakitSearch', 'AlfakitNotifications', 'AlfakitCopilot', 'AlfakitTenantSwitcher', 'AlfakitLanguageSwitcher'] as $component) {
            if (substr_count($after, '<'.$component.' ') + substr_count($after, '<'.$component.'/') > substr_count($before, '<'.$component.' ') + substr_count($before, '<'.$component.'/')) {
                $added[] = '<'.$component.' />';
            }
        }

        if ($added === []) {
            return 'the AlfaKit header components';
        }

        $last = array_pop($added);

        return $added === [] ? $last : implode(', ', $added).' and '.$last;
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
