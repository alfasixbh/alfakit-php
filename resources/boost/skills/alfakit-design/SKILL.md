---
name: alfakit-design
description: Change how an AlfaKit panel looks — pick or build themes, layout (sidebar, top navigation, icon rail), navigation/header/surface/input/button/table styles, colours, fonts, density, branding, slots and per-panel stylesheets. Use for any visual customisation of a panel.
---

# AlfaKit design

## When to use this skill

Use it when asked to restyle a panel, make it look less like Filament, add branding, put content somewhere in the layout, or give one panel a different design. Prefer configuration over CSS, and CSS over replacing components.

## Pick a theme

```php
$panel->theme('alfakit'); // neutral | alfakit | minimal | soft | corporate | filament
```

| Theme     | Structure                                                                                                             |
| --------- | --------------------------------------------------------------------------------------------------------------------- |
| neutral   | Inset sidebar, bordered cards, plain titles                                                                           |
| alfakit   | Floating sidebar, pill nav, hero banner header, elevated borderless cards, filled inputs, pill buttons                |
| minimal   | Top navigation (no sidebar), underline tabs, compact header, flat hairline surfaces, underline inputs, square buttons |
| soft      | Icon rail, serif headings, tinted flat cards, filled inputs, striped tables                                           |
| corporate | Navy sidebar, accent-bar nav, full-width title band, square bordered surfaces, uppercase dense tables                 |
| filament  | Grey canvas, transparent sidebar, white ringed cards, amber primary, Inter                                            |

## Override parts of it

```php
$panel
    ->theme('alfakit')
    ->design(layout: 'topbar', nav: 'underline', header: 'band', surfaces: 'flat', inputs: 'outline', buttons: 'square', tables: 'striped', mobile: 'bottom')
    ->mobile()                                // the mobile preset, same as design(mobile: 'bottom')
    ->sidebarVariant('flush')                 // inset | floating | flush
    ->density('compact')                      // comfortable | compact
    ->radius('0.5rem')
    ->font('Inter')                           // loaded from Bunny Fonts; FontProvider::Google / ::None
    ->font('Inter', url: asset('fonts/inter.css'))  // your own @font-face stylesheet (FontProvider::Local, Filament's LocalFontProvider); headingFont() takes url: too
    ->font('Inter', url: asset('fonts/inter.css'), preload: [asset('fonts/inter-latin.woff2')])  // <link rel="preload" as="font"> for the files
    ->headingFont('Fraunces')
    ->colors(['primary' => '#7c3aed', 'danger' => '#dc2626', 'gray' => '#64748b', 'brand' => '#0d9488'])  // gray tints neutrals; other names work as ->color('brand')
    ->colors(['primary' => Color::Indigo, 'warning' => Color::Amber, 'brand' => Color::hex('#ff5500')])  // AlfaSix\Alfakit\Support\Colors\Color palettes: --alfakit-primary (600, or 500 when white text fails AA) + --alfakit-primary-50 … -950
    ->colors(fn (?Team $tenant) => ['primary' => $tenant?->brand_color ?? '#2563eb'])  // closure: per request ($user, $tenant, $page injectable)
    ->brandName('Acme')->brandLogo(asset('logo.svg'))->darkModeBrandLogo(asset('logo-dark.svg'))->favicon(asset('favicon.ico'));
// Every panel, from a service provider (Filament's FilamentColor::register()): AlfaSix\Alfakit\Support\Colors\Colors::register(['primary' => Color::Indigo]); theme < registered < panel colors()
$panel->credit(false); // removes the default "Developed by Alfa Six" footer line (on by default; also ->credit(fn () => …), ->creditUrl('https://…'))
// Customer-facing pages and emails: "Powered by AlfaKit · Alfa Six", OFF by default.
// config('alfakit.credit.public_pages') / config('alfakit.credit.emails') (env ALFAKIT_POWERED_BY, ALFAKIT_POWERED_BY_EMAILS),
// or per plugin: CmsPlugin / FormsPlugin / HelpdeskPlugin / AnalyticsPlugin / InvoicesPlugin ::make()->poweredBy(bool|Closure|null = true).
// Own pages: AlfaSix\Alfakit\Support\Credit::poweredBy() (HtmlString, [data-slot='powered-by']); emails: Credit::addToMail($mailMessage).
```

`design()` values: layout `sidebar|topbar|rail`, nav `soft|pill|bar|underline`, header `plain|banner|band|compact`, surfaces `bordered|elevated|flat|ring`, inputs `outline|filled|underline`, buttons `rounded|pill|square`, tables `lined|striped|minimal`, mobile `drawer|bottom`.

Mobile preset (`->mobile()`, `Theme::mobile(MobileNavigation::Bottom)`, `data-mobile="bottom"` on `<html>`): below `md` the panel gets a bottom navigation bar with the first four top-level items (the rest in a "More" sheet), tables without a content layout render as stacked cards, modals slide over from the reading-end edge, primary buttons/inputs get 44px touch targets and form actions stick above the bar. Nothing changes on desktop or in server rendering. Hooks: `[data-slot='bottom-nav']`, `[data-slot='bottom-nav-item'][data-active]`, `[data-slot='form-actions']`, `--alfakit-bottom-nav-height`; replace the bar with the `panel:bottom-nav` registry key.

## Per role, page or tenant

Every design setter takes a closure, evaluated once per request with `$user`, `$page` (the page class, null on guest pages), `$tenant`, `$route` and `$request` injectable; `layout()` is shorthand for `design(layout: …)`:

```php
$panel
    ->design(layout: fn (?User $user) => $user?->hasRole('admin') ? 'sidebar' : 'topbar')
    ->density(fn (?string $page) => $page === EditPost::class ? 'compact' : 'comfortable')
    ->theme(fn (?Team $tenant) => $tenant?->theme ?? 'neutral')
    ->viteTheme(fn (?string $page) => $page === EditPost::class ? 'resources/css/editor.css' : 'resources/css/alfakit.css');
```

The `<html>` data attributes and the shared `alfakit.theme` prop follow the result, so no frontend change is needed. Closure results are validated when they run.

## A custom theme

Generate it: `php artisan make:alfakit-theme acme --extends=alfakit --panel=admin` writes `app/Alfakit/Themes/AcmeTheme.php` (`AcmeTheme::make()` returns a `Theme`), `resources/css/alfakit/themes/acme.css` (imported in `resources/css/alfakit.css`) and adds `->theme(\App\Alfakit\Themes\AcmeTheme::make())` to the panel provider. Or write it by hand:

```php
// AppServiceProvider::boot()
Themes::register(Theme::make('acme')->extends('alfakit')->layout('topbar')->header('band')->font('Sora')->colors(['primary' => '#ea580c']));
```

`->label()` / `->description()` take a string or a closure (`fn (): string => __('themes.acme')`) so pickers show them translated; `$theme->getLabel()` resolves it.

```css
/* resources/css/alfakit.css — tokens follow shadcn; keep var(--alfakit-*) fallbacks so ->colors() still wins */
:root[data-alfakit-theme~='acme'] {
  --background: oklch(0.985 0.004 60);
  --sidebar: oklch(0.2 0.03 40);
  --sidebar-foreground: oklch(0.95 0.01 60);
}
:root.dark[data-alfakit-theme~='acme'] {
  --background: oklch(0.17 0.02 40);
}
```

## Add content to the layout

```php
$panel->slot(Slot::TOPBAR_END, fn () => view('admin.environment-badge'));
$panel->slot(Slot::PAGE_HEADER_AFTER, View::make('sales-chart'), scopes: [ListOrders::class]);
```

Slots (constants on `AlfaSix\Alfakit\Theming\Slot`; the value is the frontend name, e.g. `GLOBAL_SEARCH_START` is `global-search.start`):

- Layout: `BODY_START/END`, `LAYOUT_START/END`, `SIDEBAR_START/END`, `SIDEBAR_LOGO_BEFORE/AFTER`, `SIDEBAR_HEADER`, `SIDEBAR_NAV_START/END`, `SIDEBAR_FOOTER`, `TOPBAR_BEFORE/AFTER`, `TOPBAR_LOGO_BEFORE/AFTER` (top navigation and fullscreen layouts), `TOPBAR_START/END`, `GLOBAL_SEARCH_BEFORE/AFTER/START/END`, `TENANT_MENU_BEFORE/AFTER`, `USER_MENU_BEFORE/AFTER` (inside the dropdown), `USER_MENU_PROFILE_BEFORE/AFTER`, `CONTENT_BEFORE/AFTER`, `CONTENT_START/END`, `FOOTER`, `HEAD_START` / `HEAD_END` (rendered into `<head>` by the root view: HTML only, closures get `?string $nonce`, inline `<script>`/`<style>` get the CSP nonce).
- Pages: `PAGE_START/END`, `PAGE_HEADER_BEFORE/AFTER`, `PAGE_HEADER_HEADING_BEFORE/AFTER`, `PAGE_ACTIONS_BEFORE/AFTER`, `PAGE_HEADER_WIDGETS_BEFORE/AFTER`, `PAGE_FOOTER_WIDGETS_BEFORE/AFTER`, `PAGE_SUB_NAVIGATION_{START,END,TOP,SIDEBAR}_{BEFORE,AFTER}`, `RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE/AFTER` and `_TABS_START/END` (List pages only), `RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE/AFTER` (`ManageRelatedRecords` pages only), `RESOURCE_RELATION_MANAGER_BEFORE/AFTER`, `RESOURCE_TABS_START/END`.
- Simple pages: `SIMPLE_LAYOUT_START/END`, `SIMPLE_PAGE_START/END`, `AUTH_BEFORE/AFTER`, `AUTH_LOGIN_FORM_BEFORE/AFTER`, `AUTH_REGISTER_FORM_BEFORE/AFTER`, `AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE/AFTER`, `AUTH_PASSWORD_RESET_RESET_FORM_BEFORE/AFTER` (each only on its page).
- Components: `TABLE_HEADER_BEFORE/AFTER`, `TABLE_TOOLBAR_BEFORE/AFTER`, `TABLE_TOOLBAR_START/END`, `TABLE_TOOLBAR_SEARCH_BEFORE/AFTER`, `TABLE_TOOLBAR_REORDER_TRIGGER_BEFORE/AFTER`, `TABLE_TOOLBAR_GROUPING_SELECTOR_BEFORE/AFTER`, `TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE/AFTER`, `TABLE_FILTER_INDICATORS`, `TABLE_SELECTION_INDICATOR_ACTIONS_BEFORE/AFTER`, `TABLE_CONTENT_BEFORE/AFTER`, `TABLE_HEADER_CELL` (per column, data `column`), `TABLE_WIDGET_START/END`, `MODAL_SCHEMA_BEFORE/AFTER` (data `action`).

`scopes:` takes one class or an array: page classes, resource classes (all their pages), relation manager classes and widget classes (content then renders only in that component's table/relation manager/widget slots). Closures can ask for `array $scopes` (current page class and its resource) and `array $data` (`panel`, `page`, `resource`, `record` on record pages, `tenant`; `nonce` in head slots). Per-item slots (`TABLE_HEADER_CELL`, modal slots) are sent once: target items with `when:` — `$panel->slot(Slot::TABLE_HEADER_CELL, '<span>€</span>', when: ['column' => ['price', 'total']])`, `when: ['action' => 'delete']`. `$panel->renderSlot(Slot::TABLE_HEADER_CELL, ['column' => 'title'])` renders one slot with data on the server; frontend `<Slot name="…" data={{ column: 'title' }} />`. For every panel, from a service provider's `boot()`: `Slots::register(Slot::TOPBAR_END, fn (): string => view('badge')->render(), scopes: [...], panels: 'admin', when: [...])` (`AlfaSix\Alfakit\Theming\Slots`, Filament's `FilamentView::registerRenderHook()`).

Light/dark: `->defaultThemeMode(ThemeMode::Dark)` (`AlfaSix\Alfakit\Theming\ThemeMode`: `Light`, `Dark`, `System`), `->themeSwitcher(false)` (hide the switcher, keep dark mode; everyone gets the default mode), `->darkMode(false)` / `->darkMode(isForced: true)` (both arguments take closures, resolved per request). `->topbar(false)` removes the sidebar layouts' top bar (phones keep a slim `data-slot="topbar" data-variant="mobile"` bar with the sidebar toggle). Navigation groups: `NavigationGroup::make('Shop')->extraSidebarAttributes(['class' => '…'])->extraTopbarAttributes([...])`. Pages: `protected ?Alignment $headerActionsAlignment = Alignment::Start;`, `protected array $extraBodyAttributes = ['class' => 'settings-page'];` (or `getExtraBodyAttributes()`).

## Style one place

- On one component: `->extraAttributes(['class' => 'bg-primary/5'])`, `->extraInputAttributes()`, `->extraCellAttributes()`, `$table->recordClasses(fn ($record) => …)`. The stylesheet must contain `@source '../../app/**/*.php';` so Tailwind sees these classes.
- With CSS: target the hooks `[data-slot='topbar'|'content'|'page-header'|'input-group']`, `[data-surface]`, `[data-input]`, `[data-sidebar='menu-button'][data-active='true']`, `[data-slot='topnav-link']`, and `<html>` attributes such as `data-panel`, `data-layout` and `data-nav`.
- Per panel: `->viteTheme('resources/css/portal.css')` (add it to Vite's `input`), or scope CSS with `:root[data-panel='portal']`.
- Replace structure: register `layout:app`, `layout:auth`, `layout:blank`, `panel:sidebar` or `panel:topbar` in `createAlfakit({ components })`, composing the exported `AppSidebar`, `Topbar`, `TopNavLayout`, `Brand`, `UserMenu` and `Slot`. Panel layouts stay mounted across visits (they get new `page`/`breadcrumbs` props instead of remounting), so a replacement must follow its props and close its own menus when the page URL changes.

## Icons

Icons are Lucide names. `heroicon-o-users` names and the `AlfaSix\Alfakit\Support\Icons\Heroicon` enum (Filament's case names, e.g. `Heroicon::OutlinedUsers`; pass it in a closure `->icon(fn () => Heroicon::OutlinedUsers)` or to `Icons::register()`) map to Lucide. An icon may also be an image URL (`->icon('https://…/x.svg')`) or inline SVG (`fn () => new HtmlString('<svg…>')`, sanitised on the server). Icon sets: `Icons::registerSet('app', resource_path('svg'))` makes `app-logo` read `resources/svg/logo.svg` (or pass a closure returning SVG). Iconify sets are opt-in: `php artisan alfakit:icons mdi` installs `@iconify-json/mdi` and adds it to `config('alfakit.icons.iconify')` (`--remove`, `--no-install`, `--search=home`), or call `Icons::iconify(['mdi', 'tabler'])` / `Icons::iconify('brand', resource_path('icons/brand.json'))`; then `->icon('mdi-home')`. Icons resolve to sanitised SVG on the server from the app's `node_modules/@iconify-json/{set}/icons.json` (`icons.iconify_path` to change), cached by file mtime, never from a CDN. Collisions: aliases, then sets (longest prefix first, falling through; last registration of a prefix wins), then `lucide-{name}` (always Lucide), Heroicons, Lucide. Change the icons Alfakit picks itself with aliases in a service provider: `Icons::register(['alfakit::actions.delete' => 'trash', 'panels::sidebar.collapse-button' => 'arrow-left'])` (`AlfaSix\Alfakit\Support\Icons`; catalogue in `Icons::DEFAULTS`, Filament alias names accepted; chrome aliases such as `alfakit::support.modal.close-button`, `alfakit::panels.sidebar.toggle-button`, `alfakit::tables.actions.filter`, `alfakit::support.pagination.next-button` replace the glyphs in all three renderers; they reach renderers as `theme.icons`. In custom components use `<Icon name="alfakit::panels.global-search.field" fallback={SearchIcon} />` (a statically imported glyph renders synchronously until replaced), or `iconAlias(name, fallback)` / `iconAliasOverride(name)` from `@alfakit/core`). To use another icon set, register a `panel:icon` component in `createAlfakit({ components })`; it receives `name`, `fallback` and the class. Sidebar widths: `->sidebarWidth('18rem')->collapsedSidebarWidth('4rem')` on the panel.

## Pitfalls

- Theme rules for surfaces, inputs and buttons beat utility classes. Use Tailwind's `!` modifier (`!shadow-none`) to override them on one element.
- Don't hardcode colours in custom CSS. Use tokens (`var(--primary)`, `var(--card)`) so dark mode and every theme keep working.
