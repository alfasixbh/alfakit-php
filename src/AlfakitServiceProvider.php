<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit;

use AlfaSix\Alfakit\Commands\AiCommand;
use AlfaSix\Alfakit\Commands\InstallCommand;
use AlfaSix\Alfakit\Commands\LangAddCommand;
use AlfaSix\Alfakit\Commands\LangCheckCommand;
use AlfaSix\Alfakit\Commands\MakeClusterCommand;
use AlfaSix\Alfakit\Commands\MakePageCommand;
use AlfaSix\Alfakit\Commands\MakePanelCommand;
use AlfaSix\Alfakit\Commands\MakeRelationManagerCommand;
use AlfaSix\Alfakit\Commands\MakeResourceCommand;
use AlfaSix\Alfakit\Commands\MakeUserCommand;
use AlfaSix\Alfakit\Commands\MakeWidgetCommand;
use AlfaSix\Alfakit\Commands\OptimizeClearCommand;
use AlfaSix\Alfakit\Commands\OptimizeCommand;
use AlfaSix\Alfakit\Commands\PruneUploadsCommand;
use AlfaSix\Alfakit\Localization\TranslationLoader;
use AlfaSix\Alfakit\Localization\Translations;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AlfakitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/alfakit.php', 'alfakit');

        $this->app->singleton(AlfakitManager::class);
        $this->app->alias(AlfakitManager::class, 'alfakit');

        // Long-lived workers (Octane) reuse the manager; never carry request state over.
        $this->app->terminating(fn () => $this->app->make(AlfakitManager::class)->resetRequestState());

        // English ships with the core; language packs and plugins add more directories.
        Translations::register(__DIR__.'/../resources/lang', priority: -100);

        $this->app->extend('translation.loader', fn (Loader $loader): Loader => $loader instanceof TranslationLoader
            ? $loader
            : new TranslationLoader($loader, $this->app->langPath('vendor')));

        // Edited mail templates: none without the mail templates plugin.
        $this->app->bindIf(Mail\Contracts\MailTemplateRepository::class, Mail\NullMailTemplateRepository::class);

        $this->registerFakerProvider();
    }

    /**
     * `fake()->alfakitRichContent()` / `fake()->filamentRichContent()` (and the Markdown
     * versions) on every Faker generator, including those resolved before Alfakit
     * registered (`Generator::class.':'.$locale` bindings).
     */
    protected function registerFakerProvider(): void
    {
        if (! class_exists(\Faker\Generator::class)) {
            return;
        }

        $register = static function (\Faker\Generator $faker): void {
            foreach ($faker->getProviders() as $provider) {
                if ($provider instanceof Forms\Components\RichEditor\RichContentFakerProvider) {
                    return;
                }
            }

            $faker->addProvider(new Forms\Components\RichEditor\RichContentFakerProvider($faker));
        };

        $bindings = [
            \Faker\Generator::class,
            ...array_filter(array_keys($this->app->getBindings()), static fn (string $binding): bool => str_starts_with($binding, \Faker\Generator::class.':')),
        ];

        foreach ($bindings as $binding) {
            if ($this->app->resolved($binding)) {
                $instance = $this->app->make($binding);

                if ($instance instanceof \Faker\Generator) {
                    $register($instance);
                }
            }
        }

        $this->app->afterResolving(\Faker\Generator::class, $register);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'alfakit');

        // The renderer writes the colour-scheme and sidebar cookies from JavaScript, so they
        // are plain text; an encrypted-cookie middleware would otherwise discard them and the
        // server could never render the stored scheme or sidebar state.
        EncryptCookies::except(['alfakit_appearance', 'alfakit_sidebar_state']);

        $this->registerRoutes();
        $this->registerStrMacros();

        // The core mails for the mail preview page and editable templates.
        Mail\CoreMails::register();

        // Verification mails sent from a panel link back to that panel.
        Auth\EmailVerificationUrls::register();

        // Iconify sets listed in `alfakit.icons.iconify` (`alfakit:icons mdi`); nothing is read until an icon is used.
        if (is_array($iconify = config('alfakit.icons.iconify')) && $iconify !== []) {
            Support\Icons::iconify($iconify);
        }

        // `starterKit()` panels: their navigation is shared with every page of the host app.
        $this->app->booted(fn () => Support\StarterKitNavigation::register($this->app->make(AlfakitManager::class)));

        // `realtime()` panels: broadcast channels, model observers, instant database notifications.
        $this->app->booted(fn () => Realtime\Realtime::boot($this->app->make(AlfakitManager::class)));

        // `pwa(push: true)` panels: database notifications are also sent as Web Push.
        $this->app->booted(fn () => Pwa\PushNotifications::boot($this->app->make(AlfakitManager::class)));

        if ($this->app->runningInConsole()) {
            $this->registerPublishables();
            $this->registerCommands();
            $this->registerAboutCommand();
        }
    }

    /**
     * `Str::sanitizeHtml()`, `Str::sanitizeUrl()` and `Str::sanitizeCssColor()`, the
     * Filament-compatible helpers, plus their fluent `Stringable` versions.
     */
    protected function registerStrMacros(): void
    {
        if (! \Illuminate\Support\Str::hasMacro('sanitizeHtml')) {
            \Illuminate\Support\Str::macro('sanitizeHtml', fn (?string $html): string => Support\Html::sanitize($html));
        }

        if (! \Illuminate\Support\Str::hasMacro('sanitizeUrl')) {
            // `Str::sanitizeUrl($url, ['https'])`: an optional scheme allowlist, as in Filament.
            \Illuminate\Support\Str::macro('sanitizeUrl', fn (?string $url, ?array $allowedSchemes = null): ?string => Support\Url::sanitize($url, $allowedSchemes === null ? null : array_values($allowedSchemes)));
        }

        if (! \Illuminate\Support\Str::hasMacro('sanitizeCssColor')) {
            \Illuminate\Support\Str::macro('sanitizeCssColor', fn (?string $color): ?string => Support\Colors\CssColor::sanitize($color));
        }

        // Fluent versions: `str($html)->sanitizeHtml()`, `->sanitizeUrl()`, `->sanitizeCssColor()`.
        $stringable = \Illuminate\Support\Stringable::class;

        if (! $stringable::hasMacro('sanitizeHtml')) {
            $stringable::macro('sanitizeHtml', function (): \Illuminate\Support\Stringable {
                /** @var \Illuminate\Support\Stringable $this */
                return new \Illuminate\Support\Stringable(Support\Html::sanitize($this->toString()));
            });
        }

        if (! $stringable::hasMacro('sanitizeUrl')) {
            $stringable::macro('sanitizeUrl', function (?array $allowedSchemes = null): \Illuminate\Support\Stringable {
                /** @var \Illuminate\Support\Stringable $this */
                return new \Illuminate\Support\Stringable(Support\Url::sanitize($this->toString(), $allowedSchemes === null ? null : array_values($allowedSchemes)) ?? '');
            });
        }

        if (! $stringable::hasMacro('sanitizeCssColor')) {
            $stringable::macro('sanitizeCssColor', function (): \Illuminate\Support\Stringable {
                /** @var \Illuminate\Support\Stringable $this */
                return new \Illuminate\Support\Stringable(Support\Colors\CssColor::sanitize($this->toString()) ?? '');
            });
        }
    }

    protected function registerRoutes(): void
    {
        $this->app->booted(function (): void {
            if ($this->app->routesAreCached()) {
                return;
            }

            $router = $this->app->make(Router::class);

            $manager = $this->app->make(AlfakitManager::class);

            foreach ($manager->getPanels() as $panel) {
                $panel->registerRoutes($router);
            }

            // Internal endpoints for components used outside panels (`alfakit.standalone`).
            if (Standalone\StandalonePanel::isEnabled()) {
                $manager->getStandalonePanel()->registerRoutes($router);
            }
        });
    }

    protected function registerPublishables(): void
    {
        $this->publishes([__DIR__.'/../config/alfakit.php' => config_path('alfakit.php')], 'alfakit-config');
        $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit')], 'alfakit-translations');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/alfakit')], 'alfakit-views');
        $this->publishes([__DIR__.'/../resources/views/mail' => resource_path('views/vendor/alfakit/mail')], 'alfakit-mail');
        $this->publishes([__DIR__.'/../stubs' => base_path('stubs/alfakit')], 'alfakit-stubs');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php' => database_path('migrations/0001_01_01_000100_create_alfakit_import_export_tables.php'),
        ], 'alfakit-import-export-migrations');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000200_create_alfakit_dashboard_layouts_table.php' => database_path('migrations/0001_01_01_000200_create_alfakit_dashboard_layouts_table.php'),
        ], 'alfakit-dashboard-migrations');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000200_create_alfakit_user_preferences_table.php' => database_path('migrations/0001_01_01_000200_create_alfakit_user_preferences_table.php'),
        ], 'alfakit-user-preferences-migrations');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000300_create_alfakit_record_versions_table.php' => database_path('migrations/0001_01_01_000300_create_alfakit_record_versions_table.php'),
        ], 'alfakit-record-versions-migrations');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000400_create_alfakit_table_views_table.php' => database_path('migrations/0001_01_01_000400_create_alfakit_table_views_table.php'),
        ], 'alfakit-table-views-migrations');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000500_create_alfakit_push_subscriptions_table.php' => database_path('migrations/0001_01_01_000500_create_alfakit_push_subscriptions_table.php'),
        ], 'alfakit-pwa-migrations');
    }

    protected function registerCommands(): void
    {
        $this->commands([
            AiCommand::class,
            InstallCommand::class,
            Commands\AuthCommand::class,
            MakePanelCommand::class,
            MakeResourceCommand::class,
            Commands\MakeApiResourceCommand::class,
            MakePageCommand::class,
            MakeClusterCommand::class,
            MakeWidgetCommand::class,
            MakeRelationManagerCommand::class,
            Commands\MakeRelatedRecordsPageCommand::class,
            MakeUserCommand::class,
            OptimizeCommand::class,
            OptimizeClearCommand::class,
            PruneUploadsCommand::class,
            Commands\PruneVersionsCommand::class,
            Commands\MakeImporterCommand::class,
            Commands\MakeExporterCommand::class,
            LangAddCommand::class,
            LangCheckCommand::class,
            Commands\MakeThemeCommand::class,
            Commands\MakeFieldCommand::class,
            Commands\TypesCommand::class,
            Commands\MakeColumnCommand::class,
            Commands\MakeEntryCommand::class,
            Commands\MakePluginCommand::class,
            Commands\AssetsCommand::class,
            Commands\UpgradeCommand::class,
            Commands\MakeFormCommand::class,
            Commands\MakeSchemaCommand::class,
            Commands\TenancyCheckCommand::class,
            Commands\MakeTableCommand::class,
            Commands\MakeInfolistCommand::class,
            Commands\MakeComponentCommand::class,
            Commands\EjectCommand::class,
            Commands\MigrateFromFilamentCommand::class,
            Commands\MakeSchemaComponentCommand::class,
            Commands\MakeRichContentCustomBlockCommand::class,
            Commands\MakeIssueCommand::class,
            Commands\SetupCommand::class,
            Commands\UsersCommand::class,
            Commands\AiSetupCommand::class,
            Commands\RealtimeCommand::class,
            Commands\IconsCommand::class,
            Commands\AddCommand::class,
            Commands\RemoveCommand::class,
            Commands\DoctorCommand::class,
            Commands\GenerateResourceCommand::class,
            Commands\PwaKeysCommand::class,
        ]);

        // `php artisan optimize` / `optimize:clear` also cache and clear panel discovery.
        $this->optimizes(optimize: 'alfakit:optimize', clear: 'alfakit:optimize-clear', key: 'alfakit');
    }

    protected function registerAboutCommand(): void
    {
        AboutCommand::add('AlfaKit', fn (): array => [
            'Panels' => implode(', ', array_keys($this->app->make(AlfakitManager::class)->getPanels())) ?: 'none',
            'Version' => \Composer\InstalledVersions::isInstalled('alfasix/alfakit') ? (string) \Composer\InstalledVersions::getPrettyVersion('alfasix/alfakit') : 'dev',
        ]);
    }
}
