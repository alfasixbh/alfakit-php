<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\Support\Icons\Heroicon;
use AlfaSix\Alfakit\Support\Icons\HeroiconMap;
use AlfaSix\Alfakit\Support\Icons\IconifySet;
use AlfaSix\Alfakit\Support\Icons\SvgSanitizer;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Stringable;

/**
 * Icons, resolved on the server to something every renderer can draw:
 *
 * - a Lucide name (`users`, `shopping-cart`), the default icon set;
 * - a `Heroicon` case or `heroicon-o-*` name, mapped to the closest Lucide icon;
 * - an icon from a registered set (`Icons::registerSet('heroicon-o', …)`) or Iconify set
 *   (`Icons::iconify('mdi')`), as inline SVG;
 * - `lucide-{name}`, always the Lucide icon (for names a registered set would claim);
 * - an image URL (`https://…/logo.svg`, `/icons/x.svg`, `asset('…')`);
 * - inline SVG markup (`new HtmlString('<svg…>')`), sanitised here;
 * - an alias (`alfakit::actions.delete`) for the icons AlfaKit uses itself.
 *
 * Replace aliases (or any icon name) in a service provider's `boot()`:
 *
 * ```php
 * Icons::register([
 *     'alfakit::actions.delete' => 'trash',
 *     'alfakit::panels.global-search.field' => Heroicon::OutlinedMagnifyingGlass,
 * ]);
 * ```
 *
 * Renderers only ever receive the resolved value, so they stay free of alias logic. Aliases
 * used by the renderer chrome reach the frontend through the panel's theme schema
 * (`getAliasOverrides()`).
 */
final class Icons
{
    /**
     * Built-in aliases and the Lucide icon each one maps to by default. Names follow
     * Filament's icon aliases (`panels::sidebar.collapse-button` →
     * `alfakit::panels.sidebar.collapse-button`); `register()` accepts either spelling.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        // Actions
        'alfakit::actions.associate' => 'link',
        'alfakit::actions.attach' => 'link',
        'alfakit::actions.create' => 'plus',
        'alfakit::actions.delete' => 'trash-2',
        'alfakit::actions.detach' => 'unlink',
        'alfakit::actions.dissociate' => 'unlink',
        'alfakit::actions.edit' => 'pencil',
        'alfakit::actions.export' => 'download',
        'alfakit::actions.force-delete' => 'trash-2',
        'alfakit::actions.group' => 'ellipsis-vertical',
        'alfakit::actions.import' => 'upload',
        'alfakit::actions.modal.confirmation' => 'triangle-alert',
        'alfakit::actions.replicate' => 'copy',
        'alfakit::actions.restore' => 'rotate-ccw',
        'alfakit::actions.view' => 'eye',
        // Forms
        'alfakit::forms.components.builder.actions.clone' => 'copy',
        'alfakit::forms.components.builder.actions.collapse' => 'chevron-up',
        'alfakit::forms.components.builder.actions.delete' => 'trash-2',
        'alfakit::forms.components.builder.actions.edit' => 'pencil',
        'alfakit::forms.components.builder.actions.expand' => 'chevron-down',
        'alfakit::forms.components.builder.actions.move-down' => 'arrow-down',
        'alfakit::forms.components.builder.actions.move-up' => 'arrow-up',
        'alfakit::forms.components.builder.actions.reorder' => 'grip-vertical',
        'alfakit::forms.components.checkbox-list.search-field' => 'search',
        'alfakit::forms.components.file-upload.editor.actions.flip-horizontal' => 'flip-horizontal-2',
        'alfakit::forms.components.file-upload.editor.actions.flip-vertical' => 'flip-vertical-2',
        'alfakit::forms.components.file-upload.editor.actions.rotate-left' => 'rotate-ccw',
        'alfakit::forms.components.file-upload.editor.actions.rotate-right' => 'rotate-cw',
        'alfakit::forms.components.file-upload.editor.actions.zoom-in' => 'zoom-in',
        'alfakit::forms.components.file-upload.editor.actions.zoom-out' => 'zoom-out',
        'alfakit::forms.components.key-value.actions.delete' => 'trash-2',
        'alfakit::forms.components.key-value.actions.reorder' => 'grip-vertical',
        'alfakit::forms.components.repeater.actions.clone' => 'copy',
        'alfakit::forms.components.repeater.actions.collapse' => 'chevron-up',
        'alfakit::forms.components.repeater.actions.delete' => 'trash-2',
        'alfakit::forms.components.repeater.actions.expand' => 'chevron-down',
        'alfakit::forms.components.repeater.actions.move-down' => 'arrow-down',
        'alfakit::forms.components.repeater.actions.move-up' => 'arrow-up',
        'alfakit::forms.components.repeater.actions.reorder' => 'grip-vertical',
        'alfakit::forms.components.rich-editor.toolbar.align-center' => 'align-center',
        'alfakit::forms.components.rich-editor.toolbar.align-end' => 'align-right',
        'alfakit::forms.components.rich-editor.toolbar.align-justify' => 'align-justify',
        'alfakit::forms.components.rich-editor.toolbar.align-start' => 'align-left',
        'alfakit::forms.components.rich-editor.toolbar.attach-files' => 'paperclip',
        'alfakit::forms.components.rich-editor.toolbar.blockquote' => 'text-quote',
        'alfakit::forms.components.rich-editor.toolbar.bold' => 'bold',
        'alfakit::forms.components.rich-editor.toolbar.bullet-list' => 'list',
        'alfakit::forms.components.rich-editor.toolbar.clear-formatting' => 'remove-formatting',
        'alfakit::forms.components.rich-editor.toolbar.code' => 'code',
        'alfakit::forms.components.rich-editor.toolbar.code-block' => 'square-code',
        'alfakit::forms.components.rich-editor.toolbar.custom-blocks' => 'blocks',
        'alfakit::forms.components.rich-editor.toolbar.details' => 'list-collapse',
        'alfakit::forms.components.rich-editor.toolbar.grid' => 'layout-grid',
        'alfakit::forms.components.rich-editor.toolbar.h1' => 'heading-1',
        'alfakit::forms.components.rich-editor.toolbar.h2' => 'heading-2',
        'alfakit::forms.components.rich-editor.toolbar.h3' => 'heading-3',
        'alfakit::forms.components.rich-editor.toolbar.highlight' => 'highlighter',
        'alfakit::forms.components.rich-editor.toolbar.horizontal-rule' => 'minus',
        'alfakit::forms.components.rich-editor.toolbar.italic' => 'italic',
        'alfakit::forms.components.rich-editor.toolbar.link' => 'link',
        'alfakit::forms.components.rich-editor.toolbar.merge-tags' => 'braces',
        'alfakit::forms.components.rich-editor.toolbar.ordered-list' => 'list-ordered',
        'alfakit::forms.components.rich-editor.toolbar.redo' => 'redo-2',
        'alfakit::forms.components.rich-editor.toolbar.strike' => 'strikethrough',
        'alfakit::forms.components.rich-editor.toolbar.subscript' => 'subscript',
        'alfakit::forms.components.rich-editor.toolbar.superscript' => 'superscript',
        'alfakit::forms.components.rich-editor.toolbar.table' => 'table',
        'alfakit::forms.components.rich-editor.toolbar.text-color' => 'palette',
        'alfakit::forms.components.rich-editor.toolbar.underline' => 'underline',
        'alfakit::forms.components.rich-editor.toolbar.undo' => 'undo-2',
        'alfakit::forms.components.select.actions.create-option' => 'plus',
        'alfakit::forms.components.select.actions.edit-option' => 'pencil',
        'alfakit::forms.components.text-input.actions.copy' => 'copy',
        'alfakit::forms.components.text-input.actions.hide-password' => 'eye-off',
        'alfakit::forms.components.text-input.actions.show-password' => 'eye',
        'alfakit::forms.components.toggle-buttons.boolean.false' => 'circle-x',
        'alfakit::forms.components.toggle-buttons.boolean.true' => 'circle-check',
        // Infolists
        'alfakit::infolists.components.icon-entry.false' => 'circle-x',
        'alfakit::infolists.components.icon-entry.true' => 'circle-check',
        // Notifications
        'alfakit::notifications.close-button' => 'x',
        'alfakit::notifications.danger' => 'circle-x',
        'alfakit::notifications.database.modal.empty-state' => 'bell-off',
        'alfakit::notifications.info' => 'info',
        'alfakit::notifications.success' => 'circle-check',
        'alfakit::notifications.warning' => 'triangle-alert',
        // Panels
        'alfakit::panels.global-search.field' => 'search',
        'alfakit::panels.pages.dashboard.actions.filter' => 'funnel',
        'alfakit::panels.pages.dashboard.navigation-item' => 'house',
        'alfakit::panels.sidebar.collapse-button' => 'chevron-left',
        'alfakit::panels.sidebar.expand-button' => 'chevron-right',
        'alfakit::panels.sidebar.group.collapse-button' => 'chevron-right',
        'alfakit::panels.sidebar.toggle-button' => 'panel-left',
        'alfakit::panels.sub-navigation.mobile-menu-button' => 'menu',
        'alfakit::panels.tenant-menu.billing-button' => 'credit-card',
        'alfakit::panels.tenant-menu.profile-button' => 'settings',
        'alfakit::panels.tenant-menu.registration-button' => 'plus',
        'alfakit::panels.tenant-menu.toggle-button' => 'chevrons-up-down',
        'alfakit::panels.theme-switcher.dark-button' => 'moon',
        'alfakit::panels.theme-switcher.light-button' => 'sun',
        'alfakit::panels.theme-switcher.system-button' => 'monitor',
        'alfakit::panels.topbar.open-database-notifications-button' => 'bell',
        'alfakit::panels.topbar.open-sidebar-button' => 'menu',
        'alfakit::panels.user-menu.logout-button' => 'log-out',
        'alfakit::panels.user-menu.profile-item' => 'user',
        'alfakit::panels.user-menu.toggle-button' => 'chevrons-up-down',
        'alfakit::panels.user-menu.install-app-item' => 'download',
        'alfakit::panels.user-menu.push-on-item' => 'bell',
        'alfakit::panels.user-menu.push-off-item' => 'bell-off',
        'alfakit::panels.widgets.account.logout-button' => 'log-out',
        // Query builder
        'alfakit::query-builder.add-rule' => 'plus',
        'alfakit::query-builder.constraints.boolean' => 'square-check',
        'alfakit::query-builder.constraints.date' => 'calendar',
        'alfakit::query-builder.constraints.number' => 'hash',
        'alfakit::query-builder.constraints.relationship' => 'link',
        'alfakit::query-builder.constraints.select' => 'list',
        'alfakit::query-builder.constraints.text' => 'type',
        'alfakit::query-builder.or-group.add-group' => 'plus',
        // Schemas
        'alfakit::schemas.callout.danger' => 'circle-x',
        'alfakit::schemas.callout.info' => 'info',
        'alfakit::schemas.callout.success' => 'circle-check',
        'alfakit::schemas.callout.warning' => 'triangle-alert',
        'alfakit::schemas.empty-state' => 'inbox',
        'alfakit::schemas.section.collapse-button' => 'chevron-down',
        'alfakit::schemas.tabs.more-tabs-button' => 'ellipsis',
        'alfakit::schemas.wizard.completed-step' => 'check',
        // Support (shared UI)
        'alfakit::support.breadcrumbs.separator' => 'chevron-right',
        'alfakit::support.loading-indicator' => 'loader-circle',
        'alfakit::support.modal.close-button' => 'x',
        'alfakit::support.pagination.first-button' => 'chevrons-left',
        'alfakit::support.pagination.last-button' => 'chevrons-right',
        'alfakit::support.pagination.next-button' => 'chevron-right',
        'alfakit::support.pagination.previous-button' => 'chevron-left',
        // Tables
        'alfakit::tables.actions.column-manager' => 'columns-3',
        'alfakit::tables.actions.disable-reordering' => 'check',
        'alfakit::tables.actions.enable-reordering' => 'grip-vertical',
        'alfakit::tables.actions.filter' => 'funnel',
        'alfakit::tables.actions.group' => 'layers',
        'alfakit::tables.actions.open-bulk-actions' => 'ellipsis-vertical',
        'alfakit::tables.columns.collapse-button' => 'chevron-down',
        'alfakit::tables.columns.icon-column.false' => 'circle-x',
        'alfakit::tables.columns.icon-column.true' => 'circle-check',
        'alfakit::tables.empty-state' => 'inbox',
        'alfakit::tables.filters.remove-all-button' => 'x',
        'alfakit::tables.grouping.collapse-button' => 'chevron-down',
        'alfakit::tables.header-cell.sort-asc-button' => 'arrow-up',
        'alfakit::tables.header-cell.sort-button' => 'arrow-up-down',
        'alfakit::tables.header-cell.sort-desc-button' => 'arrow-down',
        'alfakit::tables.reorder.handle' => 'grip-vertical',
        'alfakit::tables.search-field' => 'search',
        // Widgets
        'alfakit::widgets.chart.empty-state' => 'chart-column',
        'alfakit::widgets.chart.filter' => 'funnel',
    ];

    /** @var array<string, string|BackedEnum|Htmlable> */
    private static array $registered = [];

    /** @var array<string, Closure(string): (string|Htmlable|null)|string|IconifySet> */
    private static array $sets = [];

    /**
     * Map aliases (or any icon name) to the icon that should be rendered instead. Values
     * may be anything `resolve()` accepts. Filament alias names (`panels::sidebar.
     * collapse-button`) are stored under their AlfaKit name.
     *
     * @param  array<string, string|BackedEnum|Htmlable>  $icons
     */
    public static function register(array $icons): void
    {
        foreach ($icons as $alias => $icon) {
            self::$registered[self::normalizeAlias((string) $alias)] = $icon;
        }
    }

    /**
     * Register an icon set, Blade Icons style: names starting with `{prefix}-` resolve
     * through it. The source is a directory of `{name}.svg` files or a closure returning
     * SVG markup (string or `Htmlable`), an image URL, an SVG file path or null.
     *
     * ```php
     * Icons::registerSet('heroicon-o', base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg'));
     * Icons::registerSet('brand', fn (string $name) => file_get_contents(resource_path("svg/{$name}.svg")));
     * ```
     *
     * Directory sets read files named `o-{name}.svg` too, the layout blade-heroicons uses.
     *
     * Registering a prefix again replaces the earlier source (the last registration wins).
     *
     * @param  (Closure(string): (string|Htmlable|null))|string|IconifySet  $source
     */
    public static function registerSet(string $prefix, Closure|string|IconifySet $source): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/i', $prefix) !== 1) {
            throw new InvalidArgumentException("Icon set prefixes may only contain letters, numbers and dashes; [{$prefix}] given.");
        }

        self::$sets[$prefix] = $source;

        // Longest prefix first, so `heroicon-o` wins over `heroicon`.
        uksort(self::$sets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        Icons\IconCatalog::flush();
    }

    /**
     * Register Iconify icon sets (`@iconify-json/{set}` packages) under their prefix:
     * `Icons::iconify('mdi')` makes `mdi-home` resolve to that icon's SVG. The JSON is read
     * from the app's `node_modules/@iconify-json/{set}/icons.json` (or
     * `config('alfakit.icons.iconify_path')/{set}/icons.json`); pass a path to an
     * `icons.json`-style file instead, per set:
     *
     * ```php
     * Icons::iconify(['mdi', 'tabler']);
     * Icons::iconify('brand', resource_path('icons/brand.json'));
     * Icons::iconify(['mdi' => resource_path('icons/mdi.json')]);
     * ```
     *
     * Nothing is read until an icon of the set is used, and resolved icons are cached.
     *
     * @param  string|array<int|string, string>  $sets
     */
    public static function iconify(string|array $sets, ?string $path = null): void
    {
        foreach ((array) $sets as $key => $value) {
            [$set, $file] = is_string($key) ? [$key, $value] : [$value, $path];

            if (! is_string($file) || ! str_ends_with(strtolower($file), '.json')) {
                $directory = is_string($file) && $file !== '' ? $file : (config('alfakit.icons.iconify_path') ?: base_path('node_modules/@iconify-json'));
                $file = rtrim((string) $directory, '/\\').'/'.$set.'/icons.json';
            }

            self::registerSet($set, new IconifySet($set, $file));
        }
    }

    /**
     * Every registered icon set by prefix, longest prefix first.
     *
     * @return array<string, Closure(string): (string|Htmlable|null)|string|IconifySet>
     */
    public static function getSets(): array
    {
        return self::$sets;
    }

    /**
     * Forget a registered icon set.
     */
    public static function forgetSet(string $prefix): void
    {
        unset(self::$sets[$prefix]);
        Icons\IconCatalog::flush();
    }

    /**
     * The registered set whose prefix claims a name (`mdi-home` → `mdi`), longest first.
     */
    public static function setPrefixFor(string $name): ?string
    {
        foreach (array_keys(self::$sets) as $prefix) {
            if (str_starts_with($name, $prefix.'-')) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * The icon for an alias: the registered replacement, then the built-in default, then
     * `$default`, resolved like `resolve()`.
     */
    public static function get(string $alias, ?string $default = null): ?string
    {
        $alias = self::normalizeAlias($alias);

        return self::resolve(self::$registered[$alias] ?? self::DEFAULTS[$alias] ?? $default);
    }

    /**
     * Resolve an icon value set on a component to what renderers draw: a Lucide name, an
     * image URL or sanitised `<svg>` markup. Registered names are replaced, `alfakit::`
     * aliases fall back to their default, Heroicons map to Lucide and anything else is
     * returned unchanged.
     */
    public static function resolve(mixed $icon): ?string
    {
        return self::resolveValue($icon, 0);
    }

    /**
     * Whether a resolved icon is inline SVG markup.
     */
    public static function isSvg(?string $icon): bool
    {
        return $icon !== null && str_starts_with(ltrim($icon), '<');
    }

    /**
     * Whether a resolved icon is an image URL rather than an icon name.
     */
    public static function isUrl(?string $icon): bool
    {
        return $icon !== null && preg_match('#^(?:https?://|//|/|\./|data:image/(?:png|gif|jpeg|webp|svg\+xml)[;,])#i', $icon) === 1;
    }

    /**
     * Every registered replacement, as registered.
     *
     * @return array<string, string|BackedEnum|Htmlable>
     */
    public static function getRegistered(): array
    {
        return self::$registered;
    }

    /**
     * Registered replacements for `alfakit::` aliases, resolved: the renderer chrome reads
     * them (`PanelThemeSchema.icons`) and falls back to its built-in Lucide icon.
     *
     * @return array<string, string>
     */
    public static function getAliasOverrides(): array
    {
        $overrides = [];

        foreach (self::$registered as $alias => $icon) {
            if (str_starts_with($alias, 'alfakit::') && ($resolved = self::resolve($icon)) !== null && $resolved !== '') {
                $overrides[$alias] = $resolved;
            }
        }

        return $overrides;
    }

    /**
     * Forget every registered replacement and icon set (tests).
     */
    public static function flush(): void
    {
        self::$registered = [];
        self::$sets = [];
        IconifySet::flushMemo();
        Icons\IconCatalog::flush();
    }

    /**
     * `panels::sidebar.collapse-button` (Filament) → `alfakit::panels.sidebar.collapse-button`.
     */
    public static function normalizeAlias(string $alias): string
    {
        if (preg_match('/^([a-z][a-z0-9-]*)::(.+)$/', $alias, $matches) === 1 && $matches[1] !== 'alfakit') {
            return 'alfakit::'.$matches[1].'.'.$matches[2];
        }

        return $alias;
    }

    private static function resolveValue(mixed $icon, int $depth): ?string
    {
        if ($icon === null) {
            return null;
        }

        if ($icon instanceof Htmlable) {
            return SvgSanitizer::sanitize($icon->toHtml());
        }

        if ($icon instanceof Heroicon) {
            $icon = $icon->getIconName();
        } elseif ($icon instanceof BackedEnum) {
            $icon = (string) $icon->value;
        } elseif ($icon instanceof Stringable) {
            $icon = (string) $icon;
        }

        if (! is_string($icon)) {
            return null;
        }

        $icon = trim($icon);

        if ($icon === '') {
            return $icon;
        }

        // Markup in a plain string is sanitised too: renderers insert SVG as is.
        if (self::isSvg($icon)) {
            return SvgSanitizer::sanitize($icon);
        }

        if (self::isUrl($icon)) {
            return self::sanitizeUrl($icon);
        }

        $alias = self::normalizeAlias($icon);
        $replacement = self::$registered[$alias] ?? self::$registered[$icon] ?? self::DEFAULTS[$alias] ?? null;

        // Replacements may point at other aliases; stop at a sensible depth (cycles).
        if ($replacement !== null && $depth < 5 && $replacement !== $icon) {
            return self::resolveValue($replacement, $depth + 1);
        }

        if (($fromSet = self::fromSet($icon)) !== null) {
            return $fromSet;
        }

        // `lucide-flag-off`: the Lucide icon, even when a registered `flag` set exists.
        if (str_starts_with($icon, 'lucide-') && strlen($icon) > 7) {
            return substr($icon, 7);
        }

        if (HeroiconMap::isHeroiconName($icon)) {
            return HeroiconMap::toLucide($icon);
        }

        return $icon;
    }

    /**
     * Icon URLs only ever end up in `<img src>`, where `data:` images can't run scripts.
     */
    private static function sanitizeUrl(string $url): ?string
    {
        if (preg_match('#^data:image/(?:png|gif|jpeg|webp|svg\+xml)[;,]#i', $url) === 1) {
            return str_contains($url, '"') ? null : $url;
        }

        return Url::sanitize($url);
    }

    private static function fromSet(string $name): ?string
    {
        foreach (self::$sets as $prefix => $source) {
            if (! str_starts_with($name, $prefix.'-')) {
                continue;
            }

            $icon = substr($name, strlen($prefix) + 1);

            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $icon) !== 1) {
                return null;
            }

            if ($source instanceof IconifySet) {
                if (($svg = $source->svg($icon)) !== null) {
                    return $svg;
                }

                continue;
            }

            $value = is_string($source) ? self::fromDirectory($source, $prefix, $icon) : $source($icon);

            if ($value instanceof Htmlable) {
                return SvgSanitizer::sanitize($value->toHtml());
            }

            // A set without the icon lets sets with shorter prefixes try, then the usual resolution.
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (self::isSvg($value)) {
                return SvgSanitizer::sanitize($value);
            }

            if (self::isUrl($value)) {
                return self::sanitizeUrl($value);
            }

            if (str_ends_with(strtolower($value), '.svg') && is_file($value)) {
                return SvgSanitizer::sanitize((string) file_get_contents($value));
            }
        }

        return null;
    }

    private static function fromDirectory(string $directory, string $prefix, string $icon): ?string
    {
        $directory = rtrim($directory, '/\\');
        $candidates = [$directory.'/'.$icon.'.svg'];

        // blade-heroicons keeps `o-user.svg`, `s-user.svg`… in one directory.
        if (preg_match('/-([osmc])$/', $prefix, $matches) === 1) {
            $candidates[] = $directory.'/'.$matches[1].'-'.$icon.'.svg';
        }

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return (string) file_get_contents($file);
            }
        }

        return null;
    }
}
