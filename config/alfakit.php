<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default filesystem disk
    |--------------------------------------------------------------------------
    |
    | Used by FileUpload fields, rich editor attachments and image columns when
    | no explicit disk is configured on the component.
    |
    */

    'default_filesystem_disk' => env('ALFAKIT_FILESYSTEM_DISK', env('FILESYSTEM_DISK', 'public')),

    /*
    |--------------------------------------------------------------------------
    | Temporary file URL expiry
    |--------------------------------------------------------------------------
    |
    | How many minutes the temporary (signed) URLs of private files stay valid:
    | file uploads, image columns and entries, and rich editor attachments.
    | The expiry is rounded up to the end of the hour so browsers can cache
    | the images, so URLs live between this value and an hour more. Change
    | it per component with `temporaryUrlExpiry()`.
    |
    */

    'temporary_file_url_expiry_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Temporary uploads
    |--------------------------------------------------------------------------
    |
    | Files are first streamed to this disk/directory and moved to their final
    | location when the form is submitted. Schedule `alfakit:prune-uploads` to
    | clean up abandoned uploads (and chunks) older than `max_age_hours`.
    |
    */

    'uploads' => [
        'temporary_disk' => env('ALFAKIT_TEMPORARY_DISK', 'local'),
        'temporary_directory' => 'alfakit-tmp',
        'max_age_hours' => 24,
        'max_size_kb' => 12288,

        // Files larger than this (in bytes) are uploaded in resumable chunks of this size,
        // assembled on the server and stored on the temporary disk. Their maximum size is
        // the component's (`FileUpload::maxSize()`, rich editor attachments, file manager).
        'chunk_size' => (int) env('ALFAKIT_UPLOAD_CHUNK_SIZE', 5 * 1024 * 1024),

        // Detected MIME types the upload endpoint refuses outright, because browsers can
        // run them when they're served back from your own domain.
        'blocked_mime_types' => [
            'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/javascript',
            'application/javascript', 'application/x-javascript', 'text/x-php',
            'application/x-php', 'application/x-httpd-php', 'application/x-msdownload',
            'application/x-dosexec', 'application/x-executable', 'application/x-sh',
            'text/x-shellscript', 'application/java-archive', 'text/xml', 'application/xml',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal routes
    |--------------------------------------------------------------------------
    |
    | Prefix for the JSON endpoints every panel exposes (action execution,
    | reactive schema refresh, searchable select options, uploads, …).
    |
    */

    'system_route_prefix' => '_alfakit',

    /*
    |--------------------------------------------------------------------------
    | Context token lifetime
    |--------------------------------------------------------------------------
    |
    | Pages hand the browser encrypted tokens that identify them to the internal
    | endpoints. Tokens are bound to the signed-in user and expire after this many
    | minutes; an expired token reloads the page. 0 disables expiry.
    |
    */

    'token_lifetime' => (int) env('ALFAKIT_TOKEN_LIFETIME', 1440),

    /*
    | Where command output points for documentation (installer, migration report).
    */
    'docs_url' => rtrim((string) env('ALFAKIT_DOCS_URL', 'https://alfakit.dev/docs'), '/'),

    /*
    | The live demo that `alfakit:add --list <plugin>` links to (`{demo_url}/react/admin/…`).
    */
    'demo_url' => rtrim((string) env('ALFAKIT_DEMO_URL', 'http://alfakit-demo.localhost'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Standalone components (outside panels)
    |--------------------------------------------------------------------------
    |
    | Forms, tables, infolists, actions and widgets rendered in your own Inertia
    | pages (`AlfaSix\Alfakit\Standalone\Form` and friends) talk to a hidden
    | "standalone" panel that registers only the internal endpoints, under
    | `/{path}/_alfakit/*`. Give `path` a value when a regular panel lives at the
    | root of your app. `middleware` runs on those endpoints (add your locale
    | middleware here); `guard` is the auth guard tokens are bound to; with
    | `guest_uploads` guests can upload files to forms that allow them.
    |
    */

    'standalone' => [
        'enabled' => true,
        'path' => '',
        'middleware' => ['web'],
        'auth_middleware' => [],
        'guard' => env('ALFAKIT_STANDALONE_GUARD', 'web'),
        'guest_uploads' => true,
        // Plugins whose widgets are used outside panels (e.g. CalendarPlugin::class).
        'plugins' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inertia
    |--------------------------------------------------------------------------
    |
    | The Blade root view used for panel pages and the Vite entry points it
    | loads. `alfakit:install` publishes the entry files for your adapter.
    |
    */

    'inertia' => [
        'root_view' => 'alfakit::app',
        'vite' => [
            'css' => 'resources/css/alfakit.css',
            'js' => env('ALFAKIT_VITE_ENTRY', 'resources/js/alfakit/app.tsx'),
        ],
        'page_component' => 'Alfakit/Page',
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    |
    | Default design for panels that don't call `->theme()`: neutral, alfakit,
    | minimal, soft, corporate or filament (or one you register with
    | `Themes::register()`). See the theming docs for the full list of options.
    |
    */

    'theme' => env('ALFAKIT_THEME', 'neutral'),

    /*
    |--------------------------------------------------------------------------
    | Icons
    |--------------------------------------------------------------------------
    |
    | Iconify sets to register at boot (`php artisan alfakit:icons mdi` installs
    | `@iconify-json/mdi` and adds it here): set names, or set => path of an
    | `icons.json` file. They are read from `iconify_path` (default: the app's
    | `node_modules/@iconify-json`), never from the network. Resolved icons are
    | cached in this cache store (null = default store, false = per request).
    |
    */

    'icons' => [
        'iconify' => [],
        'iconify_path' => env('ALFAKIT_ICONIFY_PATH'),
        'cache' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Default ISO 4217 currency for money columns, entries and MoneyInput when a
    | panel doesn't set one with `->currency()`. Falls back to Laravel's
    | `Number::defaultCurrency()` (USD) when null. Languages, the language
    | menu and timezones are configured per panel; see the localization docs.
    |
    */

    'currency' => env('ALFAKIT_CURRENCY'),

    /*
    |--------------------------------------------------------------------------
    | Model labels
    |--------------------------------------------------------------------------
    |
    | Translation group holding per-language model names, keyed by the snake-cased
    | class name: lang/{locale}/models.php → ['category' => 'Kategorie|Kategorien'].
    | English labels and plurals are generated from class names when missing.
    |
    */

    'model_labels' => 'models',

    /*
    |--------------------------------------------------------------------------
    | Database notifications
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'polling_interval' => '30s',
    ],

    /*
    |--------------------------------------------------------------------------
    | Imports and exports
    |--------------------------------------------------------------------------
    |
    | ImportAction and ExportAction process rows in chunks on the queue (inline
    | with the `sync` driver). Export files go to a private disk and are only
    | served through signed links that expire after `download_link_lifetime`
    | minutes. Publish the tables with `alfakit:install --import-export`.
    |
    */

    'import_export' => [
        'queue_connection' => env('ALFAKIT_IMPORT_EXPORT_QUEUE_CONNECTION'),
        'queue' => env('ALFAKIT_IMPORT_EXPORT_QUEUE'),
        'export_disk' => env('ALFAKIT_EXPORT_DISK', 'local'),
        'export_directory' => 'alfakit-exports',
        'download_link_lifetime' => 60 * 24 * 7,
        'max_import_rows' => null,
        'max_export_rows' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Code generation
    |--------------------------------------------------------------------------
    |
    | Defaults for the `make:alfakit-*` commands. `format_frontend` formats the frontend
    | files they write (and `alfakit:eject` copies) with the app's own formatter:
    | `vp fmt` (a `fmt` section in vite.config), Oxfmt or Prettier from node_modules/.bin.
    |
    */

    'file_generation' => [
        'namespace' => 'App\\Alfakit',
        'path' => app_path('Alfakit'),
        'format_frontend' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Component discovery caching
    |--------------------------------------------------------------------------
    |
    | Run `alfakit:optimize` in deployment to cache discovered resources, pages
    | and widgets instead of scanning the filesystem on every request.
    |
    */

    'cache_path' => base_path('bootstrap/cache/alfakit'),

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | Optionally use Laravel Echo for real-time database notifications instead
    | of polling. Mirrors the config keys expected by the frontend.
    |
    */

    'broadcasting' => [
        'echo' => null,
        // 'echo' => [
        //     'broadcaster' => 'reverb',
        //     'key' => env('VITE_REVERB_APP_KEY'),
        //     'wsHost' => env('VITE_REVERB_HOST'),
        //     'wsPort' => env('VITE_REVERB_PORT'),
        //     'forceTLS' => true,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Undo
    |--------------------------------------------------------------------------
    |
    | `Action::undoable()`: undo payloads live in this cache store (null: the
    | default store) until their countdown ends. Undoable delete actions skip
    | their confirmation modal unless `skip_delete_confirmation` is false.
    |
    */

    'undo' => [
        'cache_store' => env('ALFAKIT_UNDO_CACHE_STORE'),
        'skip_delete_confirmation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Customizable dashboards
    |--------------------------------------------------------------------------
    |
    | `Dashboard::customizable()` stores each user's layout in this table
    | (`php artisan vendor:publish --tag=alfakit-dashboard-migrations`).
    |
    */

    'dashboards' => [
        'layouts_table' => 'alfakit_dashboard_layouts',
    ],

    /*
    |--------------------------------------------------------------------------
    | "Powered by AlfaKit · Alfa Six" credit
    |--------------------------------------------------------------------------
    |
    | A small line with the Alfa Six emblem on the pages your customers see
    | (CMS site, public forms, helpdesk portal and chat widget, shared
    | analytics dashboards, invoice links and documents) and at the end of
    | AlfaKit's emails. Both are off by default; plugins can override them
    | with `->poweredBy()`. The panel's "Developed by Alfa Six" footer line
    | is a separate option: `$panel->credit(false)`.
    |
    */

    'credit' => [
        'public_pages' => (bool) env('ALFAKIT_POWERED_BY', false),
        'emails' => (bool) env('ALFAKIT_POWERED_BY_EMAILS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Installable app and Web Push
    |--------------------------------------------------------------------------
    |
    | `$panel->pwa(push: true)` sends notifications with these VAPID keys
    | (`php artisan alfakit:pwa-keys` writes them to .env) and stores each
    | browser's subscription in this table
    | (`php artisan vendor:publish --tag=alfakit-pwa-migrations`).
    |
    */

    'pwa' => [
        'vapid' => [
            'public_key' => env('ALFAKIT_PWA_VAPID_PUBLIC_KEY'),
            'private_key' => env('ALFAKIT_PWA_VAPID_PRIVATE_KEY'),
            'subject' => env('ALFAKIT_PWA_VAPID_SUBJECT'),
        ],
        'subscriptions_table' => 'alfakit_push_subscriptions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Email theme
    |--------------------------------------------------------------------------
    |
    | AlfaKit's emails (and plugin emails) use a branded layout built from the
    | default panel's brand name, logo, primary colour and font, right to left
    | for right-to-left locales and with a dark variant. Every value below is
    | optional: null falls back to the panel. Email clients don't show SVG
    | images, so give a PNG `logo` when the panel logo is an SVG. Set `theme`
    | to false to render AlfaKit's emails with your application's mail theme.
    |
    */

    'mail' => [
        'theme' => (bool) env('ALFAKIT_MAIL_THEME', true),
        // Send Laravel's "verify your email address" mail in the theme too.
        'verify_email' => true,
        'panel' => null,
        'name' => null,
        'logo' => null,
        'dark_logo' => null,
        'logo_height' => 32,
        'primary_color' => null,
        'font' => null,
        'url' => null,
    ],

];
