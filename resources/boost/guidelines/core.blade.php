## AlfaKit

AlfaKit (`alfasix/alfakit`) is a FilamentPHP-style admin panel builder that renders through Inertia with React, Vue or Svelte (`@alfakit/react|vue|svelte`) and shadcn components. Panels, resources, forms, tables, actions, infolists and widgets are defined in PHP; the frontend only renders the JSON schema PHP sends.

### Golden rules

- Build features in PHP. Never re-implement validation, queries, authorization or formatting in JavaScript; the renderer has no business logic.
- Namespaces: `AlfaSix\Alfakit\...` (e.g. `Forms\Components\TextInput`, `Tables\Columns\TextColumn`, `Tables\Table`, `Schemas\Schema`, `Schemas\Components\Section`, `Actions\Action`, `Resources\Resource`, `Panel`). This is not Filament: do not import `Filament\...`.
- Set up a new app with `php artisan alfakit:setup` (install mode, users resource, Shield roles, AI keys, Reverb realtime, plugins or `--bundle=saas|content|backoffice|support|ops` plugin sets, branding, demo data; every question has a flag, re-runs skip finished steps). `php artisan alfakit:users` generates a complete users resource (invitations, impersonation, suspend/ban, forced password change, 2FA reset, sessions); `alfakit:ai-setup` and `alfakit:realtime` configure laravel/ai keys and Reverb.
- Generate code with Artisan instead of writing boilerplate: `php artisan make:alfakit-resource Post --generate --view` (`--nested=PostResource` for a child resource under a post, `--singular` for a one-record settings resource), `make:alfakit-api-resource Supplier --url=https://erp.example.com/api` (an `ApiResource` over a REST API or other data source, no model), `make:alfakit-relation-manager PostResource comments title`, `make:alfakit-page Settings` (`--component [--fullscreen]` for an app page: your own React/Vue/Svelte component calling `#[Expose]` page methods and embedding `{name}Form()`/`{name}Table()` pieces), `make:alfakit-widget RevenueStats`, `make:alfakit-panel admin`, `make:alfakit-user`, `make:alfakit-importer Product --generate` / `make:alfakit-exporter Product --generate` (for `ImportAction` / `ExportAction`), `make:alfakit-theme acme --extends=alfakit`, `make:alfakit-field` / `make:alfakit-column` / `make:alfakit-entry` / `make:alfakit-schema-component` (PHP class + renderer component in the auto-registered `resources/js/alfakit/{fields,columns,entries,components}` folder; `make:alfakit-field Volume --from-shadcn=slider` wraps an installed shadcn component), `alfakit:types` (TypeScript props types from `@return array{…}` shapes), `make:alfakit-plugin Blog --vendor=acme`. `php artisan alfakit:generate Post` (or `make:alfakit-resource --wizard`) is the interactive resource wizard (pick columns, filters, infolist entries, relation managers; every choice also an option for CI). `php artisan alfakit:doctor` diagnoses the installation and prints fixes (`--json`, `--strict` for CI). After updating Alfakit run `php artisan alfakit:upgrade` (it reports plugin and `@alfakit/*` npm compatibility; `--strict` fails on an incompatible package). Converting an existing FilamentPHP app: `php artisan alfakit:migrate-from-filament [--dry-run]` rewrites `Filament\...` classes to `AlfaSix\Alfakit\...`, moves `app/Filament` to `app/Alfakit`, installs the frontend and scaffolds custom components for Livewire components, marks every manual step with `// TODO(alfakit-migration)` and writes `alfakit-migration-report.md` (grouped by file, with the `alfakit:add` command for the first-party plugins that replace Filament plugins).
- Resources live in `app/Alfakit/Resources`, pages in `app/Alfakit/Pages`, widgets in `app/Alfakit/Widgets`; the panel is configured in `app/Providers/Alfakit/AdminPanelProvider.php`. Discovery and the Vite manifest are cached in production (`php artisan optimize` runs `alfakit:optimize`, `optimize:clear` runs `alfakit:optimize-clear`; run `optimize` after each asset build); run `php artisan alfakit:optimize-clear` after adding classes if they don't appear. `Panel::configureUsing(fn (Panel $panel) => ...)` callbacks run after the provider's `panel()`, so `$panel->getId()` can target one panel.
- Any configuration value can be a closure. Closures receive `$record`, `$state`, `$get` (`Get`), `$set` (`Set`), `$operation` (`create`/`edit`/`view`), `$model`, `$data` and type-hinted services by name or type.
- A panel becomes an installable app with `$panel->pwa()` (manifest, service worker with offline page and update prompt, "Install app" item); `->pwa(push: true)` plus `php artisan alfakit:pwa-keys` also delivers database notifications as Web Push.
- Real-time collaboration is one panel option: `$panel->realtime()` (presence on record pages, edit-conflict banners, `Table::live()` / `Widget::live()`, instant bell) over Laravel broadcasting; set it up with `php artisan alfakit:realtime`. Edit pages refuse stale saves (`detectConflicts()`, on by default) even without it.
- Every UI string is translatable and every layout supports right-to-left; use `__()` for your labels and never hardcode English in custom frontend components.
- Built-in fields, tables and themes target WCAG 2.1 AA (described and invalid fields, keyboard row links and reordering, 4.5:1 text and 3:1 input borders in every theme). Custom fields should spread `useField()`'s `aria` on their control, and colours set with `->colors()` should keep that contrast.

### A resource

@verbatim
<code-snippet name="Resource with form, table and actions" lang="php">
namespace App\Alfakit\Resources;

use AlfaSix\Alfakit\Actions\{DeleteAction, DeleteBulkAction, EditAction};
use AlfaSix\Alfakit\Forms\Components\{Select, TextInput, Toggle};
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\{TextColumn, ToggleColumn};
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use App\Alfakit\Resources\PostResource\Pages;
use App\Models\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;
    protected static ?string $navigationIcon = 'file-text'; // Lucide icon name
    protected static ?string $navigationGroup = 'Blog';
    protected static ?string $recordTitleAttribute = 'title'; // without it records are titled by the model label ("Edit Post")

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->schema([
                TextInput::make('title')->required()->maxLength(255),
                Select::make('author_id')->relationship('author', 'name')->searchable()->preload()->required(),
                Toggle::make('is_featured'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('author.name'),           // label "Author"
                ToggleColumn::make('is_featured'),
            ])
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
</code-snippet>
@endverbatim

- Labels, URLs and plurals are generated from names (`author_id` → "Author", `Category` → "categories", URL `/categories`). Translate model names in `lang/{locale}/models.php` (`'category' => 'Kategorie|Kategorien'`), not by changing slugs.
- Server-side reactivity: `->live()` re-evaluates the form on the server (the response carries only the changed components); use `->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state)))` and `->visible(fn (Get $get) => $get('type') === 'paid')`. Fields and entries take their own actions (`->suffixAction(Action::make(...))`, `prefixActions()`, `hintActions()`) whose `$get`/`$set` act on the form.

### Actions and notifications

@verbatim
<code-snippet name="Custom action with a modal form" lang="php">
use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Notifications\Notification;

Action::make('publish')
    ->icon('send')
    ->color('success')
    ->requiresConfirmation()
    ->schema([DateTimePicker::make('published_at')->required()])
    ->action(function (Post $record, array $data): void {
        $record->update(['published_at' => $data['published_at']]);

        Notification::make()->title(__('Post published'))->success()->send();
    });
</code-snippet>
@endverbatim

### Panels, design and localization

- Configure on the panel: `->theme('alfakit')` (`neutral`, `alfakit`, `minimal`, `soft`, `corporate`, `filament`), `->design(layout: 'topbar', nav: 'pill', inputs: 'filled')`, `->colors([...])` (or a closure per request; `Colors::register([...])` for every panel), `->font('Inter')`, `->slot(Slot::TOPBAR_END, fn () => view('…'))`, `->locales(['en', 'ar', 'fr'])` (`->languageSwitcher('topbar')` for a top bar language button), `->currency('EUR')`, `->timezone(fn () => auth()->user()?->timezone)`.
- Navigation: group pages and resources with clusters (`make:alfakit-cluster Settings`, then `protected static ?string $cluster = Settings::class;`), link a record's pages with `Resource::getRecordSubNavigation()` returning `$page->generateNavigationItems([...])`. Built-in icons are aliases you can change with `Icons::register(['alfakit::actions.delete' => 'trash'])`.
- Resource pages run Filament-style hooks (`beforeFill`, `afterFill`, `beforeValidate`, `afterValidate`, `beforeCreate`, `afterCreate`, `beforeSave`, `afterSave`, and trait variants like `afterSaveHasAuditLog()`); `$this->halt()` stops without saving. Public pages: `$panel->guestAccess()` plus `protected static bool $isGuestAccessible = true;` on the page or resource; policies get `?User`.
- Resource structure: list tabs with `getTabs()` returning `Tab::make()->modifyQueryUsing(fn (Builder $query) => ...)`, nested resources with `protected static ?string $parentResource = PostResource::class;`, `RelationGroup::make('Contacts', [...])` in `getRelations()`, `ManageRelatedRecords` pages for one relationship, `ManageSingularRecord` for settings-like resources. Actions support `->steps([...])` wizards, nested footer actions, `->rateLimit(5)`, `->requiresPasswordConfirmation()`, and bulk `->authorizeIndividualRecords('delete')` / `->chunkSelectedRecords(500)`.
- Command palette and history: panel-wide commands are actions registered with `$panel->commands([Command::make('clear-cache')->action(...)->keywords([...])->shortcut('mod+shift+x')])` (`AlfaSix\Alfakit\CommandPalette\Command`); the palette also lists page actions, navigation, create links and recent records. `use AlfaSix\Alfakit\Versions\HasRecordVersions;` on a model (publish `alfakit-record-versions-migrations`) gives its resource a History slide-over with diffs and policy-checked restore.
- Opt-in productivity features: `$table->savedViews()` (user / shared table views; `->views([TableView::make(...)])` in code), `->spreadsheet()` (bulk cell editing with per-cell validation), `DeleteAction::make()->undoable()` / `EditAction::make()->undoable()` (Undo in the toast), `$panel->customizableDashboards()` (users arrange dashboard widgets), keyboard shortcuts (`?` overlay; `->keyBindings()` on actions, `->shortcut()` on commands), `IconPicker::make('icon')` and Iconify sets (`php artisan alfakit:icons mdi`), and `$panel->devTools()` (schema inspector, on in local debug). Publish the matching `alfakit-*-migrations` tag where the feature stores data; `php artisan alfakit:doctor` reports missing ones.
- Emails: AlfaKit and plugin emails share a branded, RTL-aware, dark-mode theme (`config('alfakit.mail')`). Use `AlfaSix\Alfakit\Mail\MailMessage` in `toMail()` (or the `UsesAlfakitMailTheme` trait on Markdown mailables) for your own; register them with `Mails::register(MailDefinition::make('orders.shipped')->preview(fn () => ...))` for the `Pages\MailPreview` page and add `->editable(mergeTags: [...])` plus the `UsesEditableTemplate` trait so admins can edit them with `-mail-templates`.
- Money: `TextColumn::make('total')->money(fn (Order $r) => $r->currency, inMinorUnits: true)` and `MoneyInput::make('total')->currency('BHD')->minorUnits()`. Never format money or dates by hand.
- Custom frontend components register themselves: `createAlfakit({ discover: import.meta.glob('./{fields,columns,entries,widgets,pages,components,blocks}/**/*.tsx') })` (`.vue` / `.svelte`) in `resources/js/alfakit/app.(tsx|ts)` and `ssr.*` maps `fields/ColorWheel.tsx` to the type `ColorWheel` (the PHP class name), `columns/X` to `column:X`, `widgets/X` to `Widget::$component = 'X'`, `pages/X` to `Pages/X`, `blocks/x` to `cms:block:x`, `components/map-picker` to `View::make('map-picker')`; explicit `createAlfakit({ components: { … } })` wins.
- Server-side rendering is opt-in (`php artisan alfakit:install --ssr`): `resources/js/alfakit/ssr.(tsx|ts)` calls `createServer(createAlfakitServer({ discover, components, pages }))` from `@alfakit/{react,vue,svelte}/server` with the same options as `createAlfakit()`, built with `vite build --ssr` and run with `php artisan inertia:start-ssr`.

### Outside panels

- Forms, tables, infolists, actions and widgets also work in the application's own Inertia pages without a panel: extend `AlfaSix\Alfakit\Standalone\Form` (`form()` + `submit(array $data)`), `Standalone\Table` (`table()`), `Standalone\Infolist`, `Standalone\Widgets` or `Standalone\Component` (any mix), generate them with `make:alfakit-form|table|infolist|component`, pass `ContactForm::make(['customer' => $customer])` to `Inertia::render()` as a prop and render it with `<AlfakitComponent component={prop} />` from `@alfakit/react|vue|svelte` (`<AlfakitToaster />` once in the layout for notifications; `@import '@alfakit/react/standalone.css'` in the app stylesheet). Validation, authorisation, uploads and tokens work as in panels. `Alfakit::render('page', ['orders' => OrdersTable::class])` builds them for you; `make:alfakit-page Orders --standalone` generates the class and the page. `php artisan alfakit:auth` adds AlfaKit sign-in, registration, password reset, two-step verification and an account page for the app's own users (`AuthKit::configure()`, `AuthKit::routes()`). Customer-facing panels: `->portal()->invitationOnlyRegistration()->scopeToUser('customer_id')`.

### Inside a Laravel starter kit

- In an app made from Laravel's React, Vue or Svelte starter kit, `php artisan alfakit:install --starter-kit` adds a panel with `->starterKit()`: it uses the app's Fortify login, renders inside the app's `AppLayout`, lists its navigation in the app sidebar (`useAlfakitNavigation()`, generated `alfakit-nav` component), adds the panel's `userMenuItems()` links (Billing's entry too) to the app's user menu (`<AlfakitUserMenuItems />`, generated `alfakit-user-menu-items` component), puts AlfaKit's search, notifications bell, tenant switcher, AI copilot and language switcher in the app header (`<AlfakitSearch />`, `<AlfakitNotifications />`, `<AlfakitTenantSwitcher />`, `<AlfakitCopilot />`, `<AlfakitLanguageSwitcher />`: the panel's `->locales()` menu, since the panel's own top bar and user menu are not rendered; it renders nothing with fewer than two locales, and re-running `alfakit:install --starter-kit --no-npm --panel=<id>` adds it to a header set up earlier), shows the panel's widgets (`make:alfakit-widget`, `app/Alfakit/Widgets`) on the app's own `/dashboard` (`<AlfakitWidgets />`, routes set by `->starterKitDashboard()`) and keeps the app's shadcn theme. Two-factor authentication is the kit's (Fortify); `->multiFactorAuthentication()` is ignored in this mode. Custom components are registered in `resources/js/pages/Alfakit/Page.*` (`createStarterKitPage({ layout: AppLayout, discover, components })`, Svelte `createStarterKitLayout()`; `discover: import.meta.glob('../../alfakit/{fields,columns,entries,widgets,pages,components,blocks}/**/*.tsx')` picks up what the generators write), not in `resources/js/alfakit/app.*`.

### First-party plugins

- Don't hand-build what a first-party plugin ships: `alfasix/alfakit-shield` (roles, permissions, generated policies), `-billing` (Stripe/Paddle/Spark subscriptions), `-media-library`, `-settings` (spatie/laravel-settings pages), `-tags`, `-activity-log`, `-backup`, `-health`, `-calendar` (FullCalendar widget), `-tree` (reorderable hierarchies), `-comments` (mentions, reactions), `-kanban`, `-forms` (public form builder), `-cms` (block pages, menus, SEO), `-api` (REST API, tokens, OpenAPI), `-webhooks`, `-ops` (failed jobs, queues, logs, scheduler), `-feature-flags` (Pennant), `-translation-manager`, `-ai` (AI actions, `->aiAssist()`, `Table::aiFilters()`, copilot, MCP), `-teams`, `-workflows` (state machines, approvals), `-invoices`, `-file-manager`, `-helpdesk`, `-analytics` (privacy-first web analytics) and `-mail-templates` (admins edit emails per language with merge tags). Install with `php artisan alfakit:add {slug}` (composer require, `$panel->plugin(...)` registration, migrations; `alfakit:remove {slug}` undoes it); namespaces are `AlfaSix\AlfakitShield\...`, `AlfaSix\AlfakitKanban\...` and so on. The `alfakit-plugins` skill has install steps, options and authorisation rules for each.

### Testing

Test panels with the page test helpers: `alfakit()->testPage(EditPost::class, ['record' => $post])->fillForm([...])->call('save')->assertHasNoFormErrors()`, plus `callAction()`, `callTableAction()`, `callTableBulkAction()`, `searchTable()`, `filterTable()`, `assertCanSeeTableRecords()`, `assertNotified()`; they drive the real Inertia pages and `/{panel}/_alfakit/*` endpoints (no Livewire). Full documentation: https://alfakit.dev/docs (also https://alfakit.dev/llms-full.txt).
