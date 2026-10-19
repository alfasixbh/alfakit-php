<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The first-party plugins `alfakit:setup` and `alfakit:add` can install. Read at runtime
 * from the plugins' own `composer.json` files (the monorepo's `packages/plugins/*` next to
 * this package, and installed `vendor/alfasix/alfakit-*`): package, one-line description,
 * plugin class and migration tag. Plugins that aren't on disk yet fall back to a short
 * list of known slugs. Install recipes (spatie migrations, follow-up notes) are layered on top.
 */
final class PluginCatalog
{
    /** Packages named `alfasix/alfakit-*` that are not panel plugins. */
    private const NOT_PLUGINS = ['translations'];

    /** The tenant of the live demo whose pages the showcase paths point into. */
    public const DEMO_TENANT = 'manama';

    /** The monorepo's packages/plugins directory, overridable in tests (an app installed from Packagist has none). */
    public static ?string $pluginsDirectory = null;

    /**
     * @return array<string, array{label: string, description: string, package: string, class: string, commands: list<list<string>>, migrate: bool, notes: list<string>}>
     */
    public static function all(): array
    {
        $plugins = [];

        foreach (self::fallback() as $slug => $description) {
            $plugins[$slug] = self::entry($slug, $description);
        }

        foreach (self::composerFiles() as $file) {
            $discovered = self::fromComposerFile($file);

            if ($discovered !== null) {
                $entry = self::entry($discovered['slug'], $discovered['description']);
                // Recipe commands (a dependency's migrations, an install command) run first;
                // the plugin's own migration tag is added after them, never replacing them.
                $commands = $entry['commands'];

                foreach ($discovered['commands'] as $command) {
                    if (! in_array($command, $commands, true)) {
                        $commands[] = $command;
                    }
                }

                $plugins[$discovered['slug']] = [...$entry, ...array_filter([
                    'class' => $discovered['class'],
                    'commands' => $commands,
                ])];
                $plugins[$discovered['slug']]['migrate'] = $plugins[$discovered['slug']]['migrate'] || $discovered['commands'] !== [];
            }
        }

        ksort($plugins);

        return $plugins;
    }

    public static function has(string $slug): bool
    {
        return isset(self::all()[Str::slug($slug)]);
    }

    /**
     * @return array{label: string, description: string, package: string, class: string, commands: list<list<string>>, migrate: bool, notes: list<string>}
     */
    public static function get(string $slug): array
    {
        $slug = Str::slug($slug);

        return self::all()[$slug] ?? self::entry($slug, '');
    }

    /**
     * `slug => "slug: one-line description"` for prompts.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(static fn (array $plugin): string => $plugin['label'].($plugin['description'] !== '' ? ': '.$plugin['description'] : ''), self::all());
    }

    /**
     * @return array{slug: string, description: string, class: ?string, commands: list<list<string>>}|null
     */
    public static function fromComposerFile(string $file): ?array
    {
        $composer = json_decode((string) @file_get_contents($file), true);
        $name = is_array($composer) ? ($composer['name'] ?? null) : null;

        if (! is_string($name) || ! str_starts_with($name, 'alfasix/alfakit-')) {
            return null;
        }

        $slug = Str::after($name, 'alfasix/alfakit-');

        if (in_array($slug, self::NOT_PLUGINS, true)) {
            return null;
        }

        $directory = dirname($file);
        $class = null;

        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $namespace => $path) {
            foreach (glob($directory.'/'.trim((string) $path, '/').'/*Plugin.php') ?: [] as $pluginFile) {
                $class = rtrim((string) $namespace, '\\').'\\'.basename($pluginFile, '.php');

                break 2;
            }
        }

        // A plugin that publishes migrations under the usual tag.
        $commands = [];
        $tag = "alfakit-{$slug}-migrations";

        foreach (glob($directory.'/src/*ServiceProvider.php') ?: [] as $provider) {
            if (str_contains((string) file_get_contents($provider), "'{$tag}'")) {
                $commands[] = ['vendor:publish', "--tag={$tag}"];
            }
        }

        return [
            'slug' => $slug,
            'description' => self::oneLine((string) ($composer['description'] ?? '')),
            'class' => $class,
            'commands' => $commands,
        ];
    }

    /**
     * The plugin's showcase manifest (`extra.alfakit.showcase` in its composer.json): the
     * page of the live demo, a short tour, recorded clips, related plugins and highlights.
     *
     * @return array{demoPath: string, tour: list<string>, clips: list<string>, worksWith: list<string>, highlights: list<string>}|null
     */
    public static function showcase(string $slug): ?array
    {
        $package = 'alfasix/alfakit-'.Str::slug($slug);

        foreach (self::composerFiles() as $file) {
            $composer = json_decode((string) @file_get_contents($file), true);

            if (! is_array($composer) || ($composer['name'] ?? null) !== $package) {
                continue;
            }

            $showcase = $composer['extra']['alfakit']['showcase'] ?? null;

            if (! is_array($showcase) || ! is_string($showcase['demoPath'] ?? null)) {
                return null;
            }

            $list = static fn (mixed $value): array => array_values(array_filter(is_array($value) ? $value : [], 'is_string'));

            return [
                'demoPath' => trim($showcase['demoPath'], '/'),
                'tour' => $list($showcase['tour'] ?? []),
                'clips' => $list($showcase['clips'] ?? []),
                'worksWith' => $list($showcase['worksWith'] ?? []),
                'highlights' => $list($showcase['highlights'] ?? []),
            ];
        }

        return null;
    }

    /**
     * The plugin's page in the live demo (`config('alfakit.demo_url')`), for a renderer.
     */
    public static function demoUrl(string $slug, string $renderer = 'react'): ?string
    {
        $showcase = self::showcase($slug);

        if ($showcase === null) {
            return null;
        }

        $base = rtrim((string) config('alfakit.demo_url', 'http://alfakit-demo.localhost'), '/');

        return "{$base}/{$renderer}/admin/".self::DEMO_TENANT.'/'.$showcase['demoPath'];
    }

    /**
     * The first sentence (or clause) of a description, short enough for one line.
     */
    public static function oneLine(string $description): string
    {
        $line = trim((string) preg_split('/(?<=[.!?])\s|:\s/', trim($description))[0]);
        $line = rtrim($line, '.');

        return Str::limit($line, 90);
    }

    /**
     * @return array<int, string>
     */
    private static function composerFiles(): array
    {
        return [
            // The monorepo / path repositories: packages/plugins next to packages/alfakit.
            ...(glob((self::$pluginsDirectory ?? dirname(__DIR__, 4).'/plugins').'/*/composer.json') ?: []),
            ...(glob(base_path('vendor/alfasix/alfakit-*/composer.json')) ?: []),
        ];
    }

    /**
     * Known slugs, used when a plugin's composer.json isn't on disk yet.
     *
     * @return array<string, string>
     */
    private static function fallback(): array
    {
        return [
            'activity-log' => 'Browse spatie/laravel-activitylog activities',
            'ai' => 'AI actions, field assistance, natural-language filters and a panel copilot (laravel/ai)',
            'analytics' => 'Privacy-first web analytics for your public site',
            'api' => 'JSON REST APIs for your resources',
            'backup' => 'Manage spatie/laravel-backup backups',
            'billing' => 'Subscription billing (Cashier, Paddle, Spark)',
            'calendar' => 'FullCalendar widgets',
            'cms' => 'Block-based pages, menus and redirects',
            'comments' => 'Comments with mentions and reactions on any record',
            'feature-flags' => 'Feature flags',
            'file-manager' => 'A file manager for any filesystem disk, with a file picker field',
            'forms' => 'A form builder for public forms',
            'health' => 'spatie/laravel-health check results',
            'helpdesk' => 'Tickets, an agent inbox, a customer portal and live chat',
            'invoices' => 'Invoices, quotes, credit notes and receipts',
            'kanban' => 'Kanban boards for any model',
            'mail-templates' => 'Editable email templates with merge tags and previews',
            'media-library' => 'Spatie Media Library fields, columns and entries',
            'ops' => 'Failed jobs, queues, scheduler, logs and system info',
            'settings' => 'Settings pages for spatie/laravel-settings',
            'shield' => 'Roles and permissions (spatie/laravel-permission)',
            'tags' => 'Spatie Tags input, column and entry',
            'teams' => 'Teams with members, roles, invitations and seat limits',
            'translation-manager' => 'Manage translations in the panel',
            'tree' => 'Drag-and-drop tree pages and widgets',
            'webhooks' => 'Outgoing signed webhooks with a deliveries log',
            'workflows' => 'State machines and approval workflows for any model',
        ];
    }

    /**
     * Why a plugin's dependency tables can't be created in this app, or null: an app table
     * of the same name that isn't the dependency's (`tags` without Spatie's `taggables`).
     * Its migration would fail on every `php artisan migrate`, so it isn't published.
     */
    public static function tableConflict(string $slug): ?string
    {
        $tables = match (Str::slug($slug)) {
            'tags' => ['tags' => 'taggables'],
            default => [],
        };

        foreach ($tables as $table => $companion) {
            try {
                if (Schema::hasTable($table) && ! Schema::hasTable($companion)) {
                    return "Your database already has a `{$table}` table that isn't Spatie Laravel Tags' (there is no `{$companion}` table), so its migration was not published. "
                        ."Rename your table, or give Spatie its own: a model extending Spatie\\Tags\\Tag with `protected \$table = 'spatie_tags';` and a `getForeignKey()` returning 'tag_id', as `tag_model` in config/tags.php (php artisan vendor:publish --tag=tags-config), "
                        ."then php artisan vendor:publish --tag=tags-migrations, use `spatie_tags` in the migration (also in `constrained('spatie_tags')`) and migrate. "
                        ."A model with its own `tags()` relationship can't use HasTags as is.";
                }
            } catch (Throwable) {
                // No database yet: nothing to collide with.
            }
        }

        return null;
    }

    /**
     * Install recipes that composer.json can't express: dependencies' migrations and notes.
     *
     * @return array{commands?: list<list<string>>, migrate?: bool, notes?: list<string>}
     */
    private static function recipe(string $slug): array
    {
        return match ($slug) {
            'shield' => ['commands' => [['alfakit-shield:install', '--panel={panel}']], 'migrate' => true, 'notes' => [
                'Add Spatie\\Permission\\Traits\\HasRoles to your User model.',
                'Generate the permissions and make yourself a super admin: php artisan alfakit-shield:generate --panel={panel} && php artisan alfakit-shield:super-admin --panel={panel}',
            ]],
            'activity-log' => ['commands' => [['vendor:publish', '--provider=Spatie\\Activitylog\\ActivitylogServiceProvider', '--tag=activitylog-migrations']], 'migrate' => true, 'notes' => ['Add Spatie\\Activitylog\\Models\\Concerns\\LogsActivity to the models you want to log.']],
            'media-library' => ['commands' => [['vendor:publish', '--provider=Spatie\\MediaLibrary\\MediaLibraryServiceProvider', '--tag=medialibrary-migrations']], 'migrate' => true, 'notes' => ['Add HasMedia / InteractsWithMedia to your models.']],
            'tags' => ['commands' => [['vendor:publish', '--provider=Spatie\\Tags\\TagsServiceProvider', '--tag=tags-migrations']], 'migrate' => true, 'notes' => ['Add Spatie\\Tags\\HasTags to your models.']],
            'settings' => ['commands' => [['vendor:publish', '--provider=Spatie\\LaravelSettings\\LaravelSettingsServiceProvider', '--tag=migrations']], 'migrate' => true, 'notes' => ['Create a settings page: php artisan make:alfakit-settings-page']],
            'backup' => ['notes' => ['Configure spatie/laravel-backup (config/backup.php) and run a queue worker.']],
            // spatie/laravel-health's default result store (Eloquent) needs its table.
            'health' => ['commands' => [['vendor:publish', '--tag=health-migrations']], 'migrate' => true, 'notes' => ['Register your spatie/laravel-health checks (Health::checks([...]) in a service provider).']],
            'ops' => ['notes' => ['Allow access with OpsPlugin::make()->authorize(...) or the viewAlfakitOps gate; the pages answer 403 until then.']],
            'translation-manager' => ['notes' => ['Allow access with TranslationManagerPlugin::make()->authorize(...) or the manageTranslations gate; the page answers 403 until then.']],
            // The template history (optional, but `alfakit:doctor` reports it missing).
            'mail-templates' => ['commands' => [['vendor:publish', '--tag=alfakit-record-versions-migrations']], 'migrate' => true, 'notes' => ['Allow access with MailTemplatesPlugin::make()->authorize(...) or the manageAlfakitMailTemplates gate; the resource is hidden until then.']],
            'billing' => ['notes' => ['Set up Laravel Cashier (migrations, keys, webhook) and add Billable to the model that pays.']],
            'feature-flags' => ['commands' => [['vendor:publish', '--tag=pennant-migrations']], 'migrate' => true, 'notes' => ['Allow access with ->authorize(...) or the manageFeatureFlags gate.']],
            // Submissions are exported with AlfaKit's exporter, which needs the exports tables.
            'forms' => ['commands' => [['vendor:publish', '--tag=alfakit-import-export-migrations']], 'migrate' => true, 'notes' => ['Publish the public form page: php artisan alfakit-forms:page']],
            'analytics' => ['commands' => [['vendor:publish', '--tag=alfakit-import-export-migrations']], 'migrate' => true],
            'helpdesk' => ['notes' => ['Publish the customer portal pages: php artisan alfakit-helpdesk:pages']],
            'ai' => ['notes' => ['Configure a provider: php artisan alfakit:ai-setup']],
            'api' => ['notes' => ['Expose resources: ApiPlugin::make()->resources([PostResource::class]) (or implement ExposesApi on a resource); nothing is exposed until then.']],
            'cms' => ['notes' => ['Link the public disk: php artisan storage:link']],
            default => [],
        };
    }

    /**
     * @return array{label: string, description: string, package: string, class: string, commands: list<list<string>>, migrate: bool, notes: list<string>}
     */
    private static function entry(string $slug, string $description): array
    {
        $name = Str::studly($slug);
        $recipe = self::recipe($slug);

        return [
            'label' => $slug,
            'description' => $description,
            'package' => 'alfasix/alfakit-'.$slug,
            'class' => "AlfaSix\\Alfakit{$name}\\{$name}Plugin",
            'commands' => $recipe['commands'] ?? [],
            'migrate' => $recipe['migrate'] ?? false,
            'notes' => $recipe['notes'] ?? [],
        ];
    }
}
