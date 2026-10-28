<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests;

use AlfaSix\Alfakit\AlfakitServiceProvider;
use AlfaSix\Alfakit\CommandPalette\UserPreferences;
use AlfaSix\Alfakit\Pwa\PushSubscription;
use AlfaSix\Alfakit\Tables\Views\SavedTableView;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\TestPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\TenantPanelProvider;
use AlfaSix\Alfakit\Tests\Support\IsolatedSkeleton;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Each PHP process boots from its own copy of Testbench's skeleton, so parallel
     * workers never share `public/build`, `storage/`, `app/` or `config/` (see
     * IsolatedSkeleton).
     */
    public static function applicationBasePath()
    {
        return IsolatedSkeleton::path();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Whether an optional table exists is memoised per process, but each test has its
        // own database: suites that load AlfaKit's migrations would otherwise make later
        // tests without them query a missing table.
        UserPreferences::flush();
        SavedTableView::flushAvailability();
        PushSubscription::flushAvailability();

        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'AlfaSix\\Alfakit\\Tests\\Fixtures\\Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            AlfakitServiceProvider::class,
            TestPanelProvider::class,
            TenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('alfakit.cache_path', storage_path('framework/alfakit-cache'));
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => 'http://localhost/storage',
            'visibility' => 'public',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
