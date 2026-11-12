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
        $this->resetSequences();

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

    /**
     * MySQL and MariaDB keep their AUTO_INCREMENT counters across the rolled-back
     * transactions too. The tables are empty here (the previous test was rolled back) and
     * this test's transaction has not begun, so the counters can be put back to 1.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = $this->app['db']->connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $tables = $connection->select('select table_name as name from information_schema.tables where table_schema = database() and auto_increment > 1');

        foreach ($tables as $table) {
            if ($table->name !== 'migrations') {
                $connection->statement('alter table `'.$table->name.'` auto_increment = 1');
            }
        }
    }

    /**
     * Many tests name records by key (`/admin/posts/1/edit`). SQLite in memory starts every
     * test at 1; PostgreSQL keeps counting across the transactions that isolate tests, so
     * its sequences are put back here (`setval` is not transactional, and needs no DDL).
     */
    protected function resetSequences(): void
    {
        $connection = $this->app['db']->connection();

        // MySQL and MariaDB: see `beforeRefreshingDatabase()` (resetting a counter there is
        // DDL, which would commit the transaction this test runs in).
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($connection->select("select schemaname, sequencename from pg_sequences where schemaname = 'public' and sequencename <> 'migrations_id_seq'") as $sequence) {
            $connection->statement('select setval(?::regclass, 1, false)', ['"'.$sequence->schemaname.'"."'.$sequence->sequencename.'"']);
        }
    }

    /**
     * The database the suite runs on: SQLite in memory, or a real server to check the SQL
     * AlfaKit builds there:
     *
     * ```bash
     * ALFAKIT_TEST_DB=mariadb ALFAKIT_TEST_DB_DATABASE=alfakit_test composer test
     * ALFAKIT_TEST_DB=pgsql ALFAKIT_TEST_DB_DATABASE=alfakit_test ALFAKIT_TEST_DB_USERNAME=postgres composer test
     * ```
     *
     * Use a database made for this: the suite drops every table in it.
     *
     * @return array<string, mixed>
     */
    public static function databaseConnection(): array
    {
        $driver = (string) (getenv('ALFAKIT_TEST_DB') ?: 'sqlite');

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        $postgres = $driver === 'pgsql';

        return [
            'driver' => $driver,
            'host' => getenv('ALFAKIT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('ALFAKIT_TEST_DB_PORT') ?: ($postgres ? '5432' : '3306'),
            'database' => getenv('ALFAKIT_TEST_DB_DATABASE') ?: 'alfakit_test',
            'username' => getenv('ALFAKIT_TEST_DB_USERNAME') ?: ($postgres ? 'postgres' : 'root'),
            'password' => getenv('ALFAKIT_TEST_DB_PASSWORD') ?: '',
            'charset' => $postgres ? 'utf8' : 'utf8mb4',
            'collation' => $postgres ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::databaseConnection());
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
