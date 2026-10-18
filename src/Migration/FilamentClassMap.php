<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration;

/**
 * What `alfakit:migrate-from-filament` knows about FilamentPHP v3, v4 and v5: which
 * Filament classes become which AlfaKit classes, which have no equivalent (and what to use
 * instead), which fluent methods are renamed or removed, and how plugins, icons and colours
 * translate. Shared by the Rector set (`rector/filament-to-alfakit.php`) and the token
 * rewriter used when Rector is not installed.
 *
 * Every target in {@see classes()} is a real AlfaKit class; `MigrateFromFilamentTest` verifies
 * that with `class_exists()` so the map cannot drift from the source.
 */
final class FilamentClassMap
{
    public const ALFAKIT = 'AlfaSix\\Alfakit\\';

    /**
     * Filament class → AlfaKit class, covering v3 (`Filament\Forms\Form`, `Filament\Tables\Actions\*`,
     * `Filament\Forms\Components\Section`…), v4 and v5 (`Filament\Schemas\*`, `Filament\Actions\*`,
     * `Filament\Auth\*`).
     *
     * @return array<class-string|string, class-string|string>
     */
    public static function classes(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $a = self::ALFAKIT;

        $map = [
            // Panel
            'Filament\\Panel' => $a.'Panel',
            'Filament\\PanelProvider' => $a.'PanelProvider',
            'Filament\\Facades\\Filament' => $a.'Facades\\Alfakit',
            'Filament\\Contracts\\Plugin' => $a.'Contracts\\Plugin',
            'Filament\\Clusters\\Cluster' => $a.'Clusters\\Cluster',
            'Filament\\Events\\ServingFilament' => $a.'Events\\ServingAlfakit',
            'Filament\\Events\\TenantSet' => $a.'Events\\TenantSet',
            'Filament\\Billing\\Providers\\Contracts\\BillingProvider' => $a.'Billing\\Providers\\Contracts\\BillingProvider',
            'Filament\\GlobalSearch\\GlobalSearchResult' => $a.'GlobalSearch\\GlobalSearchResult',
            'Filament\\GlobalSearch\\Providers\\Contracts\\GlobalSearchProvider' => $a.'GlobalSearch\\GlobalSearchProvider',
            'Filament\\GlobalSearch\\Contracts\\GlobalSearchProvider' => $a.'GlobalSearch\\GlobalSearchProvider',
            'Filament\\Http\\Middleware\\Authenticate' => $a.'Http\\Middleware\\Authenticate',
            'Filament\\Http\\Middleware\\IdentifyTenant' => $a.'Http\\Middleware\\IdentifyTenant',
            'Filament\\Http\\Middleware\\SetUpPanel' => $a.'Http\\Middleware\\SetUpPanel',
            'Filament\\Http\\Middleware\\AuthenticateSession' => 'Illuminate\\Session\\Middleware\\AuthenticateSession',

            // User model contracts
            'Filament\\Models\\Contracts\\FilamentUser' => $a.'Contracts\\CanAccessPanel',
            'Filament\\Models\\Contracts\\HasAvatar' => $a.'Contracts\\HasAvatar',
            'Filament\\Models\\Contracts\\HasName' => $a.'Contracts\\HasName',
            'Filament\\Models\\Contracts\\HasTenants' => $a.'Contracts\\HasTenants',
            'Filament\\Models\\Contracts\\HasDefaultTenant' => $a.'Contracts\\HasDefaultTenant',
            'Filament\\Models\\Contracts\\HasCurrentTenantLabel' => $a.'Contracts\\HasCurrentTenantLabel',

            // Navigation
            'Filament\\Navigation\\MenuItem' => $a.'Navigation\\MenuItem',
            'Filament\\Navigation\\NavigationBuilder' => $a.'Navigation\\NavigationBuilder',
            'Filament\\Navigation\\NavigationGroup' => $a.'Navigation\\NavigationGroup',
            'Filament\\Navigation\\NavigationItem' => $a.'Navigation\\NavigationItem',
            'Filament\\Pages\\Enums\\SubNavigationPosition' => $a.'Navigation\\SubNavigationPosition',
            'Filament\\Pages\\SubNavigationPosition' => $a.'Navigation\\SubNavigationPosition',

            // Pages
            'Filament\\Pages\\Page' => $a.'Pages\\Page',
            'Filament\\Pages\\BasePage' => $a.'Pages\\Page',
            'Filament\\Pages\\SimplePage' => $a.'Pages\\Page',
            'Filament\\Pages\\Dashboard' => $a.'Pages\\Dashboard',
            'Filament\\Pages\\Dashboard\\Concerns\\HasFiltersForm' => $a.'Pages\\Dashboard\\Concerns\\HasFiltersForm',
            'Filament\\Pages\\Dashboard\\Concerns\\HasFiltersAction' => $a.'Pages\\Dashboard\\Concerns\\HasFiltersAction',
            'Filament\\Pages\\Dashboard\\Actions\\FilterAction' => $a.'Pages\\Dashboard\\Actions\\FilterAction',
            'Filament\\Support\\Enums\\SlideOverPosition' => $a.'Support\\Enums\\SlideOverPosition',
            'Filament\\Enums\\DatabaseNotificationsPosition' => $a.'Support\\Enums\\DatabaseNotificationsPosition',
            'Filament\\Enums\\GlobalSearchPosition' => $a.'Support\\Enums\\GlobalSearchPosition',
            'Filament\\Enums\\UserMenuPosition' => $a.'Support\\Enums\\UserMenuPosition',
            'Filament\\Resources\\RelationManagers\\RelationManagerConfiguration' => $a.'Resources\\RelationManagers\\RelationManagerConfiguration',
            'Filament\\Pages\\PageConfiguration' => $a.'Pages\\PageConfiguration',
            'Filament\\Pages\\Concerns\\InteractsWithFormActions' => $a.'Pages\\Concerns\\InteractsWithSchemas',
            'Filament\\Pages\\Concerns\\HasSubNavigation' => $a.'Pages\\Concerns\\HasSubNavigation',
            'Filament\\Pages\\Tenancy\\EditTenantProfile' => $a.'Tenancy\\Pages\\EditTenantProfile',
            'Filament\\Pages\\Tenancy\\RegisterTenant' => $a.'Tenancy\\Pages\\RegisterTenant',
            'Filament\\Widgets\\Concerns\\InteractsWithPageFilters' => $a.'Widgets\\Concerns\\InteractsWithPageFilters',
            'Filament\\Widgets\\Concerns\\InteractsWithPageTable' => $a.'Widgets\\Concerns\\InteractsWithPageTable',
            'Filament\\Pages\\Concerns\\ExposesTableToWidgets' => $a.'Pages\\Concerns\\ExposesTableToWidgets',

            // Auth pages (v3 `Filament\Pages\Auth\*`, v4 `Filament\Auth\Pages\*`)
            'Filament\\Pages\\Auth\\Login' => $a.'Auth\\Pages\\Login',
            'Filament\\Pages\\Auth\\Register' => $a.'Auth\\Pages\\Register',
            'Filament\\Pages\\Auth\\EditProfile' => $a.'Auth\\Pages\\EditProfile',
            'Filament\\Pages\\Auth\\EmailVerification\\EmailVerificationPrompt' => $a.'Auth\\Pages\\EmailVerificationPrompt',
            'Filament\\Pages\\Auth\\PasswordReset\\RequestPasswordReset' => $a.'Auth\\Pages\\RequestPasswordReset',
            'Filament\\Pages\\Auth\\PasswordReset\\ResetPassword' => $a.'Auth\\Pages\\ResetPassword',
            'Filament\\Auth\\Pages\\Login' => $a.'Auth\\Pages\\Login',
            'Filament\\Auth\\Pages\\Register' => $a.'Auth\\Pages\\Register',
            'Filament\\Auth\\Pages\\EditProfile' => $a.'Auth\\Pages\\EditProfile',
            'Filament\\Auth\\Pages\\EmailVerification\\EmailVerificationPrompt' => $a.'Auth\\Pages\\EmailVerificationPrompt',
            'Filament\\Auth\\Pages\\PasswordReset\\RequestPasswordReset' => $a.'Auth\\Pages\\RequestPasswordReset',
            'Filament\\Auth\\Pages\\PasswordReset\\ResetPassword' => $a.'Auth\\Pages\\ResetPassword',
            'Filament\\Auth\\MultiFactor\\App\\AppAuthentication' => $a.'Auth\\MultiFactor\\App\\AppAuthentication',
            'Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthentication' => $a.'Auth\\MultiFactor\\App\\Contracts\\HasAppAuthentication',
            'Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthenticationRecovery' => $a.'Auth\\MultiFactor\\App\\Contracts\\HasAppAuthenticationRecovery',
            'Filament\\Auth\\MultiFactor\\Email\\EmailAuthentication' => $a.'Auth\\MultiFactor\\Email\\EmailAuthentication',
            'Filament\\Auth\\MultiFactor\\Email\\Contracts\\HasEmailAuthentication' => $a.'Auth\\MultiFactor\\Email\\Contracts\\HasEmailAuthentication',
            'Filament\\Auth\\MultiFactor\\Contracts\\MultiFactorAuthenticationProvider' => $a.'Auth\\MultiFactor\\Contracts\\MultiFactorAuthenticationProvider',
            'Filament\\Auth\\MultiFactor\\MultiFactorChallenge' => $a.'Auth\\MultiFactor\\MultiFactorChallenge',
            'Filament\\Auth\\Notifications\\ResetPassword' => $a.'Auth\\Notifications\\ResetPassword',
            'Filament\\Auth\\Notifications\\VerifyEmailChange' => $a.'Auth\\Notifications\\VerifyEmailChange',
            'Filament\\Auth\\Notifications\\NoticeOfEmailChangeRequest' => $a.'Auth\\Notifications\\NoticeOfEmailChangeRequest',

            // Resources
            'Filament\\Resources\\Resource' => $a.'Resources\\Resource',
            'Filament\\Resources\\ResourceConfiguration' => $a.'Resources\\ResourceConfiguration',
            'Filament\\Resources\\Pages\\Enums\\ContentTabPosition' => $a.'Resources\\Pages\\Enums\\ContentTabPosition',
            'Filament\\Resources\\Pages\\Page' => $a.'Resources\\Pages\\Page',
            'Filament\\Resources\\Pages\\CreateRecord' => $a.'Resources\\Pages\\CreateRecord',
            'Filament\\Resources\\Pages\\EditRecord' => $a.'Resources\\Pages\\EditRecord',
            'Filament\\Resources\\Pages\\ListRecords' => $a.'Resources\\Pages\\ListRecords',
            'Filament\\Resources\\Pages\\ManageRecords' => $a.'Resources\\Pages\\ListRecords',
            'Filament\\Resources\\Pages\\ViewRecord' => $a.'Resources\\Pages\\ViewRecord',
            'Filament\\Resources\\Pages\\ManageRelatedRecords' => $a.'Resources\\Pages\\ManageRelatedRecords',
            'Filament\\Resources\\Pages\\PageRegistration' => $a.'Pages\\PageRegistration',
            'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers' => $a.'Resources\\Pages\\Concerns\\HasRelationManagers',
            'Filament\\Resources\\Pages\\Concerns\\InteractsWithRecord' => $a.'Resources\\Pages\\Concerns\\InteractsWithRecord',
            'Filament\\Resources\\Pages\\ListRecords\\Concerns\\HasTabs' => $a.'Resources\\Pages\\Concerns\\HasTabs',
            'Filament\\Resources\\Pages\\ListRecords\\Tab' => $a.'Schemas\\Components\\Tabs\\Tab',
            'Filament\\Resources\\Pages\\CreateRecord\\Concerns\\HasWizard' => $a.'Resources\\Pages\\CreateRecord\\Concerns\\HasWizard',
            'Filament\\Schemas\\Components\\EmbeddedSchema' => $a.'Schemas\\Components\\EmbeddedSchema',
            'Filament\\Schemas\\Components\\EmbeddedTable' => $a.'Schemas\\Components\\EmbeddedTable',
            'Filament\\Widgets\\ChartWidget\\Concerns\\HasFiltersSchema' => $a.'Widgets\\ChartWidget\\Concerns\\HasFiltersSchema',
            'Filament\\Resources\\Components\\Tab' => $a.'Schemas\\Components\\Tabs\\Tab',
            'Filament\\Resources\\ParentResourceRegistration' => $a.'Resources\\ParentResourceRegistration',
            'Filament\\Resources\\RelationManagers\\RelationManager' => $a.'Resources\\RelationManagers\\RelationManager',
            'Filament\\Resources\\RelationManagers\\RelationGroup' => $a.'Resources\\RelationManagers\\RelationGroup',

            // Widgets
            'Filament\\Widgets\\Widget' => $a.'Widgets\\Widget',
            'Filament\\Widgets\\ChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\LineChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\BarChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\PieChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\DoughnutChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\PolarAreaChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\RadarChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\ScatterChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\BubbleChartWidget' => $a.'Widgets\\ChartWidget',
            'Filament\\Widgets\\StatsOverviewWidget' => $a.'Widgets\\StatsOverviewWidget',
            'Filament\\Widgets\\StatsOverviewWidget\\Stat' => $a.'Widgets\\StatsOverviewWidget\\Stat',
            'Filament\\Widgets\\StatsOverviewWidget\\Card' => $a.'Widgets\\StatsOverviewWidget\\Stat',
            'Filament\\Widgets\\TableWidget' => $a.'Widgets\\TableWidget',

            // Schemas (v4/v5) and the v3 form / infolist roots and layout components
            'Filament\\Schemas\\Schema' => $a.'Schemas\\Schema',
            'Filament\\Forms\\Form' => $a.'Schemas\\Schema',
            'Filament\\Infolists\\Infolist' => $a.'Schemas\\Schema',
            'Filament\\Schemas\\Components\\Component' => $a.'Schemas\\Components\\Component',
            'Filament\\Forms\\Components\\Component' => $a.'Schemas\\Components\\Component',
            'Filament\\Infolists\\Components\\Component' => $a.'Schemas\\Components\\Component',
            'Filament\\Schemas\\Components\\Utilities\\Get' => $a.'Schemas\\Components\\Utilities\\Get',
            'Filament\\Schemas\\Components\\Utilities\\Set' => $a.'Schemas\\Components\\Utilities\\Set',
            'Filament\\Forms\\Get' => $a.'Schemas\\Components\\Utilities\\Get',
            'Filament\\Forms\\Set' => $a.'Schemas\\Components\\Utilities\\Set',
            ...self::layoutComponents($a, 'Filament\\Schemas\\Components\\'),
            ...self::layoutComponents($a, 'Filament\\Forms\\Components\\'),
            ...self::layoutComponents($a, 'Filament\\Infolists\\Components\\'),
            'Filament\\Schemas\\Components\\Callout' => $a.'Schemas\\Components\\Callout',
            'Filament\\Schemas\\Components\\EmptyState' => $a.'Schemas\\Components\\EmptyState',
            'Filament\\Schemas\\Components\\Flex' => $a.'Schemas\\Components\\Flex',
            'Filament\\Schemas\\Components\\FusedGroup' => $a.'Schemas\\Components\\FusedGroup',
            'Filament\\Schemas\\Components\\Html' => $a.'Schemas\\Components\\Html',
            'Filament\\Schemas\\Components\\Icon' => $a.'Schemas\\Components\\Icon',
            'Filament\\Schemas\\Components\\Image' => $a.'Schemas\\Components\\Image',
            'Filament\\Schemas\\Components\\Text' => $a.'Schemas\\Components\\Text',
            'Filament\\Schemas\\Components\\UnorderedList' => $a.'Schemas\\Components\\UnorderedList',
            'Filament\\Schemas\\Components\\Actions' => $a.'Schemas\\Components\\Actions',
            'Filament\\Forms\\Components\\Actions' => $a.'Schemas\\Components\\Actions',
            'Filament\\Infolists\\Components\\Actions' => $a.'Schemas\\Components\\Actions',
            'Filament\\Forms\\Components\\Placeholder' => $a.'Schemas\\Components\\Placeholder',
            'Filament\\Forms\\Components\\Actions\\Action' => $a.'Actions\\Action',
            'Filament\\Infolists\\Components\\Actions\\Action' => $a.'Actions\\Action',

            // Form fields
            ...self::sameNames($a, 'Forms\\Components\\', [
                'Builder', 'Builder\\Block', 'Checkbox', 'CheckboxList', 'CodeEditor', 'CodeEditor\\Enums\\Language',
                'ColorPicker', 'DatePicker', 'DateTimePicker', 'Field', 'FileUpload', 'Hidden', 'KeyValue',
                'MarkdownEditor', 'ModalTableSelect', 'MorphToSelect', 'MorphToSelect\\Type', 'Radio', 'Repeater',
                'Repeater\\TableColumn', 'RichEditor',
                'RichEditor\\MentionProvider', 'RichEditor\\RichContentCustomBlock', 'RichEditor\\RichContentRenderer',
                'RichEditor\\EditorCommand', 'RichEditor\\RichContentAttribute', 'RichEditor\\RichEditorTool', 'RichEditor\\ToolbarButtonGroup',
                'RichEditor\\Models\\Contracts\\HasRichContent', 'RichEditor\\Models\\Concerns\\InteractsWithRichContent',
                'RichEditor\\Plugins\\Contracts\\RichContentPlugin',
                'Select', 'Slider', 'Slider\\Enums\\Behavior', 'Slider\\Enums\\PipsMode', 'TagsInput', 'TextInput', 'Textarea', 'TimePicker', 'Toggle', 'ToggleButtons',
            ]),
            'Filament\\Forms\\Components\\BaseFileUpload' => $a.'Forms\\Components\\FileUpload',
            'Filament\\Forms\\Components\\MultiSelect' => $a.'Forms\\Components\\Select',
            'Filament\\Forms\\Components\\OneTimeCodeInput' => $a.'Forms\\Components\\OneTimeCodeInput',
            'Filament\\Forms\\Components\\RichEditor\\Contracts\\CanGenerateFakeConfiguration' => $a.'Forms\\Components\\RichEditor\\Contracts\\CanGenerateFakeConfiguration',
            'Filament\\Forms\\Components\\RichEditor\\RichContentFakerProvider' => $a.'Forms\\Components\\RichEditor\\RichContentFakerProvider',
            'Filament\\Forms\\Components\\RichEditor\\RichContentFaker' => $a.'Forms\\Components\\RichEditor\\RichContentFaker',
            'Filament\\Forms\\Components\\RichEditor\\FileAttachmentProviders\\Contracts\\FileAttachmentProvider' => $a.'Forms\\Components\\RichEditor\\FileAttachmentProviders\\Contracts\\FileAttachmentProvider',
            'Filament\\Forms\\Components\\RelationshipRepeater' => $a.'Forms\\Components\\Repeater',
            'Filament\\Forms\\Components\\TableSelect' => $a.'Forms\\Components\\ModalTableSelect',

            // Infolist entries
            ...self::sameNames($a, 'Infolists\\Components\\', [
                'CodeEntry', 'ColorEntry', 'Entry', 'IconEntry', 'ImageEntry', 'KeyValueEntry', 'RepeatableEntry',
                'RepeatableEntry\\TableColumn', 'TextEntry', 'ViewEntry',
            ]),

            // Tables
            'Filament\\Tables\\Table' => $a.'Tables\\Table',
            ...self::sameNames($a, 'Tables\\Columns\\', [
                'CheckboxColumn', 'ColorColumn', 'Column', 'ColumnGroup', 'IconColumn', 'ImageColumn', 'SelectColumn',
                'TextColumn', 'TextInputColumn', 'ToggleColumn', 'ViewColumn',
                'Layout\\Component', 'Layout\\Grid', 'Layout\\Panel', 'Layout\\Split', 'Layout\\Stack',
                'Summarizers\\Average', 'Summarizers\\Count', 'Summarizers\\Range', 'Summarizers\\Sum', 'Summarizers\\Summarizer',
            ]),
            'Filament\\Tables\\Columns\\BadgeColumn' => $a.'Tables\\Columns\\TextColumn',
            'Filament\\Tables\\Columns\\BooleanColumn' => $a.'Tables\\Columns\\IconColumn',
            'Filament\\Tables\\Columns\\TagsColumn' => $a.'Tables\\Columns\\TextColumn',
            'Filament\\Tables\\Columns\\Layout\\View' => $a.'Tables\\Columns\\ViewColumn',
            ...self::sameNames($a, 'Tables\\Filters\\', [
                'BaseFilter', 'Filter', 'SelectFilter', 'TernaryFilter', 'TrashedFilter', 'QueryBuilder',
                'QueryBuilder\\Constraints\\BooleanConstraint', 'QueryBuilder\\Constraints\\Constraint',
                'QueryBuilder\\Constraints\\DateConstraint', 'QueryBuilder\\Constraints\\NumberConstraint',
                'QueryBuilder\\Constraints\\RelationshipConstraint', 'QueryBuilder\\Constraints\\SelectConstraint',
                'QueryBuilder\\Constraints\\TextConstraint', 'Indicator',
            ]),
            'Filament\\Tables\\Filters\\MultiSelectFilter' => $a.'Tables\\Filters\\SelectFilter',
            'Filament\\Tables\\Grouping\\Group' => $a.'Tables\\Grouping\\Group',
            ...self::sameNames($a, 'Tables\\Enums\\', ['FiltersLayout', 'PaginationMode', 'RecordActionsPosition', 'ColumnManagerLayout', 'ColumnManagerResetActionPosition', 'FiltersResetActionPosition']),
            'Filament\\Tables\\Enums\\ActionsPosition' => $a.'Tables\\Enums\\RecordActionsPosition',

            // Actions (v4/v5 `Filament\Actions\*`, v3 `Filament\Tables\Actions\*`)
            ...self::actions($a, 'Filament\\Actions\\'),
            ...self::actions($a, 'Filament\\Tables\\Actions\\'),
            'Filament\\Actions\\StaticAction' => $a.'Actions\\Action',
            'Filament\\Actions\\ButtonAction' => $a.'Actions\\Action',
            'Filament\\Actions\\IconButtonAction' => $a.'Actions\\Action',
            'Filament\\Actions\\SelectAction' => $a.'Actions\\Action',
            'Filament\\Actions\\Exports\\ExportColumn' => $a.'Actions\\Exports\\ExportColumn',
            'Filament\\Actions\\Exports\\Exporter' => $a.'Actions\\Exports\\Exporter',
            'Filament\\Actions\\Exports\\Models\\Export' => $a.'Actions\\Exports\\Models\\Export',
            'Filament\\Actions\\Exports\\Enums\\ExportFormat' => $a.'Actions\\Exports\\Enums\\ExportFormat',
            'Filament\\Actions\\Imports\\ImportColumn' => $a.'Actions\\Imports\\ImportColumn',
            'Filament\\Actions\\Imports\\Importer' => $a.'Actions\\Imports\\Importer',
            'Filament\\Actions\\Imports\\Models\\Import' => $a.'Actions\\Imports\\Models\\Import',
            'Filament\\Actions\\Imports\\Models\\FailedImportRow' => $a.'Actions\\Imports\\Models\\FailedImportRow',
            'Filament\\Actions\\Imports\\Exceptions\\RowImportFailedException' => $a.'Actions\\Imports\\Exceptions\\RowImportFailedException',
            'Filament\\Support\\Exceptions\\Halt' => $a.'Actions\\Exceptions\\Halt',
            'Filament\\Support\\Exceptions\\Cancel' => $a.'Actions\\Exceptions\\Cancel',

            // Notifications
            'Filament\\Notifications\\Notification' => $a.'Notifications\\Notification',
            'Filament\\Notifications\\DatabaseNotification' => $a.'Notifications\\DatabaseNotification',
            'Filament\\Notifications\\Actions\\Action' => $a.'Notifications\\Actions\\Action',
            'Filament\\Notifications\\Events\\DatabaseNotificationsSent' => $a.'Notifications\\BroadcastNotification',

            // Support
            ...self::sameNames($a, 'Support\\Enums\\', ['Alignment', 'IconPosition', 'Operation', 'Size', 'VerticalAlignment', 'Width']),
            'Filament\\Support\\Enums\\MaxWidth' => $a.'Support\\Enums\\Width',
            'Filament\\Support\\Enums\\ThemeMode' => $a.'Theming\\ThemeMode',
            'Filament\\AvatarProviders\\UiAvatarsProvider' => $a.'AvatarProviders\\UiAvatarsProvider',
            'Filament\\AvatarProviders\\Contracts\\AvatarProvider' => $a.'AvatarProviders\\Contracts\\AvatarProvider',
            'Filament\\Support\\Enums\\ActionSize' => $a.'Support\\Enums\\Size',
            'Filament\\Support\\Assets\\Asset' => $a.'Support\\Assets\\Asset',
            'Filament\\Support\\Assets\\Css' => $a.'Support\\Assets\\Css',
            'Filament\\Support\\Assets\\Js' => $a.'Support\\Assets\\Js',
            'Filament\\Support\\Facades\\FilamentIcon' => $a.'Support\\Icons',
            // Enum contracts (labels, colours, icons, descriptions)
            ...self::sameNames($a, 'Support\\Contracts\\', ['HasLabel', 'HasColor', 'HasIcon', 'HasDescription']),
            'Filament\\Support\\Concerns\\EvaluatesClosures' => $a.'Support\\Concerns\\EvaluatesClosures',
            'Filament\\Support\\Concerns\\Configurable' => $a.'Support\\Concerns\\Configurable',
            'Filament\\Support\\Components\\Component' => $a.'Schemas\\Components\\Component',
        ];

        return $map;
    }

    /**
     * Filament classes with no AlfaKit equivalent: removed from the code (imports, array
     * entries) and listed in the report with the alternative.
     *
     * @return array<string, string>
     */
    public static function unsupported(): array
    {
        $livewire = 'Livewire-only: AlfaKit forms, tables and actions outside panels live in `AlfaSix\\Alfakit\\Standalone\\Form|Table|Infolist|Component` classes rendered with `<AlfakitComponent>` in your own Inertia page (docs: standalone/overview).';
        $blade = 'Blade/Alpine rendering has no equivalent: build a frontend component for your renderer and reference it with `View::make(\'name\')` or a custom field/column/entry (`make:alfakit-field|column|entry`), or eject a built-in component with `alfakit:eject`.';

        return [
            'Filament\\Widgets\\AccountWidget' => 'Removed from the dashboard: the user menu in the topbar shows the account and profile links; `->profile()` on the panel adds the profile page.',
            'Filament\\Widgets\\FilamentInfoWidget' => 'Removed from the dashboard (Filament-specific). Create your own widget with `make:alfakit-widget` if you need a welcome card.',
            'Filament\\Http\\Middleware\\DisableBladeIconComponents' => 'Removed: AlfaKit does not render Blade icons.',
            'Filament\\Http\\Middleware\\DispatchServingFilamentEvent' => 'Removed: `SetUpPanel` dispatches `ServingAlfakit` itself; listen with `Alfakit::serving()`.',
            'Filament\\Http\\Middleware\\IdentifyPageConfiguration' => 'Removed: not needed by AlfaKit.',
            'Filament\\Http\\Middleware\\IdentifyResourceConfiguration' => 'Removed: not needed by AlfaKit.',
            'Filament\\Support\\Colors\\Color' => 'Colours are CSS values: `->colors([\'primary\' => \'#2563eb\'])`; `Color::Amber` was converted to the Tailwind hex shade.',
            'Filament\\Support\\Icons\\Heroicon' => 'Icons are Lucide names (`\'users\'`); `Heroicon::OutlinedUsers` was converted. Check the result against https://lucide.dev/icons.',
            'Filament\\Actions\\BulkActionGroup' => 'Bulk actions are listed directly in `->bulkActions([...])`; the group wrapper was unwrapped.',
            'Filament\\Tables\\Actions\\BulkActionGroup' => 'Bulk actions are listed directly in `->bulkActions([...])`; the group wrapper was unwrapped.',
            'Filament\\Forms\\Contracts\\HasForms' => $livewire,
            'Filament\\Forms\\Concerns\\InteractsWithForms' => $livewire,
            'Filament\\Tables\\Contracts\\HasTable' => $livewire,
            'Filament\\Tables\\Concerns\\InteractsWithTable' => $livewire,
            'Filament\\Actions\\Contracts\\HasActions' => $livewire,
            'Filament\\Actions\\Concerns\\InteractsWithActions' => $livewire,
            'Filament\\Infolists\\Contracts\\HasInfolists' => $livewire,
            'Filament\\Infolists\\Concerns\\InteractsWithInfolists' => $livewire,
            'Filament\\Schemas\\Contracts\\HasSchemas' => $livewire,
            'Filament\\Schemas\\Concerns\\InteractsWithSchemas' => $livewire,
            'Filament\\Forms\\Components\\Livewire' => $livewire,
            'Filament\\Infolists\\Components\\Livewire' => $livewire,
            'Filament\\Schemas\\Components\\Livewire' => $livewire,
            'Filament\\Forms\\Components\\ViewField' => $blade,
            'Filament\\Schemas\\Components\\View' => 'Use `AlfaSix\\Alfakit\\Schemas\\Components\\View::make(\'component-name\')` with a frontend component registered in `createAlfakit({ components })`; the Blade view cannot be rendered.',
            'Filament\\Schemas\\Components\\RenderHook' => 'Render hooks are slots: `->slot(Slot::TOPBAR_END, ...)` on the panel (docs: design/slots).',
            'Filament\\Schemas\\Components\\Form' => 'Pages have one form (`form(Schema $schema)`); Action modals take `->schema([...])`.',
            'Filament\\Tables\\Columns\\Summarizers\\Values' => 'Use a custom `Summarizer::make()->using(...)`.',
            'Filament\\Tables\\Enums\\RecordCheckboxPosition' => 'The selection checkbox is always at the start of the row.',
            'Filament\\Actions\\Enums\\ActionStatus' => 'Not needed: actions return normally or throw `Halt` / `Cancel`.',
            'Filament\\Support\\Enums\\FontWeight' => 'Text weight is a string: `->weight(\'bold\')`.',
            'Filament\\Support\\Enums\\FontFamily' => 'Font family is a string: `->fontFamily(\'mono\')`.',
            'Filament\\Support\\Enums\\TextSize' => 'Text size is a string: `->size(\'sm\')`.',
            'Filament\\Support\\Enums\\IconSize' => 'Icon size is a string: `->size(\'lg\')`.',
            'Filament\\Support\\Enums\\GridDirection' => 'Grid direction is not configurable; use `->columns()`.',
            'Filament\\Support\\Enums\\Platform' => 'Keyboard shortcut platforms are not configurable.',
            'Filament\\Support\\Enums\\ArgumentValue' => 'Not needed.',
            'Filament\\Support\\Facades\\FilamentAsset' => 'Register plugin assets with `AlfaSix\\Alfakit\\Support\\Assets\\Assets::register([Css::make(...), Js::make(...)], package: \'vendor/name\')`.',
            'Filament\\Support\\Facades\\FilamentColor' => 'Register colours for every panel with `AlfaSix\\Alfakit\\Support\\Colors\\Colors::register([...])`, or per panel with `->colors([...])`.',
            'Filament\\Support\\Facades\\FilamentView' => 'Render hooks are slots: `FilamentView::registerRenderHook()` became `Slots::register()`; other `FilamentView` calls have no equivalent (docs: design/slots).',
            'Filament\\Support\\Facades\\FilamentTimezone' => 'Use `->timezone()` on the panel; formatting follows it automatically.',
            'Filament\\Support\\Facades\\FilamentCli' => 'Not needed.',
            'Filament\\Support\\Assets\\AlpineComponent' => $blade,
            'Filament\\Support\\Assets\\Theme' => 'Themes are classes: `php artisan make:alfakit-theme <name>` and `->theme(...)` on the panel (docs: design/themes).',
            'Filament\\Support\\Assets\\Font' => 'Use `->font(\'Inter\')` on the panel (Google Fonts or a self-hosted provider).',
            'Filament\\Support\\RawJs' => 'No JavaScript expressions in PHP: use `->live()` and server-side closures, or a custom frontend component. Chart options can name a registered formatter instead (`\'js:money\'` with `createAlfakit({ chartFormatters })`).',
            'Filament\\Support\\View\\Components\\Modal' => $blade,
            'Filament\\GlobalSearch\\GlobalSearchResults' => 'A `GlobalSearchProvider::getResults()` returns an array of `GlobalSearchResult` grouped by resource.',
            'Filament\\GlobalSearch\\Actions\\Action' => 'Global search result actions are URL actions (`getGlobalSearchResultActions()` returning `Action::make()->url()`).',
            'Filament\\Resources\\Concerns\\Translatable' => 'Built in: `->translatable()` on `TextInput`, `Textarea` and `RichEditor` (docs: localization/translatable-content).',
            'Filament\\Resources\\Pages\\ListRecords\\Concerns\\Translatable' => 'Built in: `->translatable()` on fields (docs: localization/translatable-content).',
            'Filament\\Actions\\LocaleSwitcher' => 'Built in: `->translatable()` fields switch locale themselves; the panel has `->locales([...])`.',
            'Filament\\Tables\\Actions\\HeaderActionsPosition' => 'Header actions have one position.',
            'Filament\\Tables\\Columns\\Concerns\\CanBeToggled' => 'Use `->toggleable()` on the column.',
            'Filament\\Forms\\ComponentContainer' => 'Use `AlfaSix\\Alfakit\\Schemas\\Schema`.',
            'Filament\\Infolists\\ComponentContainer' => 'Use `AlfaSix\\Alfakit\\Schemas\\Schema`.',
            'Filament\\Panel\\Concerns\\HasRenderHooks' => 'Render hooks are slots (docs: design/slots).',
            'Filament\\View\\PanelsRenderHook' => 'This render hook has no slot; see the `AlfaSix\\Alfakit\\Theming\\Slot` constants (docs: design/slots).',
            'Filament\\Tables\\View\\TablesRenderHook' => 'This table render hook has no slot; see the `AlfaSix\\Alfakit\\Theming\\Slot::TABLE_*` constants (docs: design/slots).',
            'Filament\\Widgets\\View\\WidgetsRenderHook' => 'This widget render hook has no slot; see `Slot::TABLE_WIDGET_START/END` (docs: design/slots).',
            'Filament\\Actions\\View\\ActionsRenderHook' => 'This action render hook has no slot; see `Slot::MODAL_SCHEMA_BEFORE/AFTER`, `Action::modalContent()` and `modalContentFooter()` (docs: design/slots).',
            'Filament\\Forms\\Components\\RichEditor\\FileAttachmentProviders\\SpatieMediaLibraryFileAttachmentProvider' => 'Use the default disk provider (`DiskFileAttachmentProvider`) or implement `FileAttachmentProvider` for your media library.',
            'Filament\\Schemas\\JsContent' => 'No JavaScript content in PHP: use a custom frontend component.',
        ];
    }

    /**
     * Filament render hook constants → `AlfaSix\Alfakit\Theming\Slot` constant names, per
     * hook class. `->renderHook(PanelsRenderHook::X, …)` becomes `->slot(Slot::Y, …)` and
     * `FilamentView::registerRenderHook()` becomes `Slots::register()`; hooks missing here
     * have no slot and are reported.
     *
     * @return array<string, array<string, string>>
     */
    public static function renderHooks(): array
    {
        $same = static fn (string ...$names): array => array_combine($names, $names);

        return [
            'Filament\View\PanelsRenderHook' => [
                ...$same(
                    'AUTH_LOGIN_FORM_AFTER', 'AUTH_LOGIN_FORM_BEFORE', 'AUTH_REGISTER_FORM_AFTER', 'AUTH_REGISTER_FORM_BEFORE',
                    'AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER', 'AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE',
                    'AUTH_PASSWORD_RESET_RESET_FORM_AFTER', 'AUTH_PASSWORD_RESET_RESET_FORM_BEFORE',
                    'BODY_END', 'BODY_START', 'CONTENT_AFTER', 'CONTENT_BEFORE', 'CONTENT_END', 'CONTENT_START', 'FOOTER',
                    'GLOBAL_SEARCH_AFTER', 'GLOBAL_SEARCH_BEFORE', 'GLOBAL_SEARCH_END', 'GLOBAL_SEARCH_START',
                    'HEAD_END', 'HEAD_START', 'LAYOUT_END', 'LAYOUT_START', 'PAGE_END', 'PAGE_START',
                    'PAGE_FOOTER_WIDGETS_AFTER', 'PAGE_FOOTER_WIDGETS_BEFORE', 'PAGE_HEADER_WIDGETS_AFTER', 'PAGE_HEADER_WIDGETS_BEFORE',
                    'PAGE_HEADER_HEADING_AFTER', 'PAGE_HEADER_HEADING_BEFORE',
                    'PAGE_SUB_NAVIGATION_END_AFTER', 'PAGE_SUB_NAVIGATION_END_BEFORE', 'PAGE_SUB_NAVIGATION_SIDEBAR_AFTER', 'PAGE_SUB_NAVIGATION_SIDEBAR_BEFORE',
                    'PAGE_SUB_NAVIGATION_START_AFTER', 'PAGE_SUB_NAVIGATION_START_BEFORE', 'PAGE_SUB_NAVIGATION_TOP_AFTER', 'PAGE_SUB_NAVIGATION_TOP_BEFORE',
                    'RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER', 'RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE',
                    'RESOURCE_PAGES_LIST_RECORDS_TABS_END', 'RESOURCE_PAGES_LIST_RECORDS_TABS_START',
                    'RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER', 'RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE',
                    'RESOURCE_RELATION_MANAGER_AFTER', 'RESOURCE_RELATION_MANAGER_BEFORE', 'RESOURCE_TABS_END', 'RESOURCE_TABS_START',
                    'SIDEBAR_FOOTER', 'SIDEBAR_LOGO_AFTER', 'SIDEBAR_LOGO_BEFORE', 'SIDEBAR_NAV_END', 'SIDEBAR_NAV_START', 'SIDEBAR_START',
                    'SIMPLE_LAYOUT_END', 'SIMPLE_LAYOUT_START', 'SIMPLE_PAGE_END', 'SIMPLE_PAGE_START',
                    'TENANT_MENU_AFTER', 'TENANT_MENU_BEFORE', 'TOPBAR_AFTER', 'TOPBAR_BEFORE', 'TOPBAR_END', 'TOPBAR_START',
                    'TOPBAR_LOGO_AFTER', 'TOPBAR_LOGO_BEFORE', 'USER_MENU_AFTER', 'USER_MENU_BEFORE',
                    'USER_MENU_PROFILE_AFTER', 'USER_MENU_PROFILE_BEFORE',
                ),
                'PAGE_HEADER_ACTIONS_BEFORE' => 'PAGE_ACTIONS_BEFORE',
                'PAGE_HEADER_ACTIONS_AFTER' => 'PAGE_ACTIONS_AFTER',
                'PAGE_HEADER_WIDGETS_START' => 'PAGE_HEADER_WIDGETS_BEFORE',
                'PAGE_HEADER_WIDGETS_END' => 'PAGE_HEADER_WIDGETS_AFTER',
                'PAGE_FOOTER_WIDGETS_START' => 'PAGE_FOOTER_WIDGETS_BEFORE',
                'PAGE_FOOTER_WIDGETS_END' => 'PAGE_FOOTER_WIDGETS_AFTER',
                'STYLES_BEFORE' => 'HEAD_START',
                'STYLES_AFTER' => 'HEAD_END',
                'SCRIPTS_BEFORE' => 'BODY_END',
                'SCRIPTS_AFTER' => 'BODY_END',
            ],
            'Filament\Tables\View\TablesRenderHook' => [
                'TOOLBAR_START' => 'TABLE_TOOLBAR_START',
                'TOOLBAR_END' => 'TABLE_TOOLBAR_END',
                'TOOLBAR_SEARCH_BEFORE' => 'TABLE_TOOLBAR_SEARCH_BEFORE',
                'TOOLBAR_SEARCH_AFTER' => 'TABLE_TOOLBAR_SEARCH_AFTER',
                'HEADER_BEFORE' => 'TABLE_HEADER_BEFORE',
                'HEADER_AFTER' => 'TABLE_HEADER_AFTER',
                'FILTER_INDICATORS' => 'TABLE_FILTER_INDICATORS',
                'TOOLBAR_BEFORE' => 'TABLE_TOOLBAR_BEFORE',
                'TOOLBAR_AFTER' => 'TABLE_TOOLBAR_AFTER',
                'CONTENT_BEFORE' => 'TABLE_CONTENT_BEFORE',
                'CONTENT_AFTER' => 'TABLE_CONTENT_AFTER',
                'HEADER_CELL' => 'TABLE_HEADER_CELL',
                'SELECTION_INDICATOR_ACTIONS_BEFORE' => 'TABLE_SELECTION_INDICATOR_ACTIONS_BEFORE',
                'SELECTION_INDICATOR_ACTIONS_AFTER' => 'TABLE_SELECTION_INDICATOR_ACTIONS_AFTER',
                'TOOLBAR_GROUPING_SELECTOR_BEFORE' => 'TABLE_TOOLBAR_GROUPING_SELECTOR_BEFORE',
                'TOOLBAR_GROUPING_SELECTOR_AFTER' => 'TABLE_TOOLBAR_GROUPING_SELECTOR_AFTER',
                'TOOLBAR_REORDER_TRIGGER_BEFORE' => 'TABLE_TOOLBAR_REORDER_TRIGGER_BEFORE',
                'TOOLBAR_REORDER_TRIGGER_AFTER' => 'TABLE_TOOLBAR_REORDER_TRIGGER_AFTER',
                'TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE' => 'TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE',
                'TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER' => 'TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER',
                'TOOLBAR_TOGGLE_COLUMN_TRIGGER_BEFORE' => 'TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE',
                'TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER' => 'TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER',
            ],
            'Filament\Widgets\View\WidgetsRenderHook' => [
                'TABLE_WIDGET_START' => 'TABLE_WIDGET_START',
                'TABLE_WIDGET_END' => 'TABLE_WIDGET_END',
            ],
            'Filament\Actions\View\ActionsRenderHook' => [
                'MODAL_SCHEMA_BEFORE' => 'MODAL_SCHEMA_BEFORE',
                'MODAL_SCHEMA_AFTER' => 'MODAL_SCHEMA_AFTER',
            ],
        ];
    }

    /**
     * The `Slot` constant for a Filament render hook (`'Filament\View\PanelsRenderHook'`,
     * `'TOPBAR_END'`), or null when it has no slot.
     */
    public static function slotFor(string $hookClass, string $hook): ?string
    {
        return self::renderHooks()[ltrim($hookClass, '\\')][$hook] ?? null;
    }

    /**
     * Fluent methods that are renamed. `[method => [newMethod, argumentsSource|null]]`: when the
     * arguments source is set the original arguments are dropped and replaced.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function renamedMethods(): array
    {
        return [
            'groupedBulkActions' => ['bulkActions', null],
            'lazy' => ['live', 'onBlur: true'],
            'toggledHiddenByDefault' => ['toggleable', 'isToggledHiddenByDefault: true'],
            'toggleColumnsTriggerAction' => ['columnManagerTriggerAction', null],
            'reactive' => ['live', null],
        ];
    }

    /**
     * Methods renamed only on a given class: AlfaKit class basename → [method → new method]
     * (`Tab::make()->query(...)` on list pages is `modifyQueryUsing()`).
     *
     * @return array<string, array<string, string>>
     */
    public static function classMethodRenames(): array
    {
        return [
            'Tab' => ['query' => 'modifyQueryUsing'],
        ];
    }

    /**
     * Fluent methods with no equivalent, removed from the chain (the code keeps working) and
     * reported with the reason.
     *
     * @return array<string, string>
     */
    public static function removedMethods(): array
    {
        $cosmetic = 'no equivalent in the renderers (cosmetic); removed';

        return [
            'extraAlpineAttributes' => 'Alpine attributes are not rendered: build a custom frontend component (`make:alfakit-field|column|entry`) or eject the built-in one (`alfakit:eject`); removed',
            'livewire' => 'Livewire components cannot be embedded: use `View::make()` with a frontend component; removed',
            'livewireClickHandlerEnabled' => $cosmetic,
            'alpineClickHandler' => 'client-side JavaScript is not rendered: use `->action()` or `->url()`; removed',
            'dispatch' => 'Livewire events are not available: use `->action()` and `Notification`; removed',
            'dispatchSelf' => 'Livewire events are not available; removed',
            'dispatchTo' => 'Livewire events are not available; removed',
            'js' => 'JavaScript expressions are not rendered; removed',
            'jsHandler' => 'JavaScript strings are not evaluated: use `RichEditorTool::command()` or register a frontend command (`createAlfakit({ richEditorExtensions })`, docs: forms/rich-editor#plugins); removed',
            'headerActionsPosition' => $cosmetic,
            'recordCheckboxPosition' => $cosmetic,
            'iconSize' => 'use `->size()`; removed',
            'moveFiles' => 'temporary uploads are always moved; removed',
            'gridDirection' => $cosmetic,
            'weekStartsOn' => 'the locale decides the first day of the week; removed',
            'addBetweenAction' => $cosmetic,
            'databaseNotificationsPolling' => 'the polling interval is `config(\'alfakit.notifications.polling_interval\')`; removed',
            'monoFont' => 'the mono font comes from the theme; removed',
            'serifFont' => 'the serif font comes from the theme; removed',
            'lazyLoadedDatabaseNotifications' => $cosmetic,
            'simpleProfilePage' => 'use `->profile(isSimple: true)`; removed',
            'sidebarLivewireComponent' => 'replace the sidebar with a `panel:sidebar` frontend component (`alfakit:eject panel:sidebar`); removed',
            'topbarLivewireComponent' => 'replace the topbar with a `panel:topbar` frontend component (`alfakit:eject panel:topbar`); removed',
            'databaseNotificationsLivewireComponent' => $cosmetic,
            'discoverLivewireComponents' => 'Livewire components are not rendered: move them to standalone components or pages; removed',
            'livewireComponents' => 'Livewire components are not rendered: move them to standalone components or pages; removed',
            'persistentMiddleware' => 'Inertia pages run the full middleware stack; removed',
            'broadcasting' => 'use `->realtimeNotifications()` and `window.Echo` in the frontend (docs: notifications/overview); removed',
            'emailVerifiedMiddlewareName' => $cosmetic,
            'multiFactorAuthenticationRequiredMiddlewareName' => $cosmetic,
            'requiresEmailVerification' => 'use `->emailVerification()`; removed',
            'requiresMultiFactorAuthentication' => 'use `->multiFactorAuthentication([...], isRequired: true)`; removed',
            'registerAssets' => 'use `Assets::register([...])`; removed',
            'resolveTenantUsing' => 'use `->tenant()` with a slug attribute or `->tenantDomain()`; removed',
        ];
    }

    /**
     * Removed methods whose removal changes nothing visible (cosmetic options, defaults AlfaKit
     * already has): reported as information, not as manual work.
     *
     * @return array<int, string>
     */
    public static function harmlessRemovedMethods(): array
    {
        return [
            'livewireClickHandlerEnabled', 'headerActionsPosition', 'recordCheckboxPosition', 'iconSize', 'moveFiles',
            'gridDirection', 'weekStartsOn', 'addBetweenAction', 'databaseNotificationsPolling', 'monoFont', 'serifFont',
            'lazyLoadedDatabaseNotifications', 'databaseNotificationsLivewireComponent', 'persistentMiddleware',
            'emailVerifiedMiddlewareName', 'multiFactorAuthenticationRequiredMiddlewareName',
        ];
    }

    /**
     * Panel methods that need a manual decision: removed from the chain and reported.
     *
     * @return array<string, string>
     */
    public static function removedPanelMethods(): array
    {
        return [
            'renderHook' => 'This render hook has no slot, so the call was removed. Render hooks with a slot became `->slot(Slot::…, …)` (docs: design/slots).',
            'viteTheme' => 'Filament theme CSS cannot be reused: run `php artisan make:alfakit-theme <name>` and register it with `->theme(<Name>Theme::make())` (docs: design/themes). The call was removed.',
            'routes' => 'Add panel routes in `routes/web.php` under the panel path, or as pages. The call was removed.',
            'authenticatedRoutes' => 'Add panel routes in `routes/web.php` under the panel path, or as pages. The call was removed.',
            'tenantRoutes' => 'Add tenant routes in `routes/web.php` (`Alfakit::getTenant()`), or as pages. The call was removed.',
            'authenticatedTenantRoutes' => 'Add tenant routes in `routes/web.php` (`Alfakit::getTenant()`), or as pages. The call was removed.',
            'icons' => 'Icon aliases are registered with `AlfaSix\\Alfakit\\Support\\Icons::register([...])`. The call was removed.',
            'plugins' => 'Plugins were removed from the panel; see the plugin section of the report.',
            'plugin' => 'The plugin was removed from the panel; see the plugin section of the report.',
        ];
    }

    /**
     * Standard Filament panel middleware; a `->middleware([...])` made only of these becomes `['web']`.
     *
     * @return array<int, string>
     */
    public static function standardWebMiddleware(): array
    {
        return [
            'Illuminate\\Cookie\\Middleware\\EncryptCookies',
            'Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse',
            'Illuminate\\Session\\Middleware\\StartSession',
            'Illuminate\\Session\\Middleware\\AuthenticateSession',
            'Illuminate\\View\\Middleware\\ShareErrorsFromSession',
            'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
            'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
            'Illuminate\\Routing\\Middleware\\SubstituteBindings',
            'Filament\\Http\\Middleware\\AuthenticateSession',
            'Filament\\Http\\Middleware\\DisableBladeIconComponents',
            'Filament\\Http\\Middleware\\DispatchServingFilamentEvent',
            'Filament\\Http\\Middleware\\IdentifyPageConfiguration',
            'Filament\\Http\\Middleware\\IdentifyResourceConfiguration',
        ];
    }

    /**
     * Composer packages: Filament's own (removed) and the popular plugins with what replaces them.
     * Plugins with a first-party AlfaKit plugin name it; {@see pluginPackages()} has the slug for
     * `php artisan alfakit:add`.
     *
     * @return array<string, string>
     */
    public static function plugins(): array
    {
        $plugins = [
            'filament/filament' => 'Replaced by alfasix/alfakit.',
            'filament/forms' => 'Part of alfasix/alfakit.',
            'filament/tables' => 'Part of alfasix/alfakit.',
            'filament/actions' => 'Part of alfasix/alfakit.',
            'filament/infolists' => 'Part of alfasix/alfakit.',
            'filament/notifications' => 'Part of alfasix/alfakit.',
            'filament/widgets' => 'Part of alfasix/alfakit.',
            'filament/schemas' => 'Part of alfasix/alfakit.',
            'filament/support' => 'Part of alfasix/alfakit.',
            'filament/query-builder' => 'Part of alfasix/alfakit (`QueryBuilder` filter).',
            'filament/upgrade' => 'Not needed; `php artisan alfakit:upgrade` runs after updates.',
            'filament/spatie-laravel-translatable-plugin' => 'Built in: `->translatable()` on `TextInput`, `Textarea` and `RichEditor` with spatie/laravel-translatable (docs: localization/translatable-content). `LocaleSwitcher` is not needed.',
            'filament/spatie-laravel-google-fonts-plugin' => 'Built in: `->font(\'Inter\')` on the panel.',
            'stechstudio/filament-impersonate' => 'Built in: `ImpersonateAction`, `CanImpersonate` / `CanBeImpersonated` (docs: panels/impersonation); `Impersonate` actions were converted.',
            'pxlrbt/filament-excel' => 'Built in: `ExportAction` / `ExportBulkAction` with an `Exporter` (`make:alfakit-exporter`) producing CSV and XLSX.',
            'konnco/filament-import' => 'Built in: `ImportAction` with an `Importer` (`make:alfakit-importer`).',
            'eightynine/filament-excel-import' => 'Built in: `ImportAction` with an `Importer` (`make:alfakit-importer`).',
            'jeffgreco13/filament-breezy' => 'Built in: `->profile()`, `->multiFactorAuthentication([AppAuthentication::make(), EmailAuthentication::make()])`, `->emailChangeVerification()`.',
            'joaopaulolndev/filament-edit-profile' => 'Built in: `->profile()` and the `EditProfile` page.',
            'bezhansalleh/filament-language-switch' => 'Built in: `->locales([...])` on the panel adds the language switcher.',
            'awcodes/filament-tiptap-editor' => 'Built in: `RichEditor` (JSON content, custom blocks, mentions, merge tags).',
            'awcodes/filament-table-repeater' => 'Built in: `Repeater::make()->table([TableColumn::make(...)])`.',
            'leandrocfe/filament-apex-charts' => 'Built in: `ChartWidget` (line, bar, pie, doughnut, radar, polar area).',
            'livewire/livewire' => 'Only needed by Livewire components you keep; AlfaKit pages render through Inertia.',
        ];

        foreach (self::pluginPackages() as $package => [$slug, $note]) {
            $plugins[$package] ??= "First-party plugin: `php artisan alfakit:add {$slug}` (alfasix/alfakit-{$slug}). {$note}";
        }

        return $plugins;
    }

    /**
     * Third-party Filament plugins with a first-party AlfaKit plugin: package → [slug, what to know].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pluginPackages(): array
    {
        return [
            'bezhansalleh/filament-shield' => ['shield', 'Roles and permissions on Laravel policies with spatie/laravel-permission; `HasPageShield` / `HasWidgetShield` were converted.'],
            'althinect/filament-spatie-roles-permissions' => ['shield', 'Roles and permissions on Laravel policies with spatie/laravel-permission.'],
            'filament/spatie-laravel-media-library-plugin' => ['media-library', '`SpatieMediaLibraryFileUpload`, `SpatieMediaLibraryImageColumn` and `SpatieMediaLibraryImageEntry` keep their names in the plugin.'],
            'awcodes/filament-curator' => ['media-library', 'Media manager on spatie/laravel-medialibrary.'],
            'ralphjsmit/laravel-filament-media-library' => ['media-library', 'Media manager on spatie/laravel-medialibrary.'],
            'filament/spatie-laravel-tags-plugin' => ['tags', '`SpatieTagsInput`, `SpatieTagsColumn` and `SpatieTagsEntry` keep their names in the plugin.'],
            'filament/spatie-laravel-settings-plugin' => ['settings', 'Settings pages on spatie/laravel-settings (`make:alfakit-settings-page`).'],
            'kirschbaum-development/commentions' => ['comments', '`CommentsAction`, `Commentable` and `HasComments` were converted.'],
            'parallax/filament-comments' => ['comments', 'Comments on any record with mentions and reactions.'],
            'mokhosh/filament-kanban' => ['kanban', 'Boards are a `KanbanPage` on a resource or a `KanbanWidget`.'],
            'relaticle/flowforge' => ['kanban', 'Boards are a `KanbanPage` on a resource or a `KanbanWidget`.'],
            'saade/filament-fullcalendar' => ['calendar', '`FullCalendarWidget` became `CalendarWidget` (`fetchEvents(array $info)`).'],
            'guava/calendar' => ['calendar', 'Calendars are a `CalendarWidget` (`fetchEvents(array $info)`).'],
            'rmsramos/activitylog' => ['activity-log', 'Activity log resource and relation manager on spatie/laravel-activitylog.'],
            'pxlrbt/filament-activity-log' => ['activity-log', 'Activity log resource and relation manager on spatie/laravel-activitylog.'],
            'z3d0x/filament-logger' => ['activity-log', 'Activity log resource and relation manager on spatie/laravel-activitylog.'],
            'filament/spatie-laravel-activitylog-plugin' => ['activity-log', 'Activity log resource and relation manager on spatie/laravel-activitylog.'],
            'solution-forest/filament-tree' => ['tree', 'Tree pages and widgets (`TreePage`, `ModelTree`).'],
            '15web/filament-tree' => ['tree', 'Tree pages and widgets (`TreePage`, `ModelTree`).'],
            'shuvroroy/filament-spatie-laravel-backup' => ['backup', 'Backups page on spatie/laravel-backup.'],
            'shuvroroy/filament-spatie-laravel-health' => ['health', 'Health checks page on spatie/laravel-health.'],
            'filament/spark-billing-provider' => ['billing', 'Tenant billing with Laravel Cashier.'],
            'stephenjude/filament-feature-flags' => ['feature-flags', 'Feature flags on Laravel Pennant.'],
            'kenepa/translation-manager' => ['translation-manager', 'Edit translations in the panel.'],
            'lara-zeus/bolt' => ['forms', 'Form builder with a public form page.'],
            'lara-zeus/sky' => ['cms', 'Pages, posts and navigation.'],
            'rupadana/filament-api-service' => ['api', 'REST API for your resources.'],
        ];
    }

    /**
     * Root namespaces of popular Filament plugins. Code using them is migrated and reported like
     * Filament's own classes (and discovered even when it does not mention `Filament\`).
     *
     * @return array<int, string>
     */
    public static function pluginNamespaces(): array
    {
        return [
            'BezhanSalleh\\FilamentShield', 'Kirschbaum\\Commentions', 'Mokhosh\\FilamentKanban', 'Saade\\FilamentFullCalendar',
            'Rmsramos\\Activitylog', 'SolutionForest\\FilamentTree', 'STS\\FilamentImpersonate', 'Awcodes\\Curator',
            'pxlrbt\\FilamentExcel', 'Jeffgreco13\\FilamentBreezy', 'Relaticle\\Flowforge', 'Guava\\Calendar',
            'Parallax\\FilamentComments', 'Z3d0x\\FilamentLogger', 'ShuvroRoy\\FilamentSpatieLaravelBackup',
            'ShuvroRoy\\FilamentSpatieLaravelHealth', 'BezhanSalleh\\FilamentLanguageSwitch', 'Leandrocfe\\FilamentApexCharts',
        ];
    }

    /**
     * Plugin classes with an AlfaKit equivalent: class → [AlfaKit class, first-party plugin slug or null for core].
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function pluginClasses(): array
    {
        $a = self::ALFAKIT;

        return [
            'Filament\\Forms\\Components\\SpatieMediaLibraryFileUpload' => ['AlfaSix\\AlfakitMediaLibrary\\Forms\\Components\\SpatieMediaLibraryFileUpload', 'media-library'],
            'Filament\\Infolists\\Components\\SpatieMediaLibraryImageEntry' => ['AlfaSix\\AlfakitMediaLibrary\\Infolists\\Components\\SpatieMediaLibraryImageEntry', 'media-library'],
            'Filament\\Tables\\Columns\\SpatieMediaLibraryImageColumn' => ['AlfaSix\\AlfakitMediaLibrary\\Tables\\Columns\\SpatieMediaLibraryImageColumn', 'media-library'],
            'Filament\\Forms\\Components\\SpatieTagsInput' => ['AlfaSix\\AlfakitTags\\Forms\\Components\\SpatieTagsInput', 'tags'],
            'Filament\\Infolists\\Components\\SpatieTagsEntry' => ['AlfaSix\\AlfakitTags\\Infolists\\Components\\SpatieTagsEntry', 'tags'],
            'Filament\\Tables\\Columns\\SpatieTagsColumn' => ['AlfaSix\\AlfakitTags\\Tables\\Columns\\SpatieTagsColumn', 'tags'],
            'STS\\FilamentImpersonate\\Actions\\Impersonate' => [$a.'Actions\\ImpersonateAction', null],
            'STS\\FilamentImpersonate\\Tables\\Actions\\Impersonate' => [$a.'Actions\\ImpersonateAction', null],
            'STS\\FilamentImpersonate\\Pages\\Actions\\Impersonate' => [$a.'Actions\\ImpersonateAction', null],
            'Saade\\FilamentFullCalendar\\Widgets\\FullCalendarWidget' => ['AlfaSix\\AlfakitCalendar\\Widgets\\CalendarWidget', 'calendar'],
            'Kirschbaum\\Commentions\\Filament\\Actions\\CommentsAction' => ['AlfaSix\\AlfakitComments\\Actions\\CommentsAction', 'comments'],
            'Kirschbaum\\Commentions\\Filament\\Actions\\CommentsTableAction' => ['AlfaSix\\AlfakitComments\\Actions\\CommentsTableAction', 'comments'],
            'Kirschbaum\\Commentions\\Contracts\\Commentable' => ['AlfaSix\\AlfakitComments\\Contracts\\Commentable', 'comments'],
            'Kirschbaum\\Commentions\\HasComments' => ['AlfaSix\\AlfakitComments\\Concerns\\HasComments', 'comments'],
            'BezhanSalleh\\FilamentShield\\Traits\\HasPageShield' => ['AlfaSix\\AlfakitShield\\Traits\\HasPageShield', 'shield'],
            'BezhanSalleh\\FilamentShield\\Traits\\HasWidgetShield' => ['AlfaSix\\AlfakitShield\\Traits\\HasWidgetShield', 'shield'],
            'BezhanSalleh\\FilamentShield\\Traits\\HasPanelShield' => ['AlfaSix\\AlfakitShield\\Traits\\HasPanelShield', 'shield'],
            'BezhanSalleh\\FilamentShield\\Contracts\\HasShieldPermissions' => ['AlfaSix\\AlfakitShield\\Contracts\\HasShieldPermissions', 'shield'],
            'SolutionForest\\FilamentTree\\Concern\\ModelTree' => ['AlfaSix\\AlfakitTree\\Concerns\\ModelTree', 'tree'],
        ];
    }

    /**
     * PHPDoc fixes in subclasses of a converted plugin base, where the AlfaKit parent passes
     * richer types: AlfaKit parent → [regex → replacement].
     *
     * @return array<string, array<string, string>>
     */
    public static function pluginDocblockFixes(): array
    {
        return [
            // Saade passes the visible range as strings; the calendar plugin as CarbonImmutable.
            'AlfaSix\\AlfakitCalendar\\Widgets\\CalendarWidget' => [
                '/\b(start|end):\s*string\b/' => '$1: \\Carbon\\CarbonImmutable',
            ],
        ];
    }

    /**
     * Plugin base classes without a drop-in equivalent: the subclass gets an AlfaKit base so the
     * app keeps booting, plus a TODO with how to rebuild it. class → [AlfaKit base, slug, how].
     *
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function pluginParents(): array
    {
        $a = self::ALFAKIT;

        return [
            'Mokhosh\\FilamentKanban\\Pages\\KanbanBoard' => [$a.'Pages\\Page', 'kanban', 'Rebuild the board as a `KanbanPage` on the resource (`$resource`, `$statusEnum`) or a `KanbanWidget` (`$model`, `$statusEnum`) after `php artisan alfakit:add kanban`'],
            'Relaticle\\Flowforge\\BoardPage' => [$a.'Pages\\Page', 'kanban', 'Rebuild the board as a `KanbanPage` on the resource or a `KanbanWidget` after `php artisan alfakit:add kanban`'],
            'SolutionForest\\FilamentTree\\Pages\\TreePage' => [$a.'Pages\\Page', 'tree', 'Rebuild it as `AlfaSix\\AlfakitTree\\Pages\\TreePage` after `php artisan alfakit:add tree`'],
            'SolutionForest\\FilamentTree\\Widgets\\Tree' => [$a.'Widgets\\Widget', 'tree', 'Rebuild it as `AlfaSix\\AlfakitTree\\Widgets\\TreeWidget` after `php artisan alfakit:add tree`'],
        ];
    }

    /**
     * Filament enums whose cases become plain strings in AlfaKit (`FontWeight::Bold` → `'bold'`).
     *
     * @return array<string, array<string, string>>
     */
    public static function enumStrings(): array
    {
        $sizes = ['ExtraSmall' => 'xs', 'Small' => 'sm', 'Medium' => 'md', 'Large' => 'lg', 'ExtraLarge' => 'xl', 'TwoExtraLarge' => '2xl'];

        return [
            'Filament\\Support\\Enums\\FontWeight' => [
                'Thin' => 'thin', 'ExtraLight' => 'extralight', 'Light' => 'light', 'Normal' => 'normal', 'Medium' => 'medium',
                'SemiBold' => 'semibold', 'Bold' => 'bold', 'ExtraBold' => 'extrabold', 'Black' => 'black',
            ],
            'Filament\\Support\\Enums\\FontFamily' => ['Sans' => 'sans', 'Serif' => 'serif', 'Mono' => 'mono'],
            'Filament\\Support\\Enums\\TextSize' => $sizes,
            'Filament\\Infolists\\Components\\TextEntry\\TextEntrySize' => $sizes,
            'Filament\\Tables\\Columns\\TextColumn\\TextColumnSize' => $sizes,
            'Filament\\Support\\Enums\\IconSize' => $sizes,
        ];
    }

    /**
     * Methods a Filament contract names differently (`HasAvatar::getFilamentAvatarUrl()`).
     *
     * @return array<string, string>
     */
    public static function renamedContractMethods(): array
    {
        return [
            'getFilamentAvatarUrl' => 'getAlfakitAvatarUrl',
            'getFilamentName' => 'getAlfakitName',
        ];
    }

    /**
     * The first-party plugin a panel plugin class (`FilamentShieldPlugin`) is replaced by, or null.
     */
    public static function pluginSlugFor(string $class): ?string
    {
        $lower = strtolower(ltrim($class, '\\'));

        foreach ([
            'shield' => 'shield', 'commention' => 'comments', 'filamentcomments' => 'comments', 'kanban' => 'kanban', 'flowforge' => 'kanban',
            'fullcalendar' => 'calendar', 'guava\\calendar' => 'calendar', 'activitylog' => 'activity-log', 'logger' => 'activity-log',
            'filamenttree' => 'tree', 'curator' => 'media-library', 'medialibrary' => 'media-library', 'spatielaraveltags' => 'tags',
            'spatielaravelsettings' => 'settings', 'backup' => 'backup', 'health' => 'health',
        ] as $needle => $slug) {
            if (str_contains($lower, $needle)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Heroicon → Lucide names where they differ. Anything else keeps its kebab-case name,
     * which is often identical (`users`, `home`, `bell`, `calendar`, …) and is listed in the report
     * so it can be checked against lucide.dev.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return [
            'academic-cap' => 'graduation-cap',
            'adjustments-horizontal' => 'sliders-horizontal',
            'adjustments-vertical' => 'sliders-vertical',
            'archive-box' => 'archive',
            'archive-box-arrow-down' => 'archive',
            'archive-box-x-mark' => 'archive-x',
            'arrow-down-tray' => 'download',
            'arrow-up-tray' => 'upload',
            'arrow-left-on-rectangle' => 'log-out',
            'arrow-left-start-on-rectangle' => 'log-out',
            'arrow-right-on-rectangle' => 'log-in',
            'arrow-right-end-on-rectangle' => 'log-in',
            'arrow-path' => 'refresh-cw',
            'arrow-top-right-on-square' => 'external-link',
            'arrow-uturn-left' => 'undo-2',
            'arrow-uturn-right' => 'redo-2',
            'arrows-right-left' => 'arrow-left-right',
            'arrows-up-down' => 'arrow-up-down',
            'arrows-pointing-out' => 'maximize-2',
            'arrows-pointing-in' => 'minimize-2',
            'bars-3' => 'menu',
            'bars-4' => 'menu',
            'bars-3-bottom-left' => 'align-left',
            'bars-arrow-down' => 'arrow-down-wide-narrow',
            'bars-arrow-up' => 'arrow-up-narrow-wide',
            'banknotes' => 'banknote',
            'bell-alert' => 'bell-ring',
            'bell-slash' => 'bell-off',
            'bolt' => 'zap',
            'bolt-slash' => 'zap-off',
            'book-open' => 'book-open',
            'bookmark-slash' => 'bookmark-x',
            'briefcase' => 'briefcase',
            'building-office' => 'building',
            'building-office-2' => 'building-2',
            'building-storefront' => 'store',
            'building-library' => 'landmark',
            'calendar-days' => 'calendar-days',
            'chart-bar' => 'bar-chart-3',
            'chart-bar-square' => 'bar-chart-2',
            'chart-pie' => 'pie-chart',
            'chat-bubble-left' => 'message-square',
            'chat-bubble-left-right' => 'messages-square',
            'chat-bubble-left-ellipsis' => 'message-square-more',
            'chat-bubble-bottom-center-text' => 'message-square-text',
            'chat-bubble-oval-left' => 'message-circle',
            'chat-bubble-oval-left-ellipsis' => 'message-circle-more',
            'check-badge' => 'badge-check',
            'check-circle' => 'circle-check',
            'chevron-double-down' => 'chevrons-down',
            'chevron-double-left' => 'chevrons-left',
            'chevron-double-right' => 'chevrons-right',
            'chevron-double-up' => 'chevrons-up',
            'chevron-up-down' => 'chevrons-up-down',
            'clipboard-document' => 'clipboard-copy',
            'clipboard-document-check' => 'clipboard-check',
            'clipboard-document-list' => 'clipboard-list',
            'cog' => 'settings',
            'cog-6-tooth' => 'settings',
            'cog-8-tooth' => 'settings-2',
            'command-line' => 'terminal',
            'computer-desktop' => 'monitor',
            'cpu-chip' => 'cpu',
            'credit-card' => 'credit-card',
            'cube' => 'box',
            'cube-transparent' => 'box',
            'currency-dollar' => 'dollar-sign',
            'currency-euro' => 'euro',
            'currency-pound' => 'pound-sterling',
            'currency-yen' => 'japanese-yen',
            'cursor-arrow-rays' => 'mouse-pointer-click',
            'device-phone-mobile' => 'smartphone',
            'device-tablet' => 'tablet',
            'document' => 'file',
            'document-arrow-down' => 'file-down',
            'document-arrow-up' => 'file-up',
            'document-check' => 'file-check',
            'document-duplicate' => 'files',
            'document-magnifying-glass' => 'file-search',
            'document-minus' => 'file-minus',
            'document-plus' => 'file-plus',
            'document-text' => 'file-text',
            'document-chart-bar' => 'file-bar-chart',
            'ellipsis-horizontal' => 'ellipsis',
            'ellipsis-vertical' => 'ellipsis-vertical',
            'envelope' => 'mail',
            'envelope-open' => 'mail-open',
            'exclamation-circle' => 'circle-alert',
            'exclamation-triangle' => 'triangle-alert',
            'eye-slash' => 'eye-off',
            'face-smile' => 'smile',
            'face-frown' => 'frown',
            'finger-print' => 'fingerprint',
            'fire' => 'flame',
            'folder-arrow-down' => 'folder-down',
            'folder-minus' => 'folder-minus',
            'folder-open' => 'folder-open',
            'folder-plus' => 'folder-plus',
            'gift' => 'gift',
            'gift-top' => 'gift',
            'globe-alt' => 'globe',
            'globe-americas' => 'globe',
            'globe-asia-australia' => 'globe',
            'globe-europe-africa' => 'globe',
            'hand-raised' => 'hand',
            'hand-thumb-up' => 'thumbs-up',
            'hand-thumb-down' => 'thumbs-down',
            'home-modern' => 'home',
            'identification' => 'id-card',
            'inbox-arrow-down' => 'inbox',
            'inbox-stack' => 'inbox',
            'information-circle' => 'info',
            'key' => 'key-round',
            'light-bulb' => 'lightbulb',
            'lock-closed' => 'lock',
            'lock-open' => 'lock-open',
            'magnifying-glass' => 'search',
            'magnifying-glass-circle' => 'search',
            'magnifying-glass-minus' => 'zoom-out',
            'magnifying-glass-plus' => 'zoom-in',
            'map-pin' => 'map-pin',
            'megaphone' => 'megaphone',
            'minus-circle' => 'circle-minus',
            'minus-small' => 'minus',
            'musical-note' => 'music',
            'newspaper' => 'newspaper',
            'no-symbol' => 'ban',
            'paper-airplane' => 'send',
            'paper-clip' => 'paperclip',
            'pencil-square' => 'square-pen',
            'phone-arrow-down-left' => 'phone-incoming',
            'phone-arrow-up-right' => 'phone-outgoing',
            'phone-x-mark' => 'phone-off',
            'photo' => 'image',
            'play-circle' => 'circle-play',
            'pause-circle' => 'circle-pause',
            'plus-circle' => 'circle-plus',
            'plus-small' => 'plus',
            'presentation-chart-bar' => 'presentation',
            'presentation-chart-line' => 'presentation',
            'puzzle-piece' => 'puzzle',
            'queue-list' => 'list',
            'question-mark-circle' => 'circle-help',
            'receipt-percent' => 'receipt',
            'receipt-refund' => 'receipt',
            'rectangle-group' => 'layout-dashboard',
            'rectangle-stack' => 'layers',
            'rocket-launch' => 'rocket',
            'shield-check' => 'shield-check',
            'shield-exclamation' => 'shield-alert',
            'shopping-bag' => 'shopping-bag',
            'shopping-cart' => 'shopping-cart',
            'signal' => 'signal',
            'sparkles' => 'sparkles',
            'speaker-wave' => 'volume-2',
            'speaker-x-mark' => 'volume-x',
            'square-2-stack' => 'copy',
            'square-3-stack-3d' => 'layers-3',
            'squares-2x2' => 'layout-grid',
            'squares-plus' => 'grid-2x2-plus',
            'star' => 'star',
            'sun' => 'sun',
            'swatch' => 'swatch-book',
            'table-cells' => 'table',
            'tag' => 'tag',
            'ticket' => 'ticket',
            'trash' => 'trash-2',
            'trophy' => 'trophy',
            'truck' => 'truck',
            'user-circle' => 'circle-user',
            'user-group' => 'users',
            'user-minus' => 'user-minus',
            'user-plus' => 'user-plus',
            'users' => 'users',
            'variable' => 'variable',
            'video-camera' => 'video',
            'video-camera-slash' => 'video-off',
            'view-columns' => 'columns-3',
            'viewfinder-circle' => 'scan',
            'wallet' => 'wallet',
            'wrench' => 'wrench',
            'wrench-screwdriver' => 'wrench',
            'x-circle' => 'circle-x',
            'x-mark' => 'x',
        ];
    }

    /**
     * Tailwind palettes (`Color::Amber`) → the 600 shade as hex; AlfaKit colours are CSS values.
     *
     * @return array<string, string>
     */
    public static function colors(): array
    {
        return [
            'Slate' => '#475569', 'Gray' => '#4b5563', 'Zinc' => '#52525b', 'Neutral' => '#525252', 'Stone' => '#57534e',
            'Red' => '#dc2626', 'Orange' => '#ea580c', 'Amber' => '#d97706', 'Yellow' => '#ca8a04', 'Lime' => '#65a30d',
            'Green' => '#16a34a', 'Emerald' => '#059669', 'Teal' => '#0d9488', 'Cyan' => '#0891b2', 'Sky' => '#0284c7',
            'Blue' => '#2563eb', 'Indigo' => '#4f46e5', 'Violet' => '#7c3aed', 'Purple' => '#9333ea', 'Fuchsia' => '#c026d3',
            'Pink' => '#db2777', 'Rose' => '#e11d48', 'Mauve' => '#7e5bef', 'Olive' => '#6b7f3a', 'Mist' => '#64748b', 'Taupe' => '#6b5b53',
        ];
    }

    /**
     * `config/filament.php` keys and where they live in `config/alfakit.php`.
     *
     * @return array<string, string|null>
     */
    public static function configKeys(): array
    {
        return [
            'default_filesystem_disk' => 'alfakit.default_filesystem_disk (ALFAKIT_FILESYSTEM_DISK)',
            'system_route_prefix' => 'alfakit.system_route_prefix (`_alfakit`, used by the internal endpoints)',
            'cache_path' => 'alfakit.cache_path',
            'broadcasting' => '`->realtimeNotifications()` on the panel; Echo is configured in your frontend',
            'assets_path' => 'not needed: renderer assets ship with the @alfakit npm package; plugin assets use `Assets::register()` and `alfakit:assets`',
            'livewire_loading_delay' => 'not needed (no Livewire)',
            'file_generation' => 'alfakit.file_generation (`namespace`, `path`)',
            'layout' => '`->design()` and `->theme()` on the panel (docs: design/themes)',
            'dark_mode' => '`->darkMode()` on the panel',
            'default_avatar_provider' => 'implement `HasAvatar` on the user model',
        ];
    }

    /**
     * `php artisan filament:*` → AlfaKit commands (composer scripts, CI, deploy files).
     *
     * @return array<string, string>
     */
    public static function commands(): array
    {
        return [
            'filament:upgrade' => 'alfakit:upgrade',
            'filament:assets' => 'alfakit:assets',
            'filament:optimize-clear' => 'alfakit:optimize-clear',
            'filament:optimize' => 'alfakit:optimize',
            'filament:cache-components' => 'alfakit:optimize',
            'filament:clear-cached-components' => 'alfakit:optimize-clear',
            'filament:install' => 'alfakit:install',
            'filament:user' => 'make:alfakit-user',
            'make:filament-resource' => 'make:alfakit-resource',
            'make:filament-page' => 'make:alfakit-page',
            'make:filament-widget' => 'make:alfakit-widget',
            'make:filament-relation-manager' => 'make:alfakit-relation-manager',
            'make:filament-panel' => 'make:alfakit-panel',
            'make:filament-user' => 'make:alfakit-user',
            'make:filament-cluster' => 'make:alfakit-cluster',
            'make:filament-theme' => 'make:alfakit-theme',
            'make:filament-importer' => 'make:alfakit-importer',
            'make:filament-exporter' => 'make:alfakit-exporter',
        ];
    }

    /**
     * Resolve a Filament class to its AlfaKit class, or null when there is none.
     */
    public static function target(string $filamentClass): ?string
    {
        $class = ltrim($filamentClass, '\\');

        return self::classes()[$class] ?? self::pluginClasses()[$class][0] ?? null;
    }

    /**
     * A Filament class, or a class of a known Filament plugin ({@see pluginNamespaces()}).
     */
    public static function isFilament(string $class): bool
    {
        $class = ltrim($class, '\\');

        if (str_starts_with($class, 'Filament\\')) {
            return true;
        }

        foreach (self::pluginNamespaces() as $namespace) {
            if (str_starts_with($class, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether some code mentions Filament or a known Filament plugin namespace.
     */
    public static function mentionsFilament(string $code): bool
    {
        if (str_contains($code, 'Filament')) {
            return true;
        }

        foreach (self::pluginNamespaces() as $namespace) {
            if (str_contains($code, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a Heroicon reference (`heroicon-o-users`, `OutlinedUsers`, `o-users`) to a Lucide name.
     *
     * @return array{0: string, 1: bool} the Lucide name and whether it came from the explicit map
     */
    public static function icon(string $heroicon): array
    {
        $name = $heroicon;

        if (preg_match('/^heroicon-[osmc]-(.+)$/', $name, $matches) === 1) {
            $name = $matches[1];
        } elseif (preg_match('/^[osmc]-(.+)$/', $name, $matches) === 1) {
            $name = $matches[1];
        } elseif (preg_match('/^(Outlined|Solid|Mini|Micro)?([A-Z].*)$/', $name, $matches) === 1) {
            $name = strtolower((string) preg_replace('/(?<!^)(?=[A-Z])|(?<=[a-z])(?=\d)/', '-', $matches[2]));
        }

        $icons = self::icons();

        return isset($icons[$name]) ? [$icons[$name], true] : [$name, false];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    private static function sameNames(string $alfakit, string $segment, array $names): array
    {
        $map = [];

        foreach ($names as $name) {
            $map['Filament\\'.$segment.$name] = $alfakit.$segment.$name;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function layoutComponents(string $alfakit, string $prefix): array
    {
        $map = [];

        foreach (['Section', 'Grid', 'Group', 'Fieldset', 'Tabs', 'Tabs\\Tab', 'Wizard', 'Wizard\\Step', 'Split'] as $name) {
            $map[$prefix.$name] = $alfakit.'Schemas\\Components\\'.$name;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function actions(string $alfakit, string $prefix): array
    {
        $map = [];

        foreach ([
            'Action', 'ActionGroup', 'AssociateAction', 'AttachAction', 'BulkAction', 'CreateAction', 'DeleteAction',
            'DeleteBulkAction', 'DetachAction', 'DetachBulkAction', 'DissociateAction', 'DissociateBulkAction',
            'EditAction', 'ExportAction', 'ExportBulkAction', 'ForceDeleteAction', 'ForceDeleteBulkAction',
            'ImportAction', 'ReplicateAction', 'RestoreAction', 'RestoreBulkAction', 'ViewAction',
        ] as $name) {
            $map[$prefix.$name] = $alfakit.'Actions\\'.$name;
        }

        return $map;
    }
}
