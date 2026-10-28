---
name: alfakit-plugins
description: Install and configure AlfaKit's first-party plugins — Shield (roles and permissions), Billing, Media Library, Settings, Tags, Activity log, Backup, Health, Calendar, Tree, Comments, Kanban, Forms (public form builder), CMS, API (REST + OpenAPI), Webhooks, Ops, Feature flags (Pennant), Translation manager, AI (actions, copilot, MCP), Teams, Workflows (state machines and approvals), Invoices, File manager, Helpdesk, Analytics and Mail templates. Use when a task needs one of them instead of building it by hand.
---

# AlfaKit first-party plugins

## When to use this skill

Use it before hand-building roles and permissions, subscription billing, media or tag fields, settings pages, an audit log, backup or health screens, a calendar, a drag-and-drop tree, comment threads, a kanban board, public forms, a CMS, a REST API, outgoing webhooks, queue / log / scheduler screens, feature flags, a translation editor, AI features, teams and invitations, approval workflows, invoices, a file manager or a helpdesk. Each of these ships as an `alfasix/alfakit-*` package built from ordinary AlfaKit resources, pages, widgets, fields and actions, so it renders in React, Vue and Svelte with no extra npm package. Plugin namespaces are `AlfaSix\Alfakit{Name}\...` (no backslash between `Alfakit` and the name), e.g. `AlfaSix\AlfakitShield\ShieldPlugin`.

## Choosing a plugin

| Need                                   | Package                               | Builds on                                       | Main entry point                                 |
| -------------------------------------- | ------------------------------------- | ----------------------------------------------- | ------------------------------------------------ |
| Roles, permissions, generated policies | `alfasix/alfakit-shield`              | spatie/laravel-permission                       | `ShieldPlugin`, `RoleResource`                   |
| Subscriptions for tenants or users     | `alfasix/alfakit-billing`             | laravel/cashier (Stripe), cashier-paddle, Spark | `BillingPlugin`, `Plan`, `StripeBillingProvider` |
| Files stored as model media            | `alfasix/alfakit-media-library`       | spatie/laravel-medialibrary                     | `SpatieMediaLibraryFileUpload`                   |
| A page that edits a settings class     | `alfasix/alfakit-settings`            | spatie/laravel-settings                         | `SettingsPage`, `make:alfakit-settings-page`     |
| Tags input, column, entry              | `alfasix/alfakit-tags`                | spatie/laravel-tags                             | `SpatieTagsInput`                                |
| Audit log, record history              | `alfasix/alfakit-activity-log`        | spatie/laravel-activitylog v5                   | `ActivityLogPlugin`, `ActivitiesRelationManager` |
| Create / download / delete backups     | `alfasix/alfakit-backup`              | spatie/laravel-backup                           | `BackupPlugin` (Backups page)                    |
| Health check results page and widget   | `alfasix/alfakit-health`              | spatie/laravel-health                           | `HealthPlugin` (Health page)                     |
| Month / week / day calendar of records | `alfasix/alfakit-calendar`            | FullCalendar (bundled in the renderers)         | `CalendarWidget`, `Event`                        |
| Nested, reorderable records            | `alfasix/alfakit-tree`                | a parent and an order column                    | `TreePage`, `TreeWidget`, `HasTreeStructure`     |
| Comments, mentions, reactions          | `alfasix/alfakit-comments`            | its own tables                                  | `CommentsEntry`, `CommentsAction`                |
| Kanban board of any model              | `alfasix/alfakit-kanban`              | a status and an order column                    | `KanbanPage`, `KanbanWidget`                     |
| Public forms built in the panel        | `alfasix/alfakit-forms`               | its own tables, standalone components           | `FormsPlugin`, `AlfakitForms::embed()`           |
| Block pages, menus, redirects, SEO     | `alfasix/alfakit-cms`                 | its own tables                                  | `CmsPlugin`, `CmsBlock`, `CmsPage`               |
| REST API, tokens, OpenAPI              | `alfasix/alfakit-api`                 | the resources' tables, forms, policies          | `ApiPlugin`, `ResourceApi`, `ExposesApi`         |
| Outgoing signed webhooks               | `alfasix/alfakit-webhooks`            | queues, Laravel HTTP client                     | `WebhooksPlugin`, `Webhooks::dispatch()`         |
| Failed jobs, queues, logs, scheduler   | `alfasix/alfakit-ops`                 | Laravel's queue, scheduler, logs                | `OpsPlugin`                                      |
| Feature flags, rollouts, kill switch   | `alfasix/alfakit-feature-flags`       | laravel/pennant                                 | `FeatureFlagsPlugin`, `FeatureFlags`             |
| Edit translations in the panel         | `alfasix/alfakit-translation-manager` | lang files + a database layer                   | `TranslationManagerPlugin`                       |
| AI actions, assist, copilot, MCP       | `alfasix/alfakit-ai`                  | laravel/ai (laravel/mcp for MCP)                | `AiPlugin`, `AiAction`, `->aiAssist()`           |
| Teams, members, invitations, seats     | `alfasix/alfakit-teams`               | panel tenancy                                   | `TeamsPlugin`, `HasTeams`                        |
| State machines and approvals           | `alfasix/alfakit-workflows`           | a state column                                  | `HasWorkflow`, `Workflow`, `WorkflowActions`     |
| Invoices, quotes, credit notes         | `alfasix/alfakit-invoices`            | its own tables, ext-intl, optional PDF lib      | `InvoicesPlugin`, `Invoice`                      |
| File manager and picker field          | `alfasix/alfakit-file-manager`        | Laravel filesystem disks                        | `FileManagerPlugin`, `FileManagerPicker`         |
| Tickets, SLAs, portal, live chat       | `alfasix/alfakit-helpdesk`            | its own tables                                  | `HelpdeskPlugin`, `Tickets`, `HelpdeskChat`      |

Install pattern: `php artisan alfakit:add {slug}` (or pick plugins in `alfakit:setup`) runs `composer require alfasix/alfakit-{slug}` after asking, registers `{Name}Plugin::make()` on the panel provider, publishes the plugin's `alfakit-{slug}-migrations` (and the Spatie migrations for activity log, media library, tags and settings) and offers to migrate; `alfakit:add --list` shows the catalogue (`alfakit:add --list {slug}` adds highlights, the live-demo link and related plugins, read from `extra.alfakit.showcase` in the plugin's composer.json: `demoPath`, `tour`, `clips`, `worksWith`, `highlights`), `alfakit:setup --bundle=saas|content|backoffice|support|ops` installs a curated set (SaaS: teams, billing, shield, feature-flags, analytics; content: cms, forms, media-library, analytics, translation-manager; back office: invoices, workflows, kanban, file-manager, activity-log; support: helpdesk, comments, ai; ops: ops, backup, health, webhooks) with navigation-group defaults, and `alfakit:remove {slug}` unregisters and removes a plugin (tables are never dropped). By hand: `composer require`, `vendor:publish --tag=alfakit-{slug}-migrations`, `migrate`, then `$panel->plugin(...)`. Each section below lists only the extra steps (underlying package set-up, generated pages, scheduled commands, model traits). Media Library, Tags, Settings (with discovered pages), Comments, Calendar, Tree and Kanban work without registering the plugin; register them anyway to declare the dependency, share frontend translations (Calendar, Tree, Kanban) and set panel-wide defaults. `{Name}Plugin::get()` returns the plugin of the current panel. Strings live in `alfakit-{slug}::*` translation namespaces.

## Shield

`composer require alfasix/alfakit-shield`, then `php artisan alfakit-shield:install --migrate` (`--teams` for tenant-scoped roles, `--generate`, `--panel=admin`). Add spatie's `HasRoles` to the user model.

```php
use AlfaSix\AlfakitShield\ShieldPlugin;

$panel->plugin(
    ShieldPlugin::make()
        ->navigationGroup('Settings')
        ->superAdmin(role: 'super_admin')          // gate: true → Gate::before() lets it pass everything
        ->panelUser(role: 'panel_user')            // with HasPanelShield on the user model
        ->customPermissions(['export_orders' => 'Export orders'])
        ->excludeResources([AuditResource::class]),
);
```

```bash
php artisan alfakit-shield:generate --panel=admin           # permissions + one policy per resource model
php artisan alfakit-shield:super-admin --user=1 --panel=admin
```

- Other options: `roleResource(MyRoleResource::class|false)`, `resourcePermissionPrefixes([...])` (default `ShieldPlugin::DEFAULT_RESOURCE_PREFIXES`: `view_any`, `view`, `create`, `update`, `delete`, `delete_any`, `restore`, `restore_any`, `force_delete`, `force_delete_any`, `replicate`, `reorder`), `pagePermissionPrefix()`, `widgetPermissionPrefix()`, `buildPermissionKeyUsing(fn (string $prefix, string $subject, string $entity, string $class) => ...)`, `excludePages()`, `excludeWidgets()`, `tabs(widgets: false)`, `gridColumns()`, `localizePermissionLabels()`, `guard('web')`, `scopeToTenant(false)`.
- Names: `{prefix}_{snake model}` for resources (`view_any_blog_post`), `{prefix}_{snake class}` for pages and widgets (`view_reports_page`).
- `generate` options: `--option=policies|permissions|policies_and_permissions`, `--resource=PostResource` (repeatable), `--force`. Policies go to `App\Policies` (`config/alfakit-shield.php`); publish stubs with `vendor:publish --tag=alfakit-shield-stubs`.
- Classes: `Traits\HasPanelShield` (user model; `canAccessPanel()`), `Traits\HasPageShield` (page `canAccess()`), `Traits\HasWidgetShield` (widget `canView()`), `Contracts\HasShieldPermissions` (per-resource prefixes), `Shield::userCan('export_orders')`, `Shield::isSuperAdmin($user)`.

```php
use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\AlfakitShield\Contracts\HasShieldPermissions;
use AlfaSix\AlfakitShield\ShieldPlugin;
use AlfaSix\AlfakitShield\Traits\HasPageShield;
use AlfaSix\AlfakitShield\Traits\HasPanelShield;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements CanAccessPanel
{
    use HasPanelShield;
    use HasRoles;
}

class PostResource extends Resource implements HasShieldPermissions
{
    public static function getPermissionPrefixes(): array
    {
        return [...ShieldPlugin::DEFAULT_RESOURCE_PREFIXES, 'publish'];   // → PostPolicy::publish()
    }
}

class Reports extends Page
{
    use HasPageShield; // requires view_reports
}
```

## Billing

`composer require alfasix/alfakit-billing`. Set up Cashier (migrations, `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET`, `php artisan cashier:webhook`) and put `Laravel\Cashier\Billable` on the paying model (the tenant on tenancy panels, otherwise the user; for a tenant call `Cashier::useCustomerModel(Team::class)`).

```php
use AlfaSix\AlfakitBilling\BillingPlugin;
use AlfaSix\AlfakitBilling\Plan;
use AlfaSix\AlfakitBilling\Providers\StripeBillingProvider;

$panel
    ->tenant(Team::class)
    ->plugin(
        BillingPlugin::make()
            ->provider(
                StripeBillingProvider::make()
                    ->plans([
                        Plan::make('basic')->price('price_basic')->amount(9, 'usd')->monthly()->features(['3 projects']),
                        Plan::make('pro')->price('price_pro')->label('Pro')->amount(29, 'usd')->monthly()->highlighted(),
                    ])
                    ->trialDays(14)
                    ->allowPromotionCodes(),
            )
            ->requiresSubscription(),
    );
```

- Routes: `/{tenant}/billing` (or `/billing`) goes to the portal when subscribed, else to the plans page; `?plan=pro` starts checkout. Plans page: `/{tenant}/billing/plans`.
- Plugin: `provider()`, `requiresSubscription()`, `checkoutPage(MyCheckout::class)` (subclass of `Pages\Checkout`), `userMenuItem(false)`.
- Plan: `price()` / `prices()`, `label()`, `description()`, `amount()`, `monthly()` / `yearly()` / `interval()`, `priceLabel()`, `features()`, `trialDays()`, `highlighted()`, `visible()` / `hidden()`.
- Every provider: `plans()`, `type()`, `trialDays()`, `allowGenericTrial()`, `successUrl()`, `cancelUrl()`, `returnUrl()`, `billable(fn (Panel $panel, ?Model $tenant, $user) => ...)`, `subscribedUsing(fn (Model $billable): bool => ...)`. Stripe adds `checkoutOptions()`, `customerOptions()`, `portalOptions()`.
- `PaddleBillingProvider` needs `laravel/cashier-paddle` and `SparkBillingProvider` (`path()`, `billableType()`) needs Spark; without them requests throw `BillingPackageMissing`. Without the plugin: `$panel->tenantBillingProvider(StripeBillingProvider::make())->requiresTenantSubscription()`.
- Opt a page out of the requirement on tenancy panels with `isTenantSubscriptionRequired()`. The subscription check reads the local `subscriptions` table, so the webhook must be running.

## Media Library

`composer require alfasix/alfakit-media-library`; run the media library's `media` migration; the model implements `Spatie\MediaLibrary\HasMedia` and uses `InteractsWithMedia`.

```php
use AlfaSix\AlfakitMediaLibrary\Forms\Components\SpatieMediaLibraryFileUpload;
use AlfaSix\AlfakitMediaLibrary\Infolists\Components\SpatieMediaLibraryImageEntry;
use AlfaSix\AlfakitMediaLibrary\MediaLibraryPlugin;
use AlfaSix\AlfakitMediaLibrary\Tables\Columns\SpatieMediaLibraryImageColumn;

$panel->plugin(MediaLibraryPlugin::make()); // optional

SpatieMediaLibraryFileUpload::make('gallery')->collection('gallery')->multiple()->reorderable()->image()->conversion('thumb');
SpatieMediaLibraryImageColumn::make('cover')->collection('cover')->conversion('thumb')->circular();
SpatieMediaLibraryImageEntry::make('gallery')->collection('gallery')->stacked();
```

- Field options on top of every `FileUpload` option: `collection()` (default `default`), `conversion()`, `conversionsDisk()`, `customProperties()`, `manipulations()`, `properties()`, `customHeaders()`, `mediaName()`, `responsiveImages()`, `filterMediaUsing(fn (Collection $media): Collection => ...)`. `disk()` overrides the collection's disk.
- Column / entry: `collection()`, `allCollections()`, `conversion()`, `filterMediaUsing()`, `visibility('private')`; the `media` relation is eager loaded.
- The field's state is the media UUIDs; it never writes an attribute. After save it adds new uploads, deletes removed media and saves the order inside the page transaction. Private disks are served with `getTemporaryUrl()` (30 min).

## Settings

`composer require alfasix/alfakit-settings`; install spatie/laravel-settings (its `settings` migration) and write a `Spatie\LaravelSettings\Settings` class plus a settings migration.

```bash
php artisan make:alfakit-settings-page ManageGeneral GeneralSettings   # --panel=admin, --group=general, --force
```

```php
use AlfaSix\Alfakit\Forms\Components\{TextInput, Toggle};
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\AlfakitSettings\Pages\SettingsPage;
use App\Settings\GeneralSettings;

class ManageGeneral extends SettingsPage
{
    protected static string $settings = GeneralSettings::class;

    protected static ?string $navigationIcon = 'settings';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('site_name')->required(),
            Toggle::make('maintenance_mode'),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-settings') ?? false;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['site_name'] = trim($data['site_name']);

        return $data;
    }
}
```

- Pages are discovered like any page, or registered with `SettingsPlugin::make()->pages([ManageGeneral::class])->navigationGroup('Settings')` (`AlfaSix\AlfakitSettings\SettingsPlugin`; a page's own `$navigationGroup` wins).
- Hooks: `beforeValidate()`, `afterValidate()`, `beforeSave()`, `mutateFormDataBeforeFill()`, `mutateFormDataBeforeSave()`, `afterSave()`, `$this->halt()`. Overrides: `getSavedNotificationTitle()` (`null` = no notification), `getSavedNotification()`, `getRedirectUrl()`, `getSaveFormAction()`, `getFormActions()`, `getSettings()`.
- Only the form's fields are sent to the browser and saved; settings missing from the form keep their value.

## Tags

`composer require alfasix/alfakit-tags`; run the `tags` / `taggables` migration; add `Spatie\Tags\HasTags` to the model.

```php
use AlfaSix\AlfakitTags\Forms\Components\SpatieTagsInput;
use AlfaSix\AlfakitTags\Infolists\Components\SpatieTagsEntry;
use AlfaSix\AlfakitTags\Tables\Columns\SpatieTagsColumn;
use AlfaSix\AlfakitTags\TagsPlugin;

$panel->plugin(TagsPlugin::make()); // optional

SpatieTagsInput::make('categories')->type('categories')->suggestions(['News', 'Guides']);
SpatieTagsColumn::make('tags')->type('categories')->limitList(3);
SpatieTagsEntry::make('tags')->type('categories');
```

- `type('x')` = that type, `type(null)` = tags without a type, no `type()` / `anyTagType()` = every type.
- The input syncs with `syncTagsWithType()` (or `syncTags()` without a type) after save and never writes an attribute; its name only identifies it, so several inputs can edit one `tags` relation. All `TagsInput` / `TextColumn` / `TextEntry` options apply; the column and entry render badges (`badge(false)` to turn off).

## Activity log

`composer require alfasix/alfakit-activity-log`; run spatie's `activity_log` migration; log models with `Spatie\Activitylog\Models\Concerns\LogsActivity` and `getActivitylogOptions(): LogOptions` (`Spatie\Activitylog\Support\LogOptions`).

```php
use AlfaSix\AlfakitActivityLog\ActivityLogPlugin;
use AlfaSix\AlfakitActivityLog\Infolists\Components\ActivityLogTimelineEntry;
use AlfaSix\AlfakitActivityLog\RelationManagers\ActivitiesRelationManager;

$panel->plugin(
    ActivityLogPlugin::make()                 // resource at /{panel}/activity-log
        ->navigationGroup('Settings')
        ->navigationCountBadge()
        ->authorize(fn (User $user): bool => $user->is_admin)
        ->logActions(),                       // log Create/Edit/Delete actions for models without LogsActivity
);

// In a resource
public static function getRelations(): array
{
    return [ActivitiesRelationManager::class];
}

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        ActivityLogTimelineEntry::make()->limit(20)->showChanges()->columnSpanFull(),
    ]);
}
```

- Options: `navigationLabel()`, `navigationIcon()`, `navigationSort()`, `registerNavigation(false)`, `label()`, `pluralLabel()`, `resourceClass(MyActivityResource::class)` (extend `Resources\ActivityResource`), `scopeToTenant(false)`, `scopeToTenantUsing(fn (Builder $query, Team $tenant) => ...)`, `recordTenant(false)`. Closures get `$plugin`, `$panel`, `$user`, `$tenant`.
- `ActivityLogTimelineEntry`: `limit()` (default 10, `null` = all), `modifyQueryUsing(fn (Builder $query) => $query->inLog('orders'))`, `showChanges(false)`.
- Create/edit pages save without an action: add `Concerns\LogsAlfakitActions` to them when using `logActions()`. Log by hand with `Support\ActionActivityLogger::log('approved', $invoice, force: true)`. Pieces for your own pages: `Support\ActivitySchema::columns()`, `filters()`, `infolist()`, `changesEntry()`.
- The resource is read only (never creates, edits or deletes activities).

## Backup

`composer require alfasix/alfakit-backup`; configure spatie/laravel-backup (`config/backup.php`) and run a queue worker (backups are a queued `Jobs\CreateBackupJob`, which fails when `backup:run` exits nonzero).

```php
use AlfaSix\AlfakitBackup\BackupPlugin;

$panel->plugin(
    BackupPlugin::make()                      // page at /{panel}/backups
        ->usingQueue('backups', 'redis')
        ->timeout(600)
        ->usingPollingInterval('10s')         // default '4s', null = off
        ->downloadLinkLifetime(10)            // minutes, default 5
        ->authorize(fn (User $user): bool => $user->is_admin)
        ->navigationGroup('Settings'),
);
```

- Also `usingPage(MyBackups::class)` (extend `Pages\Backups`), `noTimeout()`, `navigationLabel()`, `navigationIcon()`, `navigationSort()`, `registerNavigation(false)`.
- `Support\BackupRepository::backups()`, `statuses()`, `destinations()`, `find($disk, $path)`; `Widgets\BackupDestinationsWidget` is the destination cards.

## Health

`composer require alfasix/alfakit-health`; register checks with `Spatie\Health\Facades\Health::checks([...])`, configure a result store and schedule `health:check`.

```php
use AlfaSix\AlfakitHealth\HealthPlugin;

$panel->plugin(
    HealthPlugin::make()                      // page at /{panel}/health
        ->dashboardWidget()                   // adds Widgets\HealthStatusWidget to the panel
        ->usingPollingInterval('30s')         // default '10s'
        ->notifyOnRefresh()
        ->authorize(fn (User $user): bool => $user->is_admin)
        ->navigationGroup('Settings'),
);
```

- Also `usingPage(MyHealth::class)` (extend `Pages\Health`), `dashboardWidget(widget: MyHealthWidget::class)`, `navigationLabel()`, `navigationIcon()`, `navigationSort()`, `registerNavigation(false)`.
- **Refresh** runs `health:check`; polling only reads stored results. `Support\HealthResults` has `latest()`, `rows()`, `run()`, `statusLabel()`, `statusColor()`, `statusIcon()`.

## Calendar

`composer require alfasix/alfakit-calendar` (FullCalendar ships inside `@alfakit/react|vue|svelte`; registry key `widget:calendar`).

```php
use AlfaSix\Alfakit\Forms\Components\{DateTimePicker, TextInput};
use AlfaSix\AlfakitCalendar\CalendarPlugin;
use AlfaSix\AlfakitCalendar\Event;
use AlfaSix\AlfakitCalendar\Widgets\CalendarWidget;

$panel->plugin(CalendarPlugin::make()->editable()->config(['dayMaxEvents' => true]));

class MeetingsCalendar extends CalendarWidget
{
    protected static ?string $model = Meeting::class;   // or $resource = MeetingResource::class

    protected static ?string $startAttribute = 'starts_at';   // drag/resize and range selection write these

    protected static ?string $endAttribute = 'ends_at';

    protected static ?string $recordTitleAttribute = 'subject';   // names the record in the event modals

    protected ?string $heading = 'Meetings';

    public function fetchEvents(array $info): array
    {
        return Meeting::query()
            ->where('starts_at', '<', $info['end'])
            ->where('ends_at', '>', $info['start'])
            ->get()
            ->map(fn (Meeting $meeting) => Event::make($meeting)->title($meeting->subject)->start($meeting->starts_at)->end($meeting->ends_at))
            ->all();
    }

    public function getFormSchema(): array
    {
        return [
            TextInput::make('subject')->required(),
            DateTimePicker::make('starts_at')->required(),
            DateTimePicker::make('ends_at'),
        ];
    }
}
```

- Register it like any widget (`->widgets([MeetingsCalendar::class])`); it spans full width.
- Plugin: `timezone()`, `locale()`, `firstDay()`, `editable()`, `selectable()`, `config([...])`. Widget: `$views`, `$initialView`, `config()`, `getTimezone()`, `isEditable()`, `isSelectable()`.
- `Event`: `title()`, `start()`, `end()`, `allDay()`, `url($url, $shouldOpenInNewTab)`, `backgroundColor()`, `borderColor()`, `textColor()`, `editable()`, `groupId()`, `classNames()`, `extendedProps()`.
- Actions: `getHeaderActions()` (default `[$this->createAction()]`), `getEventClickAction()` (default view modal; `editAction()` / `deleteAction()` work too, offered by `viewAny` and checked for the clicked event), `getModalActions()`, `getDateSelectAction()` (receives `$arguments['start']`, `['end']`, `['allDay']`); prebuilt `createAction($name)`, `editAction()`, `viewAction()`, `deleteAction()`; give a second action of one kind its own name.
- `onEventDrop(array $event, array $oldEvent, ?Model $record): bool` / `onEventResize(...)` return `true` when saved (unlike Filament, `false` reverts). `config()` accepts JSON only; callbacks and controlled options are dropped.

## Tree

`composer require alfasix/alfakit-tree`; `$panel->plugin(TreePlugin::make())` (`AlfaSix\AlfakitTree\TreePlugin`) for translated UI. The table needs a nullable `parent_id` and an integer `order` column.

```php
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitTree\Concerns\HasTreeStructure;
use AlfaSix\AlfakitTree\Pages\TreePage;
use AlfaSix\AlfakitTree\Widgets\TreeWidget;
use App\Models\MenuItem;

class Category extends Model
{
    use HasTreeStructure;   // parent(), children(), childrenRecursive(), ancestors(), isRoot(), scopes isRoot()/ordered()
}

class CategoryTree extends TreePage
{
    protected static string $resource = CategoryResource::class;

    protected static ?int $maxDepth = 3;
}

// CategoryResource::getPages(): 'index' => CategoryTree::route('/'),
// Create/edit: getFormSchema() modal, else the resource's create/edit pages, else a modal with the
// resource's form() (simple resources). Node buttons are labelled "Edit {title}" / "Delete {title}".

class MenuTree extends TreeWidget
{
    protected static ?string $model = MenuItem::class;

    protected static ?int $maxDepth = 2;

    protected ?string $heading = 'Menu';

    public function getFormSchema(): array
    {
        return [TextInput::make('title')->required()];
    }
}
```

- Columns: `$treeParentColumn`, `$treeOrderColumn`, `$treeTitleColumn` on the model (defaults `parent_id`, `order`, `title`).
- Page / widget: `$maxDepth` (default 2, `1` = flat sortable list, `null` = unlimited), `getTreeQuery()`, `getTreeRecordTitle()`, `getTreeRecordIcon()`, `isTreeCollapsedByDefault()`, `getFormSchema()`, `getTreeActions()` (record actions per node), `hasEditAction()` / `hasDeleteAction()` / `hasViewAction()`, `canReorderTree()`, `afterTreeReordered(int $changed)`.
- Saving sends the whole structure once; `Support\TreeStructure::save($query, $items, $parentColumn, $orderColumn, $maxDepth)` does the same from code.

## Comments

`composer require alfasix/alfakit-comments`, `php artisan vendor:publish --tag=alfakit-comments-migrations`, `php artisan migrate`. Mention notifications use the `notifications` table (`make:notifications-table`) and `->databaseNotifications()` on the panel.

```php
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\AlfakitComments\Actions\CommentsAction;
use AlfaSix\AlfakitComments\Actions\CommentsTableAction;
use AlfaSix\AlfakitComments\CommentsPlugin;
use AlfaSix\AlfakitComments\Concerns\HasComments;
use AlfaSix\AlfakitComments\Concerns\IsCommenter;
use AlfaSix\AlfakitComments\Contracts\Commentable;
use AlfaSix\AlfakitComments\Contracts\Commenter;
use AlfaSix\AlfakitComments\Infolists\Components\CommentsEntry;

class Ticket extends Model implements Commentable
{
    use HasComments;
}

class User extends Authenticatable implements Commenter   // optional
{
    use IsCommenter;
}

$panel->plugin(CommentsPlugin::make()->mentionableSearchColumns(['name', 'email'])->reactions(['👍', '🎉'])->perPage(20));

// Resource infolist (or a form: CommentsEntry::make()->hiddenOn('create'))
Section::make('Comments')->schema([CommentsEntry::make()->oldestFirst()]);

// Page header actions (return them from getHeaderActions()) and table rows
$headerActions = [CommentsAction::make()];

$table->recordActions([CommentsTableAction::make()]);
```

- Plugin: `mentionables(fn (?Model $tenant): Builder => ...)`, `mentionableSearchColumns()`, `mentionUrl()`, `reactions(array|false)`, `newestFirst()` / `oldestFirst()`, `perPage()`, `pollingInterval()`, `toolbarButtons()`, `notifyMentions(false)`, `commentUrlUsing()`, `authorUsing()`, `commentModel()`, `reactionModel()`.
- Entry: `make('key')`, `commentable($record)`, `perPage()`, `newestFirst()` / `oldestFirst()`, `readOnly()`, `disableReactions()`, `disableAvatars()`, `loadMoreLabel()`. Actions add `modifyCommentsEntryUsing(fn (CommentsEntry $entry) => ...)`.
- `Widgets\CommentsWidget` for record page header/footer widgets (polls). From code: `$ticket->comment('<p>Hi</p>', $user)`, `$ticket->comments()`, `Support\Comments::post()|update()|delete()|toggleReaction()`.
- Events (`Events\`): `CommentWasCreated`, `UserWasMentioned`, `CommentWasReacted`.

## Kanban

`php artisan alfakit:add kanban`. No migration: the board writes a status column (or a `BelongsTo` foreign key) and an integer order column (`sort` by default; `null` = only move between columns). The plugin only shares the board's translations.

```php
use AlfaSix\AlfakitKanban\Card;
use AlfaSix\AlfakitKanban\CardBadge;
use AlfaSix\AlfakitKanban\Column;
use AlfaSix\AlfakitKanban\Pages\KanbanPage;
use AlfaSix\AlfakitKanban\Support\KanbanMove;
use Illuminate\Database\Eloquent\Model;

class TaskBoard extends KanbanPage   // resource page: 'board' => TaskBoard::route('/board')
{
    protected static string $resource = TaskResource::class;

    protected static ?string $statusEnum = TaskStatus::class;   // or $columnRelationship = 'stage', or getColumns()

    protected static ?string $orderAttribute = 'sort';

    protected static array $searchableAttributes = ['title'];

    protected function getTransitions(): ?array
    {
        return ['todo' => ['doing'], 'doing' => ['todo', 'done'], 'done' => []];
    }

    public function getCard(Model $record): Card
    {
        return parent::getCard($record)->badges([CardBadge::make($record->priority)])->dueDate($record->due_at);
    }

    protected function afterCardMoved(KanbanMove $move): void
    {
        // $move->record, $move->from, $move->to, $move->changesColumn()
    }
}
```

- `KanbanWidget` (`Widgets\KanbanWidget`, `$model` or `$resource`, `getKanbanQuery()`) puts a board on a dashboard. Registry key `kanban`.
- The board page is titled "Tasks board" (`alfakit-kanban::kanban.page.title` with the resource's plural label; `KanbanPage::getBoardTitle()`); set `protected static ?string $title` to rename it.
- Columns: `Column::make('doing')->label()->color()->icon()->wipLimit(3, strict: true)->collapsible()->creatable(false)`. Cards: `$titleAttribute`, `$descriptionAttribute`, `$dueDateAttribute`, `$progressAttribute`, `$coverImageAttribute`, `$with`, or `getCard()` / `getCardSchema()`.
- `getFormSchema()` adds create / per-column quick create and view / edit modals; `getCardActions()` the card menu; `getKanbanFilters()` takes table filters; `$pollingInterval`, `$cardsPerColumn`; swimlanes with `$swimlaneAttribute` / `$swimlaneRelationship` and `$canMoveBetweenSwimlanes`.
- Hooks: `canMoveCard()` (default the `update` ability), `canMoveTo()`, `beforeCardMoved()` (throw `ValidationException` to refuse), `saveCardMove()`, `afterCardMoved()`; event `Events\CardMoved`.
- `getTransitions()` (`['draft' => ['confirmed', 'cancelled'], 'confirmed' => ['shipped']]`, keys are column keys / enum values) is enforced on the server (422 with the reason) and in the browser: a card released over a column it may not enter returns to its own column with an error toast and no request; a refused or failed (500, network) save rolls the card and the counts back. Moves go one allowed step at a time (Draft → Confirmed → Shipped).

## Forms

`php artisan alfakit:add forms`, then `php artisan alfakit-forms:page` (writes the Inertia page the public `/forms/{slug}` route renders; `--adapter=`) and import `@alfakit/{adapter}/standalone.css` in the app CSS. Admins build forms in the panel (blocks, page breaks, conditional logic, spam settings); the public fills them outside the panel.

```php
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Events\FormSubmitted;
use AlfaSix\AlfakitForms\FormsPlugin;
use Illuminate\Support\Facades\Event;

$panel->plugin(FormsPlugin::make()->navigationGroup('Content')->fieldTypes(['text', 'email', 'textarea', 'select', 'page_break']));

AlfakitForms::format('vat_number', '/^[A-Z]{2}[0-9A-Z]{8,12}$/', 'VAT number'); // admins pick formats by name, never type regexes

Event::listen(function (FormSubmitted $event): void {
    // $event->form, $event->submission->data (never fired for spam)
});

// Any Inertia page: render with <AlfakitComponent component={newsletter} />
Inertia::render('home', ['newsletter' => AlfakitForms::embed('newsletter')]);
```

- Plugin: `resourceClass()`, `fieldTypes()`, `authorize()`, labels and navigation. Config `alfakit-forms.php`: `route` (prefix, middleware, domain), `page`, `spam`, `rate_limit`, `uploads`, `mail`, `models`.
- Classes: `Models\Form` (`getStatus()`, `countSubmissions()`, `getConversionRate()`), `Models\FormSubmission`, `Resources\FormResource`, `Standalone\PublicForm` (extend to change `form()` / `submit()`), `Mail\SubmissionReceived`, `AlfakitForms::captcha(component:, verify:)`.
- Conditional logic runs in PHP only; the stored definition is re-validated on every submit, hidden fields are neither validated nor stored. Honeypot, minimum fill time (in the context token) and per-IP rate limit; spam is answered like a success.

## CMS

`php artisan alfakit:add cms`, then `php artisan storage:link`. For the public site, register `CmsPage` with `createAlfakit({ pages: { 'Alfakit/CmsPage': CmsPage } })` in its own entry (a starter kit's `app.blade.php` can't load it: add `resources/js/site.*` to Vite's `input`, a `site.blade.php` with `@vite` of it, and `->rootView('site')`). App routes win over CMS pages, `/` included: for the CMS home page, point the app's `home` route at it with `Route::get('/', \AlfaSix\AlfakitCms\Http\Controllers\PageController::class)->name('home');`.

```php
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;

$panel->plugin(
    CmsPlugin::make()
        ->siteName('Acme')
        ->publicRoutes()                       // catch-all fallback route, sitemap.xml, robots.txt (opt-in)
        ->api()                                // read-only JSON API at api/cms (opt-in)
        ->blocks([PricingBlock::class])        // extends Blocks\CmsBlock: schema() + toFrontend()
        ->withoutBlocks(['testimonials'])
        ->locales(['en' => 'English', 'ar' => 'العربية'], default: 'en')
        ->review(reviewers: fn (Page $page): iterable => User::role('editor')->get()),
);
```

- Resources: pages (blocks, drafts, scheduling, revisions, signed previews, review workflow), reusable sections, templates, menus, redirects. Other options: `siteDesign()`, `customCode()`, `layouts()`, `templates()`, `sharedMenus()`, `resolveTenantUsing()` (required for tenancy sites), `adminBar()`, `livePreview()`, `draftChanges()`, `revisionsLimit()`, `previewLinkLifetime()`, `formOptions()` / `formUsing()`, `embedHosts()`, `disk()` / `directory()`, `pageResource()`.
- Custom block: `toFrontend()` is the only data sent to the browser; build it with the helpers `text()`, `html()` (sanitised), `url()`, `link()`, `image()`, `items()`; register the renderer under `cms:block:{name}`. `Forms\Components\PageBuilder::make('content')` + `Cms::blocks($model->content)` reuse blocks elsewhere; `Cms::url($page)`, `Cms::menu('main')`, `Cms::previewUrl($page)`.
- Site design (`siteDesign()`, on by default): the **Site design** page (`Pages\ManageSiteDesign`, model `Models\SiteSettings`, one row per tenant, ability `update`) sets brand colors, fonts (fonts.bunny.net), corner radius, logo and favicon, sent as `cms.site.design` (`css`, `fonts`, `logo`, `favicon`) and mapped onto the theme variables, so blocks need no changes; pass `design={cms.site.design}` to `CmsHead` on your own page. With a site favicon the root view skips the panel `favicon()` (view data `alfakitPanelFavicon` false). The JSON API page (`GET api/cms/page`) returns the same design as `site.design` next to `data` (type `CmsPageApiResponse`), never the `customCode()` head/body snippets. Every block gets a collapsed **Style** section (`cms_style`: `background` muted|primary|secondary|dark, `spacing` none|small|large, `visibility` desktop|mobile, `anchor`), sent as `block.style` and applied on the wrapper by `CmsBlocks` (`cmsBlockAttributes()` in `@alfakit/core`). `customCode()` (off by default; `true` or `fn (User $user): bool`) adds custom CSS (no `@import`, scripts or other sites' files) and head/body snippets printed by the root view as `{{ $alfakitCmsCode ?? '' }}` / `{{ $alfakitCmsBodyCode ?? '' }}` (never on previews), with a **Code history** to restore. Upgrading an existing install: `php artisan alfakit:upgrade --migrations` (publishes only migrations not yet published), then `migrate`.
- Frontend exports: `CmsPage`, `CmsBlocks`, `CmsHead`, `CmsNav`, `CmsProvider`; registry keys `cms:header`, `cms:footer`, `cms:form`.
- Page editor: a sticky bar (`Forms\Components\LivePreview`, registry key `CmsLivePreview`) shows the status (`PageResource::describeEditorStatus()`), the autosave state, the **Live preview** toggle and the main actions from `EditPage::getEditorActions()` (review steps, Save draft, Publish); other actions sit in the header menu. The preview pane opens beside the form by itself when the form's column is at least 1440 px wide (never below 1024 px, never when the form would keep less than 640 px beside it: then it opens as a full-screen overlay, `cmsPreviewFitsBeside()`) and remembers the viewer's choice (`cmsPreviewOpensOnLoad()` / `rememberedCmsPreview()` in `@alfakit/core`). The page builder uses `Builder::blockActionsMenu()` and each block's `getSummary()` becomes the item's `Block::summary()`. Add a bar button in your own edit page (register it via your resource's `getPages()` and `pageResource()`):

```php
use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\EditPage as BaseEditPage;

class EditPage extends BaseEditPage
{
    public function getEditorActions(): array
    {
        return [...parent::getEditorActions(), Action::make('checklist')->url(fn (): string => route('launch-checklist'))];
    }
}
```

## API

`php artisan alfakit:add api`. A resource is exposed only when listed or when it implements `ExposesApi`; the API runs the resource's own table (sort, search, filters), form (validation, hooks) and policies. No Sanctum needed (own hashed tokens, "API tokens" page).

```php
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Contracts\ExposesApi;
use AlfaSix\AlfakitApi\ResourceApi;

$panel->plugin(
    ApiPlugin::make()
        ->resources([
            CategoryResource::class,
            ResourceApi::make(PostResource::class)
                ->fields(['id', 'title', 'status', 'published_at'])   // output whitelist; $hidden is never returned
                ->includes(['author' => ['id', 'name']])
                ->bulkActions(['publish'])
                ->operations(['index', 'show', 'store', 'update']),
        ])
        ->rateLimit(120)
        ->tokenExpirationOptions([30, 90, null], default: 30),
);

class TagResource extends Resource implements ExposesApi
{
    public static function api(ResourceApi $api): ResourceApi
    {
        return $api->fields(['id', 'name'])->readOnly();
    }
}
```

- Routes: `/api/v1/{slug}` (`GET`, `POST`, `GET|PATCH|PUT|DELETE /{record}`, `POST /bulk/{action}`), `/api/v1/openapi.json`, `/api/v1/token`; tenancy panels `/api/v1/{tenant}/…` (or `tenantFromToken()`).
- Plugin: `prefix()`, `version()`, `domain()`, `middleware()`, `defaultPerPage()` / `maxPerPage()`, `idempotency()`, `tokenRepository()` (`Contracts\TokenRepository`; `new Tokens\SanctumTokenRepository` stores Sanctum personal access tokens, `panelBound: false` also accepts the app's own `createToken()` tokens), `canManageTokens()`, `tokensPage()`, `documentationPage()`, `canViewDocumentation()`, `publicDocumentation()`. `ResourceApi`: `slug()`, `hiddenFields()`, `writableFields()`, `modifyQueryUsing()`, `description()`.
- Token abilities `posts:read|create|update|delete`, `posts:bulk:publish`, `posts:*`, `*` only narrow; the policy is checked too. Issue from code: `ApiPlugin::get($panel)->getTokenRepository()->create(user:, panelId:, name:, abilities:, expiresAt:)`. Errors are RFC 9457 problem JSON.

## Webhooks

`php artisan alfakit:add webhooks`; run a queue worker and schedule `alfakit-webhooks:prune` daily. Endpoints (URL, events, encrypted custom headers, `whsec_` signing secret shown once) live at `/{panel}/webhooks`.

```php
use AlfaSix\AlfakitWebhooks\Webhooks;
use AlfaSix\AlfakitWebhooks\WebhooksPlugin;

$panel->plugin(
    WebhooksPlugin::make()
        ->events(['order.paid' => fn () => __('webhooks.order_paid'), 'invoice.sent'])  // labels: string or Closure (translated per request)
        ->modelEvents([Customer::class])                 // customer.created / updated / deleted
        ->retries(5)
        ->authorize(fn (User $user): bool => $user->is_admin),
);

Webhooks::dispatch('order.paid', ['order' => $order->only(['id', 'total'])]); // queued after commit, current tenant

$fake = Webhooks::fake();                                // tests
$fake->assertDispatched('order.paid');
```

- Deliveries are signed (`X-Webhook-Signature: t=…,v1=…`, HMAC-SHA256 of `"{t}.{body}"`; `Support\Signature::verify()` for receivers), retried with backoff, logged (redacted, truncated) with replay, and the endpoint auto-disables after `disableAfterConsecutiveFailures()` (15).
- Options: `backoff()` / `backoffUsing()`, `timeout()`, `queue()`, `headerPrefix()`, `redact()`, `maxLoggedBodySize()`, `pruneAfterDays()`, `notifyOnDisable()`, `scopeToTenant()`, `globalEndpointsReceiveTenantEvents()`, `resourceClass()`, `allowInsecureUrls()`, `allowPrivateNetworks()`, `allowedHosts()` / `blockedHosts()`.
- Models: `WebhookEndpoint`, `WebhookEvent`, `WebhookDelivery`; events `WebhookDeliverySucceeded`, `WebhookDeliveryFailed`, `WebhookEndpointDisabled`. A model's `toWebhookPayload(string $event)` / `getWebhookTenant()` shape model events.

## Ops

`php artisan alfakit:add ops`. Pages under `/{panel}/ops`: overview and system info, failed jobs, queues, batches (`make:queue-batches-table`), scheduler (run now), logs (read from the end, path-checked), caches and maintenance mode.

```php
use AlfaSix\AlfakitOps\OpsPlugin;

$panel->plugin(
    OpsPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)     // else the viewAlfakitOps gate; deny by default
        ->authorizeActions(fn (string $ability, User $user): bool => $user->is_admin
            || $ability === OpsPlugin::ABILITY_RETRY_FAILED_JOBS)
        ->readOnly(fn (): bool => app()->isProduction())
        ->requiresPasswordConfirmation()
        ->maintenance(false)
        ->monitorQueues(['redis' => ['default', 'emails']]),
);
```

- Features (`false` removes the page and routes, a closure is checked per request): `overview()`, `failedJobs()`, `queues()`, `batches()`, `scheduler()`, `logs()`, `maintenance()`. Also `gate()`, `dashboardWidget()`, `redactKeys()`, `pollingInterval()`, `runScheduledTasksOnQueue()`, `logDirectory()`, `logFiles()`, `downloadLinkLifetime()`, `allowOnTenantPanels()` (hidden on tenancy panels by default).
- `ABILITY_*` constants: retry / forget / flush failed jobs, clear queues, retry / cancel batches, run scheduled tasks, download / delete logs, clear caches, optimize, maintenance mode.
- Data for your own widgets: `Support\FailedJobRepository`, `QueueMonitor`, `BatchRepository`, `ScheduledTasks`, `LogFiles::resolve()`, `LogReader::paginate()`; `Widgets\SystemInfoWidget`, `OpsOverviewWidget`.

## Feature flags

`php artisan alfakit:add feature-flags` (requires `laravel/pennant`; publish Pennant's own migration with `vendor:publish --tag=pennant-migrations` if you don't have the `features` table). Features stay plain Pennant features; the panel adds a kill switch, stable percentage rollouts, schedules (`alfakit-feature-flags:apply-schedules`, auto-scheduled), targeting and an audit trail.

```php
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\AlfakitFeatureFlags\Concerns\FeatureFlagged;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\ScopeResolver;

$panel->plugin(
    FeatureFlagsPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)   // else the manageFeatureFlags gate
        ->discoverFeatures()
        ->descriptions(['new-billing' => 'The new billing page.'])
        ->scopes([User::class, ScopeResolver::make(Team::class)->titleAttribute('name')]),
);

FeatureFlags::rollout('new-billing', 25);
FeatureFlags::kill('new-billing');
FeatureFlags::setFor('new-billing', $user, true);

class Billing extends Page
{
    use FeatureFlagged;   // hides the page (403) while the feature is inactive; also on resources and actions

    protected static ?string $feature = 'new-billing';
}
```

- Options: `gate()`, `authorizeActions(fn (string $ability, ?string $feature, User $user): bool)`, `readOnly()`, `requiresPasswordConfirmation()`, `store()`, `values()`, `hideFeatures()`, `scopeUsing()`, `tenantScope()`, `globalActionsOnTenantPanels()`, `activityLog(false)`.
- `Infolists\Components\FeatureFlagsEntry::make()->features([...])` shows and toggles a record's features. Attributes `#[Description]`, `#[Values]` on class features; a public `valueLabels(): array` method (`value => label`, translatable) labels the values (or lists them on its own), and `values(['feature' => fn () => [...]])` takes closures. `FeatureFlags::evaluate($feature, $scope)` explains a decision.

## Translation manager

`php artisan alfakit:add translation-manager`. A Translations page with a keyboard-driven grid, placeholder / plural / HTML checks, history, a database override layer (cached), export / import to lang files, a source scan and reviewed AI suggestions (with `laravel/ai`).

```php
use AlfaSix\AlfakitTranslationManager\Enums\StorageMode;
use AlfaSix\AlfakitTranslationManager\TranslationManagerPlugin;

$panel->plugin(
    TranslationManagerPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)    // else the manageTranslations gate; deny by default
        ->mode(StorageMode::Database)                            // or filesOnly(), databaseAndFiles()
        ->baseLocale('en')
        ->locales(['en', 'de', 'ar'])
        ->editableLocales(fn (User $user): array => $user->translator_locales)
        ->namespaces(['alfakit']),
);
```

- Options: `backups()`, `scanPaths()`, `aiProvider()`, `aiBatchSize()`, `aiTranslation()`, `tenantScoped()`, `perPage()`, `usingPage()`, navigation. Commands: `alfakit-translations:scan` (`--missing --unused --fail-on-missing`), `:export`, `:import`.
- Bind `Contracts\MachineTranslator` for another translation service; fake AI in tests with `Ai\TranslationAgent::fake()`. Registry key `translation-manager`.

## AI

`php artisan alfakit:add ai` (requires `laravel/ai`), then `php artisan alfakit:ai-setup` for provider keys. `laravel/mcp` is needed for the MCP server.

```php
use AlfaSix\AlfakitAi\Actions\AiAction;
use AlfaSix\AlfakitAi\Actions\SummarizeAction;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;

$panel->plugin(
    AiPlugin::make()
        ->provider('openai', 'gpt-4.1-mini')
        ->provider('anthropic', 'claude-sonnet-4-5', feature: AiFeature::Copilot)
        ->authorize(fn (User $user): bool => $user->can('use-ai'))
        ->copilot()                       // topbar chat (mod+j), off by default; tenancy pages without a tenant (profile, registration) leave it out; starter kits: <AlfakitCopilot /> in the app header
        ->mcp()                           // /{panel}/mcp, off by default
        ->streaming()                     // stream AiAction / aiAssist / copilot answers (SSE, Stop button)
        ->redact(['email', 'phone']),
);

$table->recordActions([
    SummarizeAction::make()->bullets(),
    AiAction::make('explain')->prompt(fn (Invoice $record): string => "Explain: {$record->description}"),
])->aiFilters();                          // "Ask AI" → the table's own filters, search and sort; call after headerActions()

Textarea::make('notes')->aiAssist();      // writing assistant (TextInput, Textarea, RichEditor, MarkdownEditor)
```

- Prebuilt actions in `Actions\`: `SummarizeAction`, `TranslateAction`, `ExtractIntoFieldsAction`, `ClassifyAction` (`saveTo()`), `AiFilterAction`. `AiAction`: `prompt()`, `instructions()`, `schema()` (structured output, reviewed before submit), `inputs()`, `fillField()` / `fillFields()`, `attachments()`.
- Plugin: `enabled()`, `disable(AiFeature::Copilot, …)`, `model()`, `timeout()`, `resources()`, `copilotActions()`, `copilotInstructions()`, `rateLimit()`, `pricing()`, `usageResource()`, `canViewAllUsage()`, `redactUsing()`.
- No provider key: `isConfigured(?AiFeature)` reads `config('ai.default')` / `config('ai.providers')` only. AI modals, `->aiAssist()`, "Ask AI", the copilot and the usage page show "AI isn't set up yet. Run `php artisan alfakit:ai-setup`…" to users passing `setupHintVisible(fn (User $user): bool => $user->is_admin)` (default: local env only) and "AI is not available right now" to others; never a 500. `->demoResponses(bool|Closure)` instead answers every feature with labelled, deterministic sample answers made locally (`Support\DemoResponder`: extractive summaries, "[Sample translation · …]", template rewrites, regex extraction, keyword classification, English filter phrases like "over 100 this month", a copilot running its real tools for "search …", "open …", "run … on …"), logged as provider `demo` at zero cost. A configured key always wins.
- Tests fake the agents: `Agents\TextAgent::fake([...])`, `StructuredAgent`, `AssistAgent`, `FilterAgent`, `Copilot\CopilotAgent` (faked agents need no key). Streaming (`->streaming()`, per action `->stream(false)`) also works with fakes (they stream word by word); structured answers and `attachments()` never stream. MCP tokens: alfakit-api tokens, Sanctum, or `php artisan alfakit-ai:mcp-token`, all tried in that order; `->mcpTokenSources(['api'])` narrows them (`'api'`, `'sanctum'`, `'plugin'`).

## Teams

`php artisan alfakit:add teams` (it also adds `HasTeams`, the two tenancy contracts and, next to Shield's `HasRoles`, the `HasTeams::teams insteadof HasRoles` block to the user model). Jetstream-style teams on panel tenancy: the plugin calls `tenant(Team::class, slugAttribute: 'slug', ownershipRelationship: 'team')`, the tenant registration / profile pages, members and invitations pages and the signed invitation route. Run a queue worker (invitation mail).

```php
use AlfaSix\Alfakit\Contracts\HasDefaultTenant;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\AlfakitTeams\Concerns\HasTeams;
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\TeamsPlugin;

class User extends Authenticatable implements HasDefaultTenant, HasTenants
{
    use HasTeams;   // hasTeamPermission(), teamRole(), ownsTeam(), belongsToTeam()
}

$panel->plugin(
    TeamsPlugin::make()
        ->personalTeams()
        ->roles([
            Role::make('owner'),
            Role::make('admin')->permissions([Permission::UPDATE_TEAM, 'members:*', 'projects:*']),
            Role::make('editor')->permissions(['projects:create', 'projects:update']),
        ])
        ->defaultRole('editor')
        ->seats(fn (Team $team): ?int => $team->plan === 'pro' ? 25 : 5),
);
```

- Tenant-owned models need `team_id` and a `team()` relationship. Options: `teamModel()` (with `Concerns\IsTeam`), `configureTenancy(false)`, `ownershipRelationship()`, `teamCreation()`, `invitationExpiry()`, `invitationLimit()`, `invitations(false)`, `domainAutoJoin()`, `seatsFromBilling()`, `syncSeatsWithBilling()`, `syncShieldRoles()`, `logos()`, `membersNavigation()`, page overrides (`membersPage()`, `settingsPage()`, …).
- `Teams::manager()` (`TeamManager`): `createTeam()`, `invite()`, `addMember()`, `updateRole()`, `removeMember()`, `transferOwnership()`, `deleteTeam()`, `seatsRemaining()`; rule violations throw `Exceptions\TeamException`. Events in `Events\`.

## Workflows

`php artisan alfakit:add workflows`; schedule `alfakit-workflows:process` (reminders, escalation) and enable `->databaseNotifications()`. The plugin adds the Approvals inbox; transitions also work without it.

```php
use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Concerns\HasWorkflow;
use AlfaSix\AlfakitWorkflows\State;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;

class Invoice extends Model
{
    use HasWorkflow;   // refuses saving a changed `state` outside a transition

    public static function workflow(): Workflow
    {
        return Workflow::make('invoice')
            ->states([State::make('draft'), State::make('submitted')->color('warning'), State::make('approved')->color('success'), State::make('rejected'), State::make('paid')->final()])
            ->transitions([
                Transition::make('submit')->from('draft')->to('approved')
                    ->requiresApproval([ApprovalStep::make('manager')->role('manager')->dueIn('2 days')->escalateAfter('3 days')->escalateToRole('director')])
                    ->pendingState('submitted')->rejectedState('rejected'),
                Transition::make('pay')->from('approved')->to('paid')->authorize('pay'),
            ])
            ->initial('draft');
    }
}

$invoice->transition('submit', comment: 'Ready');   // throws Exceptions\TransitionDenied with a $reason
```

- UI: `Actions\WorkflowActions::make()` (page header / record actions; `dropdown(false)`), `Infolists\Components\WorkflowTimeline`, `WorkflowStateEntry`, `Schemas\Components\WorkflowDiagram`, `Tables\Columns\WorkflowStateColumn` (`showPendingApproval()`), `Tables\Filters\WorkflowStateFilter`.
- `Transition`: `guard()`, `authorize()`, `form()`, `withComment()` / `requiresComment()`, `using()`, `after()`, `notify()`. `ApprovalStep`: `approvers()`, `role()`, `permission()`, `anyOf()` / `allOf()`, `onlyWhen()`, `remindEvery()`, `escalateTo()`. Code API: `canTransition()`, `getAvailableTransitions()`, `getWorkflowState()`, `isAwaitingApproval()`, `Support\Approvals::approve()`.
- Plugin: `inbox()`, `users()`, `mailNotifications()`, `recordUrlUsing()`, `authorizeReassignUsing()`, navigation.

## Invoices

`php artisan alfakit:add invoices` (needs `ext-intl`). Install `spatie/laravel-pdf` or `barryvdh/laravel-dompdf` for PDF files (without them documents are print-ready HTML). The overdue command is scheduled daily for you; emails are queued.

```php
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;

$panel->currency('BHD')->plugin(
    InvoicesPlugin::make()
        ->authorize(fn (User $user): bool => $user->can_bill)
        ->customers(fn (?Team $tenant) => Customer::query()->whereBelongsTo($tenant), titleAttribute: 'company')
        ->numbering(InvoiceType::Invoice, prefix: 'INV-', format: '{YYYY}-{SEQ:5}')
        ->seller(fn (?Team $tenant): array => ['name' => $tenant?->name, 'tax_id' => $tenant?->vat_number])
        ->dashboardWidgets(),
);

$invoice = Invoice::createWithItems(
    ['billable' => $customer, 'currency' => 'BHD'],
    [['description' => 'Design', 'quantity' => '10', 'unit_price' => 25000]],   // minor units
);
$invoice->recordPayment(100000, method: 'bank_transfer');
```

- Money is integer minor units (no floats); totals are always recomputed on the server. Options: `assignNumbersOnIssue()`, `rounding(Rounding::PerLine|PerTotal)`, `currency()`, `currencies()`, `dueDays()`, `quoteValidDays()`, `branding()`, `defaultNotes()` / `defaultTerms()`, `payNowUsing()`, `invoiceResource()`, `taxResource()`, `taxes(false)`.
- Model methods: `markAsSent()`, `markAsPaid()`, `duplicate()`, `convertToInvoice()`, `createCreditNote()`, `void()`, `getCustomerUrl()` (signed customer page). `Contracts\InvoiceRecipient` on customer models; `Contracts\PdfRenderer` for another PDF engine; `Widgets\InvoiceStatsWidget`, `InvoiceAgingWidget`.

## File manager

`php artisan alfakit:add file-manager` (migration only needed for stored public links; zip downloads need `ext-zip`). The page lives at `/{panel}/files`.

```php
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use AlfaSix\AlfakitFileManager\Forms\Components\FileManagerPicker;

$panel->plugin(
    FileManagerPlugin::make()
        ->disks(['public', 's3' => 'Cloud'])
        ->root(fn (Team $tenant): string => 'teams/'.$tenant->getKey())   // default on tenancy panels: tenants/{key}
        ->authorize(fn (User $user): bool => $user->can('manage-files'))
        ->can('delete', fn (User $user): bool => $user->is_admin)
        ->maxUploadSize(20 * 1024)
        ->trash(retentionDays: 30)
        ->shareLinks(),
);

FileManagerPicker::make('attachment')->disk('public')->directory('invoices')->acceptedFileTypes(['application/pdf']);
```

- Abilities for `can()`: `upload`, `createFolder`, `rename`, `move`, `copy`, `delete`, `restore`, `download`, `share`; `readOnly()` for browse / preview / download only. Other options: `acceptedFileTypes()`, `allowFileTypes()`, `blockedFileTypes()`, `zipDownloads()`, `rateLimits()`, `signedUrlLifetime()`, `publicLinks()`, `recursiveSearch()`, `defaultView()`, `usingPage()` (extend `Pages\FileManager`).
- The picker's state is the disk path (a list with `multiple()`); it needs the plugin on the panel. Registry keys `file-manager`, `FileManagerPicker`.

## Helpdesk

`php artisan alfakit:add helpdesk`, then `php artisan alfakit-helpdesk:pages` (Inertia page for the portal and contact form), schedule `alfakit-helpdesk:sla` every minute and run a queue worker.

```php
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Sla\BusinessHours;
use AlfaSix\AlfakitHelpdesk\Sla\SlaPolicy;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;

$panel->plugin(
    HelpdeskPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_support_agent)
        ->businessHours(BusinessHours::make('Asia/Bahrain')->days(['sun', 'mon', 'tue', 'wed', 'thu'], '08:00', '17:00'))
        ->slaPolicies([SlaPolicy::make('urgent')->priorities([TicketPriority::Urgent])->firstResponseWithin(30)->resolveWithin(240)]),
);

$ticket = Tickets::open(['subject' => 'Printer on fire', 'requester' => $user], '<p>Help!</p>');
Tickets::reply($ticket, '<p>On our way.</p>', author: $agent, status: TicketStatus::Pending);
```

- Panel: tickets resource with views (`mine`, `unassigned`, `due_soon`, `breached`…), Inbox page (`helpdesk:inbox`, live with `->realtime()`), canned replies with `{{ placeholders }}` (never evaluated), groups, reports widget. Outside panels: customer portal and contact form under `/support`, `<HelpdeskChat endpoint="/support/chat" />` live chat, reply by email (`inbound`, verified by `InboundMailVerifier`).
- Options: `agents()`, `publicTenant()`, `dueSoonMinutes()`, `inboxPollingInterval()`, `spatieTags()`, `inbox(false)`, `cannedReplies(false)`, `groups(false)`, `reports(false)`, resource / page overrides. Code: `Tickets::note()`, `assign()`, `changeStatus()`, `merge()`, `rate()`.

## Analytics

`php artisan alfakit:add analytics`, then add the snippet to your public layout and schedule `alfakit-analytics:aggregate` (hourly).

```php
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Analytics;

$panel->plugin(
    AnalyticsPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)  // plus a Site policy if registered
        ->scopeToTenant()            // default: sites per tenant
        ->sharing()                  // signed, read-only shared dashboards
        ->topListLimit(10)
        ->realtimePollingInterval('15s')
        ->navigationGroup('Marketing'),
);

// Public site (Blade): @alfakitAnalytics('site-key')   or   {{ Analytics::script($site) }}
// Inertia: <AlfakitAnalytics siteKey="site-key" />  (React/Vue/Svelte), trackAnalyticsEvent('Signup', {plan: 'pro'})
// HTML: <button data-alfakit-event="Download" data-alfakit-prop-file="guide.pdf">, window.alfakit.track(name, props)

Analytics::track('Signup', ['plan' => 'pro']);          // server-side, same visit as the browser
Analytics::pageview();                                   // or Route::middleware('alfakit-analytics[:key]')
Analytics::shareUrl($site, now()->addWeek());            // signed link (site must have is_shared)
```

- Sites: `Models\Site` (name, domain, domains, timezone, is_active, goals `[{type: page|event, match, name}]`, is_shared). Created in the Sites resource (`analytics-sites`) or in code (`->setTenant($team)`).
- Dashboard: page `analytics` (`AnalyticsDashboard`), URL state `?site=&period=today|yesterday|7d|30d|month|last_month|year|12mo|custom&from=&to=&compare=1&filter[source]=Google`. Filter keys: page, entry_page, exit_page, source, channel, referrer, utm_*, country, device, browser, os, event, goal; `(none)` matches empty values.
- Numbers in code: `Support\Stats::for($site, Support\Period::preset('7d', $site->getTimezone()), Support\Filters::from([...]))->totals() / timeseries() / breakdown('source')`.
- Config `alfakit-analytics.*`: route_prefix, rate_limit, default_site, respect_do_not_track, session_timeout, realtime_window, raw_retention_days, rollup_retention_days, schedule, country_header, bot_patterns, exclude_paths, max_properties.
- Countries: bind `Contracts\ResolvesCountry` (e.g. MaxMind GeoLite2) or set `country_header`.
- Command: `alfakit-analytics:aggregate [--days=3] [--from= --to=] [--site=key] [--no-prune]` (scheduled hourly).
- Frontend: registry key `analytics:breakdown` (widget type `AnalyticsBreakdown`), `data-slot="analytics-breakdown"` / `"analytics-row"`; translations under `analytics.*` via the plugin's frontendTranslations.
- Never send personal data in event properties.

## Mail templates

`php artisan alfakit:add mail-templates` (or `composer require alfasix/alfakit-mail-templates`, `vendor:publish --tag=alfakit-mail-templates-migrations`, `--tag=alfakit-record-versions-migrations` for the history, `migrate`).

```php
use AlfaSix\AlfakitMailTemplates\MailTemplatesPlugin;

$panel->plugin(
    MailTemplatesPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin) // else the manageAlfakitMailTemplates gate
        ->locales(['en', 'ar'])                                // default: the panel's locales
        ->navigationGroup('Settings'),
);
```

- Admins edit the subject and body per email and language (`alfakit_mail_templates`: key, locale, subject, body) with the email's merge tags; preview (light/dark, RTL), send test (to themselves only), reset to default, history (record versions). Bodies are sanitised on save and send; unknown merge tags fail validation.
- Every editable email of AlfaKit and its plugins is listed; your own: a mailable with `UsesEditableTemplate` registered with `Mails::register(MailDefinition::make('orders.shipped')->editable(mergeTags: [...]))` (see the development skill, Emails). A `pt_BR` email also uses a `pt` template; a subject-only template keeps the email's own content.
- Options: `authorize()`, `gate()`, `locales()`, `navigationGroup()`, `navigationSort()`, `navigationIcon()` (default `mail`), `usingResource()`.

## Security and authorisation

Plugins never trust the browser: every page, widget and action authorises on the server from the context token, and records are always resolved within the plugin's (tenant-scoped) query.

A plugin's `authorize(fn (User $user): bool => ...)` (and `canManageTokens()`, `canViewDocumentation()`, `sharing()`, `teamCreation()` closures) only runs for a signed-in user: guests are never authorised and the closure isn't called, so `User $user` can stay non-nullable (the guest login page asks resources about global search).

| Plugin                                  | Who may do what                                                                                                                                                                                                                                                                                                 |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shield                                  | Generated policies return `$authUser->can('{permission}')`. Until `alfakit-shield:generate` writes them, resources allow everything (unless the panel uses `strictAuthorization()`), so generate before granting access. Super admin passes via `Gate::before()` (or gets every permission with `gate: false`). |
| Billing                                 | The billable is the current tenant (panel tenancy already checks membership) or the signed-in user; no billable → 403. Hidden plans can't be checked out.                                                                                                                                                       |
| Media Library                           | Only UUIDs of the record's own collection are accepted back; private disks use temporary URLs.                                                                                                                                                                                                                  |
| Settings                                | `canAccess()` is checked on load, for every internal request and again in `save()`; it also hides the navigation item.                                                                                                                                                                                          |
| Activity log                            | `authorize()` if set, else the activity model's policy (`viewAny`, `view`); no policy → allowed unless `strictAuthorization()`. Read only.                                                                                                                                                                      |
| Backup, Health                          | `authorize()` guards the page, every action and the widgets; without it every panel user may. Backup downloads are signed, expiring URLs that re-check `authorize()` and only serve listed backups.                                                                                                             |
| Calendar                                | Records resolve within `getEloquentQuery()` (resource or tenant-scoped model). Drag/resize require `isEditable()` and `canEditEvent()` (the `update` ability). Actions use the resource / model policies.                                                                                                       |
| Tree                                    | Viewing needs the resource's `viewAny`; saving needs `canReorderTree()` (`reorder` ability; allowed when the policy lacks it, unless `strictAuthorization()`), then the structure is validated (scope, cycles, depth). Node actions are authorised per record.                                                  |
| Comments                                | `CommentPolicy`: `create` and `react` need `view` on the record, `update` / `delete` are the author's. Replace with `Gate::policy(Comment::class, MyCommentPolicy::class)`. Bodies are sanitised on save and display.                                                                                           |
| Tags                                    | Plain fields; access follows the page or resource they sit on.                                                                                                                                                                                                                                                  |
| Kanban                                  | Cards and moves resolve within `getKanbanQuery()` (resource / tenant-scoped); moving needs `canMoveCard()` (`update`); columns, transitions, strict WIP limits and lanes are validated on the server.                                                                                                           |
| Forms                                   | Admins: `authorize()` else the `Form` policy. Public: the definition is rebuilt and re-validated on every submit; formats are whitelisted names; downloads are signed per answer.                                                                                                                               |
| CMS                                     | `authorize(fn ($ability, $model, $record, $user))` else the `Page` / `Menu` / `Redirect` policies; `publish`, `submitForReview`, `restoreRevision` fall back to `update`. Rich text is sanitised, links and embeds allowlisted; public queries return only live content of the request's tenant.                |
| API                                     | Bearer token (hashed, per panel and tenant, expiring) → token abilities → resource policy on every call; input limited to writable form fields, output to the field whitelist.                                                                                                                                  |
| Webhooks                                | `authorize()` else the `WebhookEndpoint` policy; endpoints are tenant-owned. URLs pass an SSRF guard on save and before every attempt (https, public IPs only, pinned DNS, no redirects).                                                                                                                       |
| Ops, Translation manager, Feature flags | Deny by default: `authorize()` else the `viewAlfakitOps` / `manageTranslations` / `manageFeatureFlags` gate. Per-action `authorizeActions()` and `readOnly()`; Ops hides itself on tenancy panels.                                                                                                              |
| AI                                      | `authorize()` (default every signed-in user); prompts only contain fields the resource shows, redacted; nothing changes without the user confirming; copilot and MCP are off until enabled and run as the user.                                                                                                 |
| Teams                                   | `TeamPolicy` (role permissions, owner-only rules) for every page and `TeamManager` call; invitations are signed, single-use and bound to the invited e-mail.                                                                                                                                                    |
| Workflows                               | Transitions: `authorize()`, else a `transition()` policy method, else `update`; decisions only by the assigned approver; everything re-checked on a locked row.                                                                                                                                                 |
| Invoices                                | `authorize()` + `InvoicePolicy` abilities (`send`, `recordPayment`, `void`, …) and document state; totals never trusted from the browser; customer links are signed.                                                                                                                                            |
| File manager                            | `authorize()`, per-ability `can()`, `readOnly()`; every path normalised under the tenant's root; uploads type-checked from their contents.                                                                                                                                                                      |
| Helpdesk                                | Agents: `authorize()` + `TicketPolicy`; customers only reach their own tickets and never internal notes; inbound mail refused until a verifier secret is set.                                                                                                                                                   |

## Pitfalls

- Namespaces are `AlfaSix\AlfakitShield\...`, `AlfaSix\AlfakitBilling\...` etc., not `AlfaSix\Alfakit\Shield\...`, and never `Filament\...`.
- Install the underlying Spatie / Cashier / Pennant package's migrations and model traits; the AlfaKit plugin doesn't do it for you (`alfakit:add` publishes the Spatie migrations it knows about).
- Plugins that run work later need a queue worker or the scheduler: webhooks, forms / helpdesk / invoices / teams mail, `alfakit-workflows:process`, `alfakit-helpdesk:sla`, `alfakit-webhooks:prune`.
- Forms and Helpdesk render outside the panel: generate their Inertia page (`alfakit-forms:page`, `alfakit-helpdesk:pages`) and load the standalone styles. The generated pages opt out of the app's default Inertia layout (no starter-kit `AppLayout` sidebar): React `Page.layout = (page: ReactNode) => <>{page}</>`, Vue `defineOptions({ layout: (_h: unknown, page: unknown) => page as VNode })`, Svelte `<script module>` `export const layout = (_h: unknown, page: unknown) => page`; delete it to use the app layout.
- Customer-facing pages and emails (CMS site, public forms, helpdesk portal and chat, shared analytics dashboards, invoice links/documents/emails) show no AlfaKit branding by default. `CmsPlugin` / `FormsPlugin` / `HelpdeskPlugin` / `AnalyticsPlugin` / `InvoicesPlugin` `::make()->poweredBy()` (bool|Closure|null) adds a "Powered by AlfaKit · Alfa Six" line; unset, they follow `config('alfakit.credit.public_pages')` / `config('alfakit.credit.emails')`. Custom form/portal pages render `meta.poweredBy` (HTML or null).
- Media, tags and comments fields are not attributes: don't add them to `$fillable` or cast them.
- Backups need a queue worker; health results need the scheduled `health:check`; Stripe subscriptions need the Cashier webhook.
- Don't re-implement any of these in JavaScript; extend the PHP classes (`RoleResource`, `ActivityResource`, `Backups`, `Health`, `Checkout`, `FormResource`, `WebhookEndpointResource`, `TeamMembers`, `FileManager`, …) and pass them to the plugin.
