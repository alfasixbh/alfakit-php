<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

use AlfaSix\Alfakit\Auth\Pages\Login;
use AlfaSix\Alfakit\Auth\Pages\Register;
use AlfaSix\Alfakit\Auth\Pages\RequestPasswordReset;
use AlfaSix\Alfakit\Auth\Pages\ResetPassword;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Pages\ManageRelatedRecords;
use ReflectionClass;

/**
 * Named places in the panel layout where you can insert content with `Panel::slot()` or,
 * for every panel, `Slots::register()` (Filament's render hooks). The comment on each
 * constant says where it renders and the Filament hook it replaces.
 */
final class Slot
{
    /**
     * Start of `<head>`, before the panel's styles (meta tags, preconnects). Rendered on
     * the server by the root view, so only HTML (strings, views, `Htmlable`) works here.
     */
    public const HEAD_START = 'head.start';

    /** End of `<head>`, after the panel's assets (analytics, verification tags). */
    public const HEAD_END = 'head.end';

    /** Top of the page body, above everything (announcement bars). */
    public const BODY_START = 'body.start';

    /** End of the page body (chat widgets, scripts rendered as markup). */
    public const BODY_END = 'body.end';

    /** Under the brand at the top of the sidebar. */
    public const SIDEBAR_HEADER = 'sidebar.header';

    /** Before the first navigation group. */
    public const SIDEBAR_NAV_START = 'sidebar.nav.start';

    /** After the last navigation group. */
    public const SIDEBAR_NAV_END = 'sidebar.nav.end';

    /** Above the user menu at the bottom of the sidebar. */
    public const SIDEBAR_FOOTER = 'sidebar.footer';

    /** After the breadcrumbs in the top bar. */
    public const TOPBAR_START = 'topbar.start';

    /** Before global search, notifications and the theme toggle. */
    public const TOPBAR_END = 'topbar.end';

    /** Top of the content area, above the page header. */
    public const CONTENT_START = 'content.start';

    /** Bottom of the content area. */
    public const CONTENT_END = 'content.end';

    /** Above the page heading. */
    public const PAGE_HEADER_BEFORE = 'page.header.before';

    /** Under the page heading, above the page content. */
    public const PAGE_HEADER_AFTER = 'page.header.after';

    /** Before the page's header actions. */
    public const PAGE_ACTIONS_BEFORE = 'page.actions.before';

    /** After the page's header actions. */
    public const PAGE_ACTIONS_AFTER = 'page.actions.after';

    /** Panel footer under the content. */
    public const FOOTER = 'footer';

    /** First items of the user menu dropdown. */
    public const USER_MENU_BEFORE = 'user-menu.before';

    /** Last items of the user menu, before signing out. */
    public const USER_MENU_AFTER = 'user-menu.after';

    /** Above the card on sign-in, registration and password pages. */
    public const AUTH_BEFORE = 'auth.before';

    /** Under the card on sign-in, registration and password pages. */
    public const AUTH_AFTER = 'auth.after';

    /** Start of every table's toolbar (next to the search box). */
    public const TABLE_TOOLBAR_START = 'table.toolbar.start';

    /** End of every table's toolbar (next to filters and columns). */
    public const TABLE_TOOLBAR_END = 'table.toolbar.end';

    /** Start of the panel layout, inside it (`LAYOUT_START`). Scope it to pages. */
    public const LAYOUT_START = 'layout.start';

    /** End of the panel layout, inside it (`LAYOUT_END`). */
    public const LAYOUT_END = 'layout.end';

    /** Start of the sidebar, above the logo (`SIDEBAR_START`). */
    public const SIDEBAR_START = 'sidebar.start';

    /** Before the logo (or the tenant menu) in the sidebar (`SIDEBAR_LOGO_BEFORE`). */
    public const SIDEBAR_LOGO_BEFORE = 'sidebar.logo.before';

    /** After the logo (or the tenant menu) in the sidebar (`SIDEBAR_LOGO_AFTER`). */
    public const SIDEBAR_LOGO_AFTER = 'sidebar.logo.after';

    /** End of the sidebar, under the user menu. */
    public const SIDEBAR_END = 'sidebar.end';

    /** Above the top bar (`TOPBAR_BEFORE`). */
    public const TOPBAR_BEFORE = 'topbar.before';

    /** Below the top bar (`TOPBAR_AFTER`). */
    public const TOPBAR_AFTER = 'topbar.after';

    /** Before the logo in the top bar of the top navigation and fullscreen layouts (`TOPBAR_LOGO_BEFORE`). */
    public const TOPBAR_LOGO_BEFORE = 'topbar.logo.before';

    /** After the logo in the top bar of the top navigation and fullscreen layouts (`TOPBAR_LOGO_AFTER`). */
    public const TOPBAR_LOGO_AFTER = 'topbar.logo.after';

    /** Before the global search field in the top bar (`GLOBAL_SEARCH_BEFORE`). */
    public const GLOBAL_SEARCH_BEFORE = 'global-search.before';

    /** After the global search field in the top bar (`GLOBAL_SEARCH_AFTER`). */
    public const GLOBAL_SEARCH_AFTER = 'global-search.after';

    /** Top of the global search dialog, above the search input (`GLOBAL_SEARCH_START`). */
    public const GLOBAL_SEARCH_START = 'global-search.start';

    /** Bottom of the global search dialog, under the results (`GLOBAL_SEARCH_END`). */
    public const GLOBAL_SEARCH_END = 'global-search.end';

    /** Before the profile link in the user menu (`USER_MENU_PROFILE_BEFORE`). */
    public const USER_MENU_PROFILE_BEFORE = 'user-menu.profile.before';

    /** After the profile link in the user menu (`USER_MENU_PROFILE_AFTER`). */
    public const USER_MENU_PROFILE_AFTER = 'user-menu.profile.after';

    /** Before the tenant menu (`TENANT_MENU_BEFORE`). */
    public const TENANT_MENU_BEFORE = 'tenant-menu.before';

    /** After the tenant menu (`TENANT_MENU_AFTER`). */
    public const TENANT_MENU_AFTER = 'tenant-menu.after';

    /** Before the content area, outside it (`CONTENT_BEFORE`). */
    public const CONTENT_BEFORE = 'content.before';

    /** After the content area, outside it (`CONTENT_AFTER`). */
    public const CONTENT_AFTER = 'content.after';

    /** Start of the page, above the page header (`PAGE_START`). Scope it to pages or resources. */
    public const PAGE_START = 'page.start';

    /** End of the page, under its content and footer widgets (`PAGE_END`). */
    public const PAGE_END = 'page.end';

    /** Before the page heading, inside the header (`PAGE_HEADER_HEADING_BEFORE`). */
    public const PAGE_HEADER_HEADING_BEFORE = 'page.heading.before';

    /** After the page heading and subheading, inside the header (`PAGE_HEADER_HEADING_AFTER`). */
    public const PAGE_HEADER_HEADING_AFTER = 'page.heading.after';

    /** Before the page's header widgets (`PAGE_HEADER_WIDGETS_BEFORE`). */
    public const PAGE_HEADER_WIDGETS_BEFORE = 'page.header-widgets.before';

    /** After the page's header widgets (`PAGE_HEADER_WIDGETS_AFTER`). */
    public const PAGE_HEADER_WIDGETS_AFTER = 'page.header-widgets.after';

    /** Before the page's footer widgets (`PAGE_FOOTER_WIDGETS_BEFORE`). */
    public const PAGE_FOOTER_WIDGETS_BEFORE = 'page.footer-widgets.before';

    /** After the page's footer widgets (`PAGE_FOOTER_WIDGETS_AFTER`). */
    public const PAGE_FOOTER_WIDGETS_AFTER = 'page.footer-widgets.after';

    /** Before the sub-navigation in the `start` position (`PAGE_SUB_NAVIGATION_START_BEFORE`). */
    public const PAGE_SUB_NAVIGATION_START_BEFORE = 'page.sub-navigation.start.before';

    /** After the sub-navigation in the `start` position (`PAGE_SUB_NAVIGATION_START_AFTER`). */
    public const PAGE_SUB_NAVIGATION_START_AFTER = 'page.sub-navigation.start.after';

    /** Before the sub-navigation in the `end` position (`PAGE_SUB_NAVIGATION_END_BEFORE`). */
    public const PAGE_SUB_NAVIGATION_END_BEFORE = 'page.sub-navigation.end.before';

    /** After the sub-navigation in the `end` position (`PAGE_SUB_NAVIGATION_END_AFTER`). */
    public const PAGE_SUB_NAVIGATION_END_AFTER = 'page.sub-navigation.end.after';

    /** Before the sub-navigation tabs in the `top` position (`PAGE_SUB_NAVIGATION_TOP_BEFORE`). */
    public const PAGE_SUB_NAVIGATION_TOP_BEFORE = 'page.sub-navigation.top.before';

    /** After the sub-navigation tabs in the `top` position (`PAGE_SUB_NAVIGATION_TOP_AFTER`). */
    public const PAGE_SUB_NAVIGATION_TOP_AFTER = 'page.sub-navigation.top.after';

    /** Top of the side sub-navigation (`start`/`end`), above its links (`PAGE_SUB_NAVIGATION_SIDEBAR_BEFORE`). */
    public const PAGE_SUB_NAVIGATION_SIDEBAR_BEFORE = 'page.sub-navigation.sidebar.before';

    /** Bottom of the side sub-navigation (`start`/`end`), under its links (`PAGE_SUB_NAVIGATION_SIDEBAR_AFTER`). */
    public const PAGE_SUB_NAVIGATION_SIDEBAR_AFTER = 'page.sub-navigation.sidebar.after';

    /** Before the table of a List page (`RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE`). Only rendered on `ListRecords` pages. */
    public const RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE = 'resource.list-records.table.before';

    /** After the table of a List page (`RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER`). */
    public const RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER = 'resource.list-records.table.after';

    /** Before the first tab of a List page's tabs (`RESOURCE_PAGES_LIST_RECORDS_TABS_START`). */
    public const RESOURCE_PAGES_LIST_RECORDS_TABS_START = 'resource.list-records.tabs.start';

    /** After the last tab of a List page's tabs (`RESOURCE_PAGES_LIST_RECORDS_TABS_END`). */
    public const RESOURCE_PAGES_LIST_RECORDS_TABS_END = 'resource.list-records.tabs.end';

    /** Before the table of a `ManageRelatedRecords` page (`RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE`). */
    public const RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE = 'resource.manage-related-records.table.before';

    /** After the table of a `ManageRelatedRecords` page (`RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER`). */
    public const RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER = 'resource.manage-related-records.table.after';

    /** Before each relation manager's table (`RESOURCE_RELATION_MANAGER_BEFORE`). Scope it to pages or relation manager classes. */
    public const RESOURCE_RELATION_MANAGER_BEFORE = 'resource.relation-manager.before';

    /** After each relation manager's table (`RESOURCE_RELATION_MANAGER_AFTER`). */
    public const RESOURCE_RELATION_MANAGER_AFTER = 'resource.relation-manager.after';

    /** Before the first relation manager tab (`RESOURCE_TABS_START`). */
    public const RESOURCE_TABS_START = 'resource.tabs.start';

    /** After the last relation manager tab (`RESOURCE_TABS_END`). */
    public const RESOURCE_TABS_END = 'resource.tabs.end';

    /** Start of the simple (auth) layout, above the logo (`SIMPLE_LAYOUT_START`). */
    public const SIMPLE_LAYOUT_START = 'simple.layout.start';

    /** End of the simple (auth) layout (`SIMPLE_LAYOUT_END`). */
    public const SIMPLE_LAYOUT_END = 'simple.layout.end';

    /** Top of the simple page's card, above the heading (`SIMPLE_PAGE_START`). */
    public const SIMPLE_PAGE_START = 'simple.page.start';

    /** Bottom of the simple page's card, under the form (`SIMPLE_PAGE_END`). */
    public const SIMPLE_PAGE_END = 'simple.page.end';

    /** Before the sign-in form (`AUTH_LOGIN_FORM_BEFORE`). Only rendered on `Login` pages. */
    public const AUTH_LOGIN_FORM_BEFORE = 'auth.login.form.before';

    /** After the sign-in form (`AUTH_LOGIN_FORM_AFTER`). */
    public const AUTH_LOGIN_FORM_AFTER = 'auth.login.form.after';

    /** Before the registration form (`AUTH_REGISTER_FORM_BEFORE`). Only rendered on `Register` pages. */
    public const AUTH_REGISTER_FORM_BEFORE = 'auth.register.form.before';

    /** After the registration form (`AUTH_REGISTER_FORM_AFTER`). */
    public const AUTH_REGISTER_FORM_AFTER = 'auth.register.form.after';

    /** Before the "send reset link" form (`AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE`). */
    public const AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE = 'auth.password-reset.request.form.before';

    /** After the "send reset link" form (`AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER`). */
    public const AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER = 'auth.password-reset.request.form.after';

    /** Before the "choose a new password" form (`AUTH_PASSWORD_RESET_RESET_FORM_BEFORE`). */
    public const AUTH_PASSWORD_RESET_RESET_FORM_BEFORE = 'auth.password-reset.reset.form.before';

    /** After the "choose a new password" form (`AUTH_PASSWORD_RESET_RESET_FORM_AFTER`). */
    public const AUTH_PASSWORD_RESET_RESET_FORM_AFTER = 'auth.password-reset.reset.form.after';

    /** Before every table's search box (`TOOLBAR_SEARCH_BEFORE`). Scope it to pages, relation managers or table widgets. */
    public const TABLE_TOOLBAR_SEARCH_BEFORE = 'table.toolbar.search.before';

    /** After every table's search box (`TOOLBAR_SEARCH_AFTER`). */
    public const TABLE_TOOLBAR_SEARCH_AFTER = 'table.toolbar.search.after';

    /** Before every table's header (heading, description, header actions) (`HEADER_BEFORE`). */
    public const TABLE_HEADER_BEFORE = 'table.header.before';

    /** After every table's header (`HEADER_AFTER`). */
    public const TABLE_HEADER_AFTER = 'table.header.after';

    /** Next to a table's active filter indicators, rendered while filters are active (`FILTER_INDICATORS`). */
    public const TABLE_FILTER_INDICATORS = 'table.filter-indicators';

    /** Above every table's toolbar (search, filters, column manager) (`TOOLBAR_BEFORE`). */
    public const TABLE_TOOLBAR_BEFORE = 'table.toolbar.before';

    /** Under every table's toolbar (`TOOLBAR_AFTER`). */
    public const TABLE_TOOLBAR_AFTER = 'table.toolbar.after';

    /** Before every table's rows or empty state, after the selection and filter indicators (`CONTENT_BEFORE`). */
    public const TABLE_CONTENT_BEFORE = 'table.content.before';

    /** After every table's rows or empty state, before the pagination (`CONTENT_AFTER`). */
    public const TABLE_CONTENT_AFTER = 'table.content.after';

    /**
     * Inside every column header cell, after the label (`HEADER_CELL`). Rendered once per
     * column with the slot data `['column' => '<name>']`: target one column with
     * `when: ['column' => 'title']`. Adds to the header; it doesn't replace it.
     */
    public const TABLE_HEADER_CELL = 'table.header-cell';

    /** Before the "select all" / "deselect all" buttons of the selection bar (`SELECTION_INDICATOR_ACTIONS_BEFORE`). */
    public const TABLE_SELECTION_INDICATOR_ACTIONS_BEFORE = 'table.selection-indicator.actions.before';

    /** After the "select all" / "deselect all" buttons of the selection bar (`SELECTION_INDICATOR_ACTIONS_AFTER`). */
    public const TABLE_SELECTION_INDICATOR_ACTIONS_AFTER = 'table.selection-indicator.actions.after';

    /** Before the grouping selector (`TOOLBAR_GROUPING_SELECTOR_BEFORE`). */
    public const TABLE_TOOLBAR_GROUPING_SELECTOR_BEFORE = 'table.toolbar.grouping-selector.before';

    /** After the grouping selector (`TOOLBAR_GROUPING_SELECTOR_AFTER`). */
    public const TABLE_TOOLBAR_GROUPING_SELECTOR_AFTER = 'table.toolbar.grouping-selector.after';

    /** Before the reorder trigger (`TOOLBAR_REORDER_TRIGGER_BEFORE`). */
    public const TABLE_TOOLBAR_REORDER_TRIGGER_BEFORE = 'table.toolbar.reorder-trigger.before';

    /** After the reorder trigger (`TOOLBAR_REORDER_TRIGGER_AFTER`). */
    public const TABLE_TOOLBAR_REORDER_TRIGGER_AFTER = 'table.toolbar.reorder-trigger.after';

    /** Before the column manager trigger (`TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE`). */
    public const TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE = 'table.toolbar.column-manager-trigger.before';

    /** After the column manager trigger (`TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER`). */
    public const TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER = 'table.toolbar.column-manager-trigger.after';

    /** Start of every table widget, above the table (`TABLE_WIDGET_START`). Scope it to widget classes. */
    public const TABLE_WIDGET_START = 'table-widget.start';

    /** End of every table widget, under the table (`TABLE_WIDGET_END`). */
    public const TABLE_WIDGET_END = 'table-widget.end';

    /** Above the fields of every action modal (`MODAL_SCHEMA_BEFORE`). */
    public const MODAL_SCHEMA_BEFORE = 'modal.schema.before';

    /** Under the fields of every action modal (`MODAL_SCHEMA_AFTER`). */
    public const MODAL_SCHEMA_AFTER = 'modal.schema.after';

    /**
     * Slots the root view renders into `<head>` instead of the renderer.
     *
     * @return array<int, string>
     */
    public static function head(): array
    {
        return [self::HEAD_START, self::HEAD_END];
    }

    /**
     * Slots that only render on one kind of page (whatever their scopes say): the List
     * page's table slots on `ListRecords` pages, the sign-in form's on `Login` pages, …
     *
     * @return array<string, class-string>
     */
    public static function pageTypes(): array
    {
        return [
            self::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE => ListRecords::class,
            self::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER => ListRecords::class,
            self::RESOURCE_PAGES_LIST_RECORDS_TABS_START => ListRecords::class,
            self::RESOURCE_PAGES_LIST_RECORDS_TABS_END => ListRecords::class,
            self::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE => ManageRelatedRecords::class,
            self::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER => ManageRelatedRecords::class,
            self::AUTH_LOGIN_FORM_BEFORE => Login::class,
            self::AUTH_LOGIN_FORM_AFTER => Login::class,
            self::AUTH_REGISTER_FORM_BEFORE => Register::class,
            self::AUTH_REGISTER_FORM_AFTER => Register::class,
            self::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE => RequestPasswordReset::class,
            self::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER => RequestPasswordReset::class,
            self::AUTH_PASSWORD_RESET_RESET_FORM_BEFORE => ResetPassword::class,
            self::AUTH_PASSWORD_RESET_RESET_FORM_AFTER => ResetPassword::class,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values((new ReflectionClass(self::class))->getConstants());
    }
}
