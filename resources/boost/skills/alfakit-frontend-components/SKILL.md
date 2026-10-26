---
name: alfakit-frontend-components
description: Write custom React, Vue or Svelte components for AlfaKit panels — custom fields, columns, entries, widgets, pages, slot content and replacement layouts — that bind to the PHP schema. Use when a feature needs a visual component AlfaKit doesn't ship.
---

# AlfaKit frontend components

## When to use this skill

Use it when a panel needs UI that the built-in fields, columns, entries and widgets can't express. Examples are a map picker, a kanban board, a custom chart, or a replacement sidebar or layout. Business logic, validation and data loading stay in PHP.

## Register components

Drop the component into `resources/js/alfakit/` and it registers itself. The panel entry (`app.tsx` for React, `app.ts` for Vue and Svelte) passes Vite's glob to `discover`:

```tsx
import { createAlfakit } from '@alfakit/react' // or '@alfakit/vue' / '@alfakit/svelte'
import { MySidebar } from './chrome/my-sidebar'

void createAlfakit({
  // `.vue` / `.svelte` for the other adapters; write the glob out, Vite needs a literal.
  discover: import.meta.glob('./{fields,columns,entries,widgets,pages,components,blocks}/**/*.tsx'),
  components: {
    'panel:sidebar': MySidebar, // replace chrome: panel:sidebar, panel:topbar
    'layout:app': MyLayout, // replace layouts: layout:app, layout:auth, layout:blank
    'panel:sub-navigation': MySubNav, // cluster/record sub-navigation; receives `navigation`
    'panel:icon': MyIcon, // render every named icon with another set; receives `name`, `fallback`, class
    'panel:impersonation-banner': MyBanner, // shown while impersonating; receives `impersonation` ({ label, leaveLabel, leaveUrl }), leave = POST leaveUrl
  },
})
```

`createAlfakit()` registers the panel layouts (`layout:app`, `layout:auth`, `layout:blank`, `layout:fullscreen`) from the exported `panelLayouts`; `PageShell` never imports them, so a registry built by hand for `AlfakitPage` needs `createDefaultRegistry().registerMany(panelLayouts)` (without it a page renders bare). Starter kit pages use the host's layout (plus the panel's auth layout); a `fullscreen` page there renders `StarterKitFullscreenLayout` (the content area, no AlfaKit header; replace `layout:fullscreen` in `createStarterKitPage({ components })`): don't import panel chrome (`AppLayout`, `AppSidebar`, `Topbar`) from code that starter kit pages run, `pnpm bundle:budget` (`starter-kit:<renderer>`) fails on it.

Keys come from the path. The file name is used exactly as written, and sub-folders don't change it except under `pages/`:

| File                                         | Key                  | PHP                                                                        |
| -------------------------------------------- | -------------------- | -------------------------------------------------------------------------- |
| `fields/ColorWheel.tsx`                      | `ColorWheel`         | `class ColorWheel extends Field` (default type = class name)               |
| `columns/ProgressBar.tsx`                    | `column:ProgressBar` | `class ProgressBar extends Column`                                         |
| `entries/StarRating.tsx`                     | `StarRating`         | `class StarRating extends Entry`                                           |
| `widgets/RevenueCard.tsx`                    | `RevenueCard`        | `protected static ?string $component = 'RevenueCard'`                      |
| `components/map-picker.tsx`                  | `map-picker`         | `View::make('map-picker')`, a layout component's type, a built-in override |
| `blocks/pricing.tsx`                         | `cms:block:pricing`  | CMS block `pricing`                                                        |
| `pages/Reports.tsx`, `pages/Billing/Due.tsx` | `Pages/Reports`, …   | `protected static ?string $component = 'Pages/Reports'`                    |

The component must be the default export. `X/index.tsx` counts as `X`. Paths with a segment starting with `_`, and `*.test.*`, `*.spec.*` and `*.stories.*` files, are skipped. A file named like a built-in type (`fields/TextInput.tsx`) replaces it. Two files with the same key log a console warning, and the first file wins. Explicit `components` / `pages` win over discovered files. Each file is its own chunk. Before Inertia renders a page, AlfaKit loads the chunks that page's schema names (`type`, `component` and `view` values), on the server, during hydration and on client visits. Components that only appear later, such as in modals, load when they render. `discoverComponents(modules)` (exported by each renderer) returns `{ components, pages, discovery }` for use outside `createAlfakit()`.

With server-side rendering, `resources/js/alfakit/ssr.tsx` (or `ssr.ts`) passes the same `discover`, `components` and `pages`: `createServer(createAlfakitServer({ discover, components, pages }))`, with `createServer` from `@inertiajs/react/server` (`vue3`, `svelte`) and `createAlfakitServer` from `@alfakit/react/server` (`vue`, `svelte`). Keep both entries identical, or hydration discards the server markup. Discovered pages count as registered, so they are server-rendered.

## Generate them

`php artisan make:alfakit-field RatingInput`, `make:alfakit-column ProgressBar`, `make:alfakit-entry StarRating`, `make:alfakit-schema-component ShippingPanel`, `make:alfakit-widget RevenueCard --type=custom` and `make:alfakit-page Reports --component` write the PHP class (`App\Alfakit\Forms\Components`, `Tables\Columns`, `Infolists\Components`, `Schemas\Components`, `Widgets`, `Pages`; `$type` / `$component` = the class name) and a starter component in the matching folder (`fields/RatingInput.tsx`, `columns/ProgressBar.vue`, …). The adapter comes from `package.json` (or `--adapter=react|vue|svelte`). With `discover` in `app.*` (starter kit apps: in `createStarterKitPage()` / `createStarterKitLayout()` of `resources/js/pages/Alfakit/Page.*`, glob `'../../alfakit/{fields,…}/**/*.tsx'`) nothing else changes. Otherwise they add the component to `createAlfakit({ components })` (`pages` for pages, also in `ssr.*`) and print how to turn `discover` on. `--no-frontend` writes only the PHP class. `useField()` accepts any field schema, so custom fields need no casts.

`make:alfakit-schema-component ShippingPanel` writes a layout component (extends `AlfaSix\Alfakit\Schemas\Components\Component`, children through `->schema([...])`).

`make:alfakit-field Volume --from-shadcn=slider` wraps a shadcn component installed in `resources/js/components/ui` (`@/components/ui/slider`). Ready-made bindings exist for `input`, `textarea`, `switch`, `checkbox`, `slider`, `calendar` (state `Y-m-d`) and `input-otp`; other names get a generic binding to finish by hand. React binds the component's value/change props, Vue uses `:model-value` + `@update:model-value`, and Svelte uses function bindings `bind:value={() => field.value, (v) => field.setValue(v)}`. The PHP class gets the matching rule (`numeric`, `date`, `boolean`, `string`). It warns when the component isn't installed. Any installed shadcn component can be imported in an AlfaKit component. Add `@source '../js/components/ui';` to `resources/css/alfakit.css` (new installs have it) so Tailwind scans it.

## Typed props

`php artisan alfakit:types [--path=app] [--output=resources/js/alfakit/types.d.ts] [--adapter=]` reads the PHPDoc array shapes (`@return array{swatches: list<string>, size?: int|null}`, `@phpstan-return` wins) of your classes' `getSchemaProps()` (fields, entries, layout components; a column's `column`; a widget's `widget`), `getCellProps()` (a column's `cell`) and `getViewData()` (a `$component` widget's props). It writes `export type ColorWheelProps = FieldProps<{ swatches: string[]; size?: number | null }>`, `ColorWheelSchema` (the `component` / `column` / `widget` object) and an `AlfakitComponents` map keyed by registry key. Supported types: shapes, `list<T>`, `array<K, V>`, `T[]`, scalars, literals, `?T` and unions; anything else becomes `unknown`, and a method without a shape gives `Record<string, unknown>`. Subclasses of built-in types and plugin classes that add no props are skipped. Use it like this: `export default function ColorWheel({ component }: ColorWheelProps)` (React), `let { component }: ColorWheelProps = $props()` (Svelte), `defineProps<{ component: ColorWheelSchema }>()` (Vue needs a literal object type). Hand-written equivalents come from every renderer: `FieldProps<T>`, `EntryProps<T>`, `SchemaComponentProps<T>`, `ColumnProps<TColumn, TCell>`, `WidgetProps<TProps, TWidget>`, `PageComponentProps<T>` and `BlockProps<TData>`.

## Layout components and schema context

- `<SchemaChildren component={component} />` (Vue `:component="props.component"`, Svelte `{component}`) renders a custom component's `->schema([...])` children in its grid (`columns`, `dense`, `gap`).
- `useSchemaComponentContext(component)` returns `{ get(path, isAbsolute?), state, operation, recordKey, record, data }`: `get('../title')` reads another component's live form state like PHP's `$get()`, from the component's container (the server sends it as `context.basePath` with `exposeContext()`; otherwise a field resolves from its own path) (`state` is `null` outside a form). `operation`/`recordKey`/`record`/`data` are only sent when the PHP component calls `->exposeContext(record: ['name'], data: fn (?Post $record) => [...])` (or sets `protected bool|Closure $exposesContext = true`); only listed attributes leave the server. React returns the value, Vue a computed ref (pass a getter `() => props.component`), Svelte `{ current }` (pass a getter).
- `<MixedContent items={...} name="data-slot-name" />` renders a list of `{ component } | { action }` items (section `afterHeader`, callout `controls`) with page actions.

## Eject a built-in component

To change a built-in renderer rather than write one from scratch, `php artisan alfakit:eject TextInput` (also `column:TextColumn`, `widget:Chart`, `layout:app`, `panel:sidebar`, `panel:topbar`…) copies the package's source for that key into `resources/js/alfakit/components/`, rewrites its imports to `@alfakit/<adapter>` (private helpers it needs are copied under `components/_alfakit/`) and registers the copy in `app.*` and `ssr.*`. Idempotent; `--force` overwrites. The adapter comes from `package.json` or `--adapter=`. Keep the `data-slot` attributes so theme CSS still applies.

## Translatable fields

`->translatable()` fields (`TextInput`, `Textarea`, `RichEditor`, or a custom `Field` using `AlfaSix\Alfakit\Forms\Components\Concerns\CanBeTranslatable`) carry `translatable: { locales, defaultLocale }`. Every renderer wraps the registered component in a language switcher and renders it with `translatableLocaleField(component, locale)` from `@alfakit/core` (state path `{statePath}.{code}`, `lang`/`dir` in `extraInputAttributes`), so a custom field component needs nothing special: it only has to bind `component.statePath` through `useField()` and spread `extraInputAttributes` on its control.

## A custom field (PHP + React)

```php
// app/Alfakit/Forms/Components/MapPicker.php: type `MapPicker` (the class name)
namespace App\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Field;

class MapPicker extends Field
{
    protected int $zoom = 12;

    public function zoom(int $zoom): static { $this->zoom = $zoom; return $this; }

    /** @return array{zoom: int} */
    protected function getSchemaProps(): array { return ['zoom' => $this->zoom]; }

    protected function getTypeValidationRules(): array { return ['array']; }
}
```

```tsx
// resources/js/alfakit/fields/MapPicker.tsx: auto-registered as `MapPicker`
import { FieldWrapper, useField, useT, type FieldProps } from '@alfakit/react'

export default function MapPicker({ component }: FieldProps<{ zoom: number }>) {
  const { value, setValue, error, id, disabled, aria } = useField<{
    lat: number
    lng: number
  } | null>(component)
  const t = useT()

  return (
    <FieldWrapper field={component} htmlFor={id} error={error}>
      <Map
        id={id}
        value={value}
        zoom={component.zoom}
        disabled={disabled}
        onChange={setValue}
        aria-label={t('maps.pick', {}, 'Pick a location')}
        {...aria}
      />
    </FieldWrapper>
  )
}
```

The field validates, hydrates and saves in PHP like any built-in field. `useField` also returns `readOnly` (the PHP `readOnly()` flag): show the value but block changes. Fields are not disabled during a live refresh; the store keeps edits typed while it is in flight. Vue: `useField(() => props.component)` returns refs. Svelte: `useField(() => component)` returns getters. `aria` holds `aria-describedby` (the hint, helper text and error `FieldWrapper` renders, ids from `fieldIds(id)` in `@alfakit/core`), `aria-invalid` and `aria-required`: spread it on the control (`v-bind="aria"` in Vue, `{...field.aria}` in Svelte); use `withoutRequired(aria)` on a button-like trigger. Links that open a new tab add the translated `opens_in_new_tab` text for screen readers.

Every built-in field is registered under its PHP type, so `createAlfakit({ components: { CodeEditor: MyMonacoEditor } })` swaps one renderer (the same keys exist in React, Vue and Svelte: `Builder`, `ToggleButtons`, `Slider`, `CodeEditor`, `ModalTableSelect`, `OneTimeCodeInput`, …). The built-ins emit stable hooks for CSS: `data-slot="builder"` (items carry `data-block="<name>"`; grouped picker headings sit in `data-slot="builder-block-group"`), `"one-time-code-input"`, `"item-move-status"` (the live region of move up/down buttons, which carry `data-item-move="up|down"`), `"toggle-buttons"`, `"slider"`, `"code-editor"` and `"modal-table-select"`. Entries and layout components work the same way: `CodeEntry` renders `data-slot="code-entry"` (with `data-light-theme` / `data-dark-theme`) and the `Actions` schema component `data-slot="schema-actions"`. `@alfakit/core` exports their framework-agnostic helpers (`builderItemSchema()`, `insertBuilderItem()`, `groupBuilderBlocks()`, `ItemMoveTracker`, `oneTimeCodeDigits()`/`OneTimeCodeSubmitter`, `scheduleAutofocus()` (refocus after "create another", `OpenModal.refocus`), `sliderPips()`, `highlightCode()`, `indentCode()`, `FormStore.modalTable()`) for custom renderers.

Fields and entries may carry `prefixActions`, `suffixActions` and `hintActions`; `FieldWrapper` and the entry wrapper render them (`data-slot="field-affix-actions"` around the control). They also render the content slots in `component.slots` (`{ aboveLabel: { items: [{ component } | { action }], alignment } }`) as `data-slot="field-content-slot"` rows with `data-content-slot="<name>"` (before/after content sit in `data-slot="field-content"` / `"entry-content"`), and merge `extraFieldWrapperAttributes` / `extraEntryWrapperAttributes` into the wrapper with `mergeAttributes()` from `@alfakit/core` (also `slotAlignmentClass()`, `hasSlots()`, `timeInputStep()`, `htmlLabelToText()`, `collectFieldStatePaths()`). The `FusedGroup` registry key renders `data-slot="fused-group"` with the fields in `data-slot="fused-group-controls"` (styled by `themes.css`, `data-invalid` with an error); fields inside render `data-fused` and hide their label visually. In a custom wrapper, build their context with `fieldActionContext({ token, form, key: component.key })` from `@alfakit/core` and pass it to `ActionList` (the runner posts the form state and merges the refreshed form). A custom submit reads the state through `form.whenSettled(() => runner.execute(action, context, form.state.values))` (or `await form.settle()`), so a live refresh started by the click (a `live(onBlur: true)` field losing focus) lands first. A live refresh can patch only some components (`applySchemaPatches()`): unchanged component objects keep their identity, so memoised renderers skip them. Rich editor extensions use the shared `mentionNodeConfig()`, `mergeTagNodeConfig()`, `customBlockNodeConfig()`, `EditorSuggestions` and `RichEditorRemote`; the toolbar uses `TOOLBAR_COMMANDS` (what each button does; renderers only map icons), `toolbarButtonLabel()`, `showsTableToolbar()`/`TABLE_TOOLBAR_BUTTONS`, `setTextColor()`, `fileAttachmentError()`, `htmlToMarkdown()` and the `highlightMarkConfig()`, `subscriptMarkConfig()`, `superscriptMarkConfig()`, `textColorMarkConfig()`, `textAlignExtensionConfig()`, `detailsNodeConfigs()` configurations (tables come from `@tiptap/extension-table`'s `TableKit`; hooks `data-slot="rich-editor-text-colors"`, `"rich-editor-table-toolbar"`); the layout uses `allToolbarButtons()`, `isToolbarGroup()`/`groupTriggerButton()` (`group:{n}` entries of `toolbarButtonGroups`), `toolbarCommands()`/`commandLabel()` (built-ins + plugin `editorTools` + commands of `loadRichEditorExtensions(component.extensions)`, which must resolve before the editor is created), `resolveFloatingToolbar()`/`floatingToolbarStyle()`, `resolveStickyOffset()`, `toolbarPanel()`/`groupCustomBlocks()`, `attachToolbarKeyboard()`/`focusToolbar()`/`isToolbarShortcut()` (roving tabindex, arrows, Escape, Alt+F10 on every `role="toolbar"`), `linkExtensionOptions(component.linkProtocols)` for Tiptap's `Link`, `RichEditorRemote.loadPreview()` (`{ html, prose }`; previews get `alfakit-not-prose` unless `prose`) and `resizableImageOptions()`/`resizableImageAttributes()` (hooks `data-slot="rich-editor-toolbar"`, `"rich-editor-floating-toolbar"`, `"rich-editor-panel"`); plugin Tiptap extensions are registered with `createAlfakit({ richEditorExtensions: { key: () => import('./ext') } })` (`registerRichEditorExtensions()`); the FileUpload image editor uses `ImageEditor`, `loadEditorImage()`, `canEditImage()` and `filesFromClipboard()` (hooks `data-slot="image-editor"`, `"image-editor-crop"`); table repeaters use `tableColumnCells()` (hooks `data-slot="repeater-table"`, `"repeatable-entry-table"`, `"rich-editor-suggestions"`, `"rich-editor-custom-block"`).
Tables emit `data-stacked-on-mobile` (`stackedOnMobile()`, classes from `STACKED_TABLE_CLASSES` / `stackedCellClasses()`), `data-slot="table-filters-layout"` and `"table-filters-sidebar"` (sidebar filter layouts; `filtersPlacement()`, `filtersFormWidthClass()`). Selection limits live in `TableSelection` (`configure(table)`, `canSelect()`, `selectGroup()`, `groupState()`; `canSelectAllRecords(table)`), record actions that `accessSelectedRecords` get `selectedRecordsArguments(selection, actions)`, and combobox `SelectColumn`s use `filterSelectColumnOptions()` / `searchTableColumnOptions()` / `selectColumnEmptyMessage()`. Reorder mode: `startTableReordering(table)` / `stopTableReordering(table)` (reload with `?reordering=1` unless `table.paginatedWhileReordering`; `table.reordering` when the server lists every record) and `reorderTable(endpoints, token, component, order, { reload })`. Toolbar buttons restyled on the server arrive in `table.triggerActions` and render through `triggerButtonView(table, key, defaults)` (`data-slot="table-trigger"`); other toolbar helpers from `@alfakit/core`: `tableLoadingStore` / `isTableLoading()` / `skeletonRowCount()` (`[data-slot="table-loading-skeleton"]`), `extremePageLinks()`, `limitedListItems()` (`[data-slot="list-expand"]`), `stackedImageStyle()` / `usesDefaultImageStack()`, `removeFilterIndicator()` / `removeAllFilterIndicators()`, `toggleVisibleColumn()` / `columnDraftChanged()` / `defaultVisibleColumns()`. Hooks: `[data-slot="table-grouping-inline"]`, `"filters-remove-all"`, `"pagination-first|last"`, `"input-prefix-icon|input-suffix-icon"`. Saved views (`table.views`): `TableViews` renders them (`data-slot="table-views"`, `"table-views-tabs"`, `"table-views-trigger"`, `"table-views-modified"`, slots `table.views.before|after`); helpers `tableViewUrl()` / `visitTableView(table, key)` (drop the replaced state keys, set `?view=`), `groupTableViews()`, `activeTableView()`, `requestTableView(endpoints.tableViews, token, component, { operation, view, name, shared })` (the server reads the state from `window.location.search`) and `tableViewFollowUp()`. Spreadsheet mode (`table.spreadsheet`): the lazy `Spreadsheet` component (`data-slot="spreadsheet"`, `"spreadsheet-toolbar"`, `"spreadsheet-grid"`, `"spreadsheet-cell"` with `data-dirty` / `data-active`, `"spreadsheet-errors"`, `"spreadsheet-paste"`, toolbar `"spreadsheet-trigger"`) drives `SpreadsheetGrid` from `@alfakit/core` (`spreadsheetColumns(table)`, `spreadsheetRows(records, columns)`, `handleKey(event, direction)` returns the command, `copyText()`, `planPaste(tsv)`, `apply()`, `fillDown()`, `undo()`, `changes()`, `applySaveResult()`, `undoSaveChanges()`), `parseTsv()` / `toTsv()`, `coerceSpreadsheetValue()` and `saveSpreadsheetChanges(endpoints.tableCells, token, component, changes, { dryRun })`.

## Custom columns, widgets and filters

Built-in renderers look up the registry by schema type first, so you can add new types or override built-in ones:

| Registry key                                         | Replaces                                                                                          | Props                                                               |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `column:<type>`                                      | The cell of every column with that `type` (`column:TextColumn`, or your own)                      | `{ column, cell, recordKey, token, component }`                     |
| `ViewColumn::make('status')->view('StatusSwitcher')` | One column's cells, by registered name                                                            | `{ state, ...viewData, column, cell, recordKey, token, component }` |
| `ViewEntry::make('events')->view('OrderTimeline')`   | One infolist entry, by registered name                                                            | `{ state, ...viewData, component }`                                 |
| `widget:<type>`                                      | Every widget of that type (`widget:Chart`, `widget:StatsOverviewWidget`, `widget:TableWidget`)    | `{ widget }`                                                        |
| `Widget::$component`                                 | One widget class; works for `View` widgets and for `ChartWidget`/`StatsOverviewWidget` subclasses | `{ widget }` (+ `getViewData()` for `View`)                         |
| `widget:calendar`                                    | The calendar widget of `alfasix/alfakit-calendar` (`CalendarWidgetSchema`, FullCalendar, lazy)    | `{ widget }`                                                        |
| `tree`                                               | The tree of `alfasix/alfakit-tree` (`TreePage` component, `TreeWidget`), lazy                     | `{ component }` on pages, `{ widget }` (with `widget.tree`)         |

```php
namespace App\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Tables\Columns\Column;

class ProgressColumn extends Column
{
    // rendered by columns/ProgressColumn.tsx ('column:ProgressColumn'); set $type to share a renderer
}
```

```php
namespace App\Alfakit\Widgets;

use AlfaSix\Alfakit\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected static ?string $component = 'revenue-chart'; // gets the full chart schema as `widget`
}
```

`viewData()` on `ViewColumn` / `ViewEntry` may be a closure; it is evaluated on the server per record (`$record`, `$state`), so compute everything the component needs there. An unregistered name renders a translated "Component … is not registered." notice.

Navigation items may carry `labelHtml: true` (their `label` is sanitised HTML; render it as markup) and `extraAttributes` (spread on the item's link with the `extraProps`-style helper so `class` merges); tenants `nameHtml: true`. Use `navigationItemText(item)` / `tenantNameText(tenant)` from `@alfakit/core` for tooltips, `alt` and search. The global search palette clears its term and results on close and on Inertia `navigate`, and announces `globalSearchStatus(query, loading, groups, { t, choice })` in a `role="status" aria-live="polite"` region. Relation group tabs with `badgeDeferred` take their badge from the `relationGroupBadge:{key}` deferred target (`resolveDeferredGroupBadge()`), and `relationManagerTabs()` follows `block.tabOrder` (`getCombinedTabComponents()`).

Chart widgets emit `data-slot="chart-filters-trigger"` / `"chart-filters"` (the `filtersSchema()` popover; `chartFiltersUrl()` writes `chartFilters[{id}][field]`) and `"chart-empty-state"`; `buildChartConfig({ ..., color })` applies `$color`. List tabs render `extraAttributes` and fill `deferBadge()` badges from the `alfakitTabBadges` deferred prop with `withDeferredTabBadges(tabs, props.alfakitTabBadges)`. List tabs use a roving tabindex: `tabKeyTarget(key, index, count, dir)` maps Arrow/Home/End keys (RTL-aware) to the tab to focus, and `listTabIds(base, key)` gives the tab and `role="tabpanel"` ids (`data-slot="list-tabs-panel"` wraps the table). The spinner (`data-slot="spinner"`, `role="status"`) draws the `alfakit::support.loading-indicator` icon alias, so `Icons::register(['alfakit::support.loading-indicator' => ...])` replaces it. Wizards apply a server `goToStep` request with `wizardRequestedStepIndex(steps, component.goToStep, current, skippable)`. Page forms publish their submit with `registerFormSubmitter(component, submit)`, so `ActionRunner.trigger()` sends an action with `formId` through that form (`submitFormById()`); `alfakitFormRefresh` flashes (`refreshFormData()`) are applied with `formRefreshFor()` + `applyFormRefresh(store, state)`, and wizard pages hide the form actions until the last step (`formActionsVisible(block, wizardProgressStore(store).get())`). The page's `defaultAction` (`$defaultAction`, `?tableAction=`) is opened once per page by `openDefaultAction(page, opener, trigger)` (React also exports `useDefaultAction(page)`).

Command palette 2.0: `GlobalSearch` renders the palette whenever `alfakit.commandPalette` or `alfakit.globalSearch` is set. Only the trigger and its key bindings load with the panel: the dialog (`GlobalSearchPalette`) loads the first time it opens (a `mod+k` pressed earlier opens it once loaded; hover/focus on the trigger preloads it), and `KeyboardShortcuts` loads `ShortcutsOverlay` the first time `?` opens it, so neither renders on the server. Build its local items with `commandPaletteItems({ panel, page, loaded, labels, shortcutsOverlay })` (page header actions with `{ token, component: 'page' }`, panel commands with `{ token, component: COMMANDS_COMPONENT }`, navigation, create links and recent records from the `endpoints.commandPalette` response, theme and help items), filter them with `filterCommandPalette(items, query)` and translate built-in group headings with `t(paletteGroupKey(group.id))`; run `action` items with `runner.trigger(item.action, item.context)`, `url` items with `router.visit`, `theme` items with `setAppearance()`. Keyboard shortcuts go through the shared registry: `shortcuts.register({ id, keys, label, group, handler, essential? })` in an effect (never during a server render; it returns a disposer); `SHORTCUT_IDS` and `commandShortcutId(name)` are the ids users customise; a handler returning `false` keeps the key's default. `KeyboardShortcuts` (mounted in the app layout's page shell) registers `/`, `g d`, `?` and command shortcuts, applies `alfakit.keyboardShortcuts.overrides` and renders `ShortcutsOverlay` (`data-slot="shortcuts-overlay"`, saves with `saveShortcutOverrides(endpoints.shortcuts, { [id]: keys | null })`). Shortcut hints render with `ShortcutKeys` after hydration. The History slide-over's content is the `RecordVersions` registry key (`data-slot="record-versions"` / `"record-version"` / `"record-version-change"` / `"record-version-diff"`); its restore button calls `runner.trigger(component.restoreAction, { token, component: component.actionComponent ?? 'page', parents: component.actionPath, arguments: { version: id } })`.

A registered widget component only receives the full schema: while a lazy widget (`Widget::$isLazy`, on by default) is loading, the grid renders the built-in `LazyPlaceholder` skeleton (`data-slot="lazy-placeholder"`) in its cell instead.

Subclasses of built-in columns (`StatusColumn extends IconColumn`) keep the built-in type unless they set `$type`. Filters render their `getFormComponents()`, so a custom filter UI is a custom field type registered like any other field.

## Hooks and helpers

| Hook                                                    | Use                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `useT()` / `useChoice()`                                | Translations (`t('key', replacements, fallback)`, `choice('key', count)`); share app groups with `$panel->frontendTranslations([...])`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `useFormatter()`                                        | `money()`, `number()`, `date()`, `relative()`, `list()`, `parseNumber()` in the user's locale, currency and timezone (Svelte: `format.current.money()`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `useLocalization()` / `useDirection()`                  | Language, `'ltr'`/`'rtl'`, currency, timezone                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `usePanel()` / `usePageSchema()`                        | Shared panel props and the current page                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `useActionRunner()`                                     | Trigger server actions from your component; `runner.trigger(action, { token, component, parents: ['edit'] })` runs an action registered in the `edit` modal (`registerModalActions()`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `Slot`                                                  | Render a named slot in your own layout: `<Slot name="sidebar.footer" />`; in a component belonging to a relation manager or table widget pass its key so content scoped to it shows: `<Slot name="table.toolbar.end" scope={table.component} />` (`slotItems(slots, name, scope)` from `@alfakit/core` filters the same way)                                                                                                                                                                                                                                                                                                                                                                                                           |
| `useHydrated()`                                         | `false` on the server and during hydration, then `true`: gate browser-only output (Vue: a ref, Svelte: `.current`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `pollComponent(target, interval)`                       | From `@alfakit/core`: poll one component (`'table'`, `'relationManager:…'`, `widgetPollTarget(widget)`); returns an unsubscribe                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `subscribeToNotifications({ channel, onNotification })` | From `@alfakit/core`: listen on `usePanel().realtime.channel` with Laravel Echo (`window.Echo`, `createAlfakit({ echo })` or `resolveEcho()`; call it in an effect; returns the unsubscribe). `onDatabaseNotificationsSent(cb)` fires when a stored notification is announced. Real-time collaboration (`Panel::realtime()`): `listenForRecordChanges(channel, (change) => …)` (`{event, model, key, by, at}`), `new PresenceRoom({ channel, state, self }).start()` (`store` lists other members), `useLiveUpdates(live, target, { polling, records })` (React; Vue/Svelte take a getter) with `<LiveRecordsPill>`; replace the built-ins with registry keys `page:presence` (`{ presence }`) and `page:conflict-banner` (`{ page }`) |
| `closeNotification(id)`                                 | From `@alfakit/core`: close the toast with that notification id (`onCloseNotification(cb)` listens; Filament's `close-notification` window event works too)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `bindUndo(id, notification.undo, { onTick, onClose })`  | From `@alfakit/core`: wire an "Undo" button in a custom toast (`notification.undo` from `Action::undoable()`): countdown, Ctrl/Cmd+Z via `undoShortcuts`, single request with `performUndo()`. `DashboardEditor` drives a custom customizable-dashboard grid (`block.customize`)                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `registerChartFormatters()` / `registerChartPlugins()`  | From `@alfakit/core` (or `createAlfakit({ chartFormatters, chartPlugins })`): functions that `'js:name'` strings in `ChartWidget::getOptions()` resolve to (looked up, never evaluated; unknown names are dropped with a console warning), and Chart.js plugins registered for every chart                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `openDatabaseNotifications()`                           | From `@alfakit/core`: open the database notifications panel (`onOpenDatabaseNotifications(cb)` listens); `NotificationsMenu` takes `variant="sidebar"` for a full-width trigger. Modal hooks for custom modal UIs: `runner.registerModalForm()`, `runner.cancelModal()`, `isModalHiddenByChild()`, `modalFooterAlignment()`, `slideOverSide(position, direction)`                                                                                                                                                                                                                                                                                                                                                                      |

A custom widget that polls should call `pollComponent(widgetPollTarget(widget), widget.pollingInterval)` in an effect (and return its unsubscribe) rather than `router.reload()`: it reloads only that widget's schema (the `alfakitPoll` prop) and shares one request with other components on the same interval. Load heavy libraries inside your component with a dynamic `import()` (as the built-in chart and rich editor do) so they stay out of the main bundle. In Svelte, `<Icon name="…">` loads any Lucide icon on demand; `registerIcons()` is only for non-Lucide icons.

## App pages (custom page components)

A Page with `protected static ?string $component = 'Pages/Pos'` renders your component as the Inertia page; its props are `getViewData()` plus `page` (the schema). Build any screen (POS, kanban, calendar, inbox, map, builder) with these exports of `@alfakit/react` (Vue and Svelte export the same names; Vue returns refs, Svelte objects with reactive getters):

```tsx
import {
  AppPage,
  PageForm,
  PageTable,
  useFormatter,
  useLiveProps,
  usePageMethod,
  usePageMethods,
  useShortcut,
} from '@alfakit/react'

export default function Pos({ products }: PosProps) {
  const checkout = usePageMethod<Sale, { items: Line[] }>('checkout', { forms: ['sale'] }) // #[Expose] public function checkout(array $items)
  const move = usePageMethod('moveOrder', {
    optimistic: (args) => {
      const before = cards
      setCards(next)
      return () => setCards(before)
    },
  })
  useLiveProps(['recentSales'], { poll: '60s' }) // or { echo: { channel, events: ['.OrderPaid'] } }
  useShortcut('f2', () => void checkout.call({ items }), { allowInInputs: true })

  return (
    <AppPage>
      {' '}
      {/* page layout, header actions, <title> */}
      <PageForm name="sale" /> {/* public function saleForm(Schema $schema): Schema */}
      <PageTable name="products" /> {/* public function productsTable(Table $table): Table */}
      <Button disabled={checkout.loading} onClick={() => checkout.call({ items })}>
        Charge
      </Button>
      {checkout.errors.items}
    </AppPage>
  )
}
```

- `usePageMethod(name, { forms, optimistic, toasts, followRedirects, onSuccess, onError })` → `call()` (result or `undefined` on failure), `callOrThrow()` (rejects with `PageMethodError`: `.errors`, `.notifications`, `.status`, `.isValidation`), `loading`, `result`, `error`, `errors` (first message per key), `reset()`. Toasts, redirects and `reloadProps()` are applied automatically. `File` values in the arguments are uploaded first and arrive as `TemporaryUpload` parameters.
- `usePageMethods<{ checkout: { args: {...}; result: Sale } }>()` → `call(name, args, options)`, `callOrThrow`, `isLoading(name)`, `errors[name]`.
- `PageForm` / `PageTable` / `PageInfolist` (`name` optional: the page's own `form()`/`table()`/`infolist()`), `PageWidgets` (`position="footer"`), `PageBlock name="any block key"` (`fallback`). They behave as on schema pages (live fields, field actions, uploads, table actions, polling). `usePageForm('sale')` → `{ store, values, errors }`.
- `useReloadProps(['cart'])` → `{ reload, reloading }`; `useLiveProps(props, { poll, echo })`; `useShortcut(keys, handler, { allowInInputs, preventDefault, enabled })`.
- Framework-free: `callPageMethod()`, `runPageMethod()`, `optimistic(apply, run)`, `registerShortcut()`, `watchPageProps()`, `reloadPageProps()` from `@alfakit/core`.
- `$layout = 'fullscreen'` renders `layout:fullscreen` (`FullscreenLayout`, replaceable): no sidebar or topbar, a slim `data-slot="topbar"` header (brand, title, slots, notifications, user menu, exit link) unless `$hasFullscreenHeader = false`; the component fills `main[data-slot="content"]`.
- UI primitives (`Button`, `Card`, `Input`, `Dialog`, `Sheet`, `Badge`, `Tabs`, `Table`, `DropdownMenu`, `Spinner`, …) and `Icon` are exported by every renderer. Keep component state presentational: prices, totals, stock and permissions come from PHP.

## Rules for custom components

- **No hardcoded English.** Every visible string and every `aria-label` goes through `t()` with an English fallback.
- **Logical CSS only:** `ms-*`/`me-*`, `ps-*`/`pe-*`, `start-*`/`end-*`, `text-start`, `border-s`. Add `rtl:rotate-180` to arrow icons.
- **Use theme tokens** (`bg-card`, `text-muted-foreground`, `border`, `bg-primary`) instead of fixed colours, so all six themes and dark mode work. Add `data-surface` to card-like containers to pick up the theme's surface style.
- **Format with `useFormatter()`.** Never use `toFixed()` or `toLocaleString()` for money or dates.
- **Spell Tailwind classes out in full.** Classes built at runtime aren't generated.
- **Server-render safely.** Components also render in Node when SSR is on: touch `window`, `document`, `localStorage` or `matchMedia` only in `useEffect`/`onMounted`/`$effect`, never during render or at import time, and render the same markup on the server and in the first browser render (`useHydrated()` for browser-only values, `useId()` for ids, never `Math.random()` or `Date.now()` in markup).
- **PHP extra attributes** (`extraAttributes(['class' => …])`) are merged into components by the renderer. In your own components, merge them with `extraProps(attributes, yourClasses)` (React) instead of spreading them over your classes.
- **Content Security Policy.** A `<style>` or `<script>` your component adds at runtime needs the page's nonce: `documentNonce()` from `@alfakit/core` (e.g. TipTap's `injectNonce: documentNonce()`). Prefer shipping CSS in your stylesheet.
