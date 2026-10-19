<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/*
 * `alfakit:migrate-from-filament` on tests/Fixtures/Filament5App: a Filament 5.10 shop back
 * office written from Filament's documented APIs (resources split into Schemas/Tables/Pages,
 * relation manager, wizard, repeaters, tabs, filters incl. QueryBuilder, import/export,
 * clusters, tenancy, multi-factor auth, widgets, custom pages with Blade views, render
 * hooks, a theme, Livewire components and nine third-party plugins).
 *
 * The migrated app must lint, pass PHPStan level 5 against AlfaKit, boot in its own process
 * with its own providers and render every panel page, and convert at least 90% of the
 * Filament constructs without a TODO.
 */

const FILAMENT5_FIXTURE = __DIR__.'/../Fixtures/Filament5App';

/** The first-party plugins the migrated code uses (what `alfakit:add` would install). */
const FILAMENT5_PLUGIN_PROVIDERS = [
    'AlfaSix\\AlfakitCalendar\\AlfakitCalendarServiceProvider',
    'AlfaSix\\AlfakitComments\\AlfakitCommentsServiceProvider',
    'AlfaSix\\AlfakitMediaLibrary\\AlfakitMediaLibraryServiceProvider',
];

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/alfakit-migrate5-'.uniqid();
    $this->files->copyDirectory(FILAMENT5_FIXTURE, $this->sandbox);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->sandbox);
});

function migrateFilament5(mixed $test): void
{
    $test->artisan('alfakit:migrate-from-filament', [
        '--path' => $test->sandbox,
        '--keep-filament' => true,
        '--no-npm' => true,
        '--rector' => 'none',
    ])->assertSuccessful();
}

/**
 * @return array<string, string> relative path → md5, ignoring the report and backups
 */
function filament5Snapshot(string $root): array
{
    $hashes = [];

    foreach ((new Filesystem)->allFiles($root, true) as $file) {
        $relative = $file->getRelativePathname();

        if ($relative === 'alfakit-migration-report.md' || str_starts_with($relative, 'storage/alfakit-migration-backup-')) {
            continue;
        }

        $hashes[$relative] = md5($file->getContents());
    }

    ksort($hashes);

    return $hashes;
}

/**
 * @return array{constructs: int, automatic: int, manual: int, percent: float}
 */
function filament5Metrics(string $report): array
{
    preg_match('/\| Filament constructs found \| (\d+)/', $report, $constructs);
    preg_match('/\| Converted automatically \| (\d+) \(([\d.]+)%\)/', $report, $automatic);
    preg_match('/\| Need a manual step \| (\d+)/', $report, $manual);

    return [
        'constructs' => (int) ($constructs[1] ?? 0),
        'automatic' => (int) ($automatic[1] ?? 0),
        'manual' => (int) ($manual[1] ?? 0),
        'percent' => (float) ($automatic[2] ?? 0),
    ];
}

it('migrates the Filament 5 corpus: lint, no Filament references, TODO markers and ≥ 90% converted', function (): void {
    migrateFilament5($this);

    $root = $this->sandbox;
    $report = $this->files->get($root.'/alfakit-migration-report.md');
    $metrics = filament5Metrics($report);

    expect($metrics['constructs'])->toBeGreaterThan(400)
        ->and($metrics['automatic'] + $metrics['manual'])->toBe($metrics['constructs'])
        ->and($metrics['percent'])->toBeGreaterThanOrEqual(90.0);

    foreach ($this->files->allFiles($root.'/app') as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $lint = new Process([PHP_BINARY, '-l', $file->getPathname()]);
        $lint->run();
        expect($lint->isSuccessful())->toBeTrue($lint->getOutput().$lint->getErrorOutput());

        // Livewire components stay Livewire until their scaffolds are ported.
        if (! str_contains($file->getPathname(), '/app/Livewire/')) {
            $code = implode("\n", array_filter(preg_split('/\R/', $file->getContents()) ?: [], static fn (string $line): bool => ! str_contains($line, MigrationReport::TODO_MARKER)));

            expect($code)->not->toMatch('/\bFilament\\\\|Mokhosh\\\\|Saade\\\\|Kirschbaum\\\\|STS\\\\Filament|BezhanSalleh\\\\/', $file->getRelativePathname());
        }
    }

    // Resources, plugins and Filament 5.x APIs converted.
    expect($this->files->get($root.'/app/Alfakit/Resources/Products/Tables/ProductsTable.php'))
        ->toContain('->bulkActions([')
        ->toContain('->headerActions([')
        ->toContain("->weight('medium')")
        ->toContain("->trueIcon('star')")
        ->toContain('->paginated([10, 25, 50, 100])');
    expect($this->files->get($root.'/app/Alfakit/Resources/Orders/Pages/ListOrders.php'))->toContain('->modifyQueryUsing(fn (Builder $query)');
    expect($this->files->get($root.'/app/Alfakit/Resources/Orders/Schemas/OrderForm.php'))
        ->toContain('->live()')
        ->not->toContain('->reactive()')
        ->toContain("route('alfakit.admin.resources.products.edit'");
    expect($this->files->get($root.'/app/Alfakit/Resources/Products/RelationManagers/ReviewsRelationManager.php'))->not->toContain('->recordTitleAttribute(');
    expect($this->files->get($root.'/app/Alfakit/Clusters/Settings/Pages/ShippingSettings.php'))->toContain('->action(fn () => $this->save())');
    expect($this->files->get($root.'/app/Enums/OrderStatus.php'))->toContain('public function getIcon(): string')->toContain("self::Shipped => 'truck'");
    expect($this->files->get($root.'/app/Models/User.php'))->toContain('public function getAlfakitAvatarUrl(): ?string');
    expect($this->files->get($root.'/app/Models/Order.php'))
        ->toContain('use AlfaSix\\AlfakitComments\\Contracts\\Commentable;')
        ->toContain('use AlfaSix\\AlfakitComments\\Concerns\\HasComments;');
    expect($this->files->get($root.'/app/Alfakit/Widgets/DeliveryCalendar.php'))
        ->toContain('extends CalendarWidget')
        ->toContain('start: \\Carbon\\CarbonImmutable');
    expect($this->files->get($root.'/app/Alfakit/Resources/Users/UserResource.php'))
        ->toContain('ImpersonateAction::make()')
        ->toContain('use AlfaSix\\AlfakitMediaLibrary\\Forms\\Components\\SpatieMediaLibraryFileUpload;');
    expect($this->files->get($root.'/app/Alfakit/Pages/OrdersBoard.php'))
        ->toContain('extends \\AlfaSix\\Alfakit\\Pages\\Page')
        ->toContain('// '.MigrationReport::TODO_MARKER.': `KanbanBoard` (parent class)');
    expect($this->files->get($root.'/app/Alfakit/Pages/Reports.php'))->toContain('fn () => null')->toContain(MigrationReport::TODO_MARKER);
    expect($this->files->get($root.'/app/Providers/Alfakit/AdminPanelProvider.php'))
        ->toContain('->slot(\\AlfaSix\\Alfakit\\Theming\\Slot::BODY_START')
        ->toContain('->slot(\\AlfaSix\\Alfakit\\Theming\\Slot::SIDEBAR_NAV_END')
        ->toContain("'primary' => '#4f46e5'");
    expect($this->files->get($root.'/app/Providers/AppServiceProvider.php'))->toContain('\\AlfaSix\\Alfakit\\Theming\\Slots::register(');

    // Livewire components → custom component scaffolds in the auto-registered folder (Vue app).
    foreach (['CartCounter', 'NewsletterSignup', 'ProductCatalog'] as $component) {
        expect($this->files->get($root."/app/Alfakit/Components/{$component}.php"))
            ->toContain("protected ?string \$type = '{$component}';")
            ->toContain(MigrationReport::TODO_MARKER);
        expect($this->files->get($root."/resources/js/alfakit/components/{$component}.vue"))
            ->toContain('useSchemaComponentContext')
            ->toContain(MigrationReport::TODO_MARKER);
        expect($this->files->get($root."/app/Livewire/{$component}.php"))->toContain('// '.MigrationReport::TODO_MARKER.": Ported to App\\Alfakit\\Components\\{$component}");
    }

    expect($this->files->get($root.'/app/Alfakit/Components/CartCounter.php'))->toContain("'count' => 0,")->toContain("'label' => 'Cart',");
    expect($this->files->get($root.'/resources/js/alfakit/components/CartCounter.vue'))->toContain('`wire:click="increment"`');

    // Blade views and the theme carry a marker too.
    expect($this->files->get($root.'/resources/views/filament/pages/reports.blade.php'))->toStartWith('{{-- '.MigrationReport::TODO_MARKER);
    expect($this->files->get($root.'/resources/css/filament/admin/theme.css'))->toStartWith('/* '.MigrationReport::TODO_MARKER);

    // The report: summary, plugin command, grouped by file.
    expect($report)
        ->toContain('Detected Filament v5.10.1')
        ->toContain('## First-party plugins to add')
        ->toContain('php artisan alfakit:add activity-log calendar comments kanban media-library settings shield tags tree')
        ->toContain('## By file')
        ->toContain('### `app/Alfakit/Resources/Products/Tables/ProductsTable.php`')
        ->toContain('**TODO**')
        ->toContain('## By topic');
});

it('passes PHPStan level 5 against AlfaKit after the migration', function (): void {
    migrateFilament5($this);

    $root = dirname(__DIR__, 4);
    $autoload = $this->sandbox.'/phpstan-autoload.php';
    $this->files->put($autoload, '<?php spl_autoload_register(static function (string $class): void { if (str_starts_with($class, \'App\\\\\')) { $file = '.var_export($this->sandbox.'/app/', true).'.str_replace(\'\\\\\', \'/\', substr($class, 4)).\'.php\'; if (is_file($file)) { require_once $file; } } });');
    $config = $this->sandbox.'/phpstan.neon';
    $this->files->put($config, implode("\n", [
        'includes:',
        '    - '.$root.'/vendor/larastan/larastan/extension.neon',
        'parameters:',
        '    level: 5',
        '    paths:',
        '        - '.$this->sandbox.'/app',
        '    excludePaths:',
        // Livewire components are left in place (still Livewire) next to their AlfaKit scaffolds.
        '        - '.$this->sandbox.'/app/Livewire/*',
        '    bootstrapFiles:',
        '        - '.$autoload,
        '    databaseMigrationsPath:',
        '        - '.$this->sandbox.'/database/migrations',
        '    tmpDir: '.$this->sandbox.'/.phpstan',
        '    ignoreErrors:',
        // Larastan resolves view names against the analysing app, not the migrated one.
        "        - '#expects view-string\\|null, string given#'",
        '',
    ]));

    $process = new Process([PHP_BINARY, $root.'/vendor/bin/phpstan', 'analyse', '-c', $config, '--no-progress', '--error-format=raw', '--memory-limit=1G'], $root, timeout: 300);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
})->group('slow');

it('boots the migrated app with its own providers and renders every panel page', function (): void {
    migrateFilament5($this);

    $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/boot-migrated-app.php', $this->sandbox, ...FILAMENT5_PLUGIN_PROVIDERS], dirname(__DIR__, 4), timeout: 300);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    /** @var array<string, array{status: int|null, types?: array<int, string>, deferred?: array{status: int, types: array<int, string>}, error?: string}> $pages */
    $pages = json_decode($process->getOutput(), true)['pages'];

    $expected = [
        '/admin/acme', '/admin/acme/products', '/admin/acme/products/create', '/admin/acme/products/1', '/admin/acme/products/1/edit',
        '/admin/acme/orders', '/admin/acme/orders/create', '/admin/acme/orders/1/edit', '/admin/acme/customers',
        '/admin/acme/users', '/admin/acme/users/create', '/admin/acme/users/1/edit', '/admin/acme/reports', '/admin/acme/orders-board',
        '/admin/acme/settings/manage-brands', '/admin/acme/settings/shipping-settings', '/admin/acme/profile', '/admin/new',
    ];

    foreach ($expected as $uri) {
        expect($pages)->toHaveKey($uri);
    }

    foreach ($pages as $uri => $page) {
        expect($page['status'])->toBeIn([200, 302], "{$uri}: ".($page['error'] ?? json_encode($page)));

        if (isset($page['deferred'])) {
            expect($page['deferred']['status'])->toBe(200, "{$uri} (deferred props): ".($page['deferred']['error'] ?? ''));
        }
    }

    // The dashboard's widgets (stats, chart, table, calendar, the custom one) load.
    expect($pages['/admin/acme']['deferred']['types'])->toContain('StatsOverviewWidget', 'Chart', 'TableWidget', 'Calendar', 'CustomerMap');
    expect($pages['/admin/acme/orders']['types'])->toContain('Table', 'SelectFilter', 'TrashedFilter');
    expect($pages['/admin/acme/products/create']['types'])->toContain('RichEditor', 'Tabs', 'KeyValue', 'ToggleButtons', 'Flex');
    expect($pages['/admin/acme/orders/1/edit']['types'])->toContain('Repeater', 'MarkdownEditor', 'Radio');
})->group('slow');

it('is idempotent on the Filament 5 corpus', function (): void {
    migrateFilament5($this);
    $after = filament5Snapshot($this->sandbox);

    $this->artisan('alfakit:migrate-from-filament', [
        '--path' => $this->sandbox,
        '--keep-filament' => true,
        '--no-npm' => true,
        '--rector' => 'none',
    ])->assertSuccessful();

    expect(filament5Snapshot($this->sandbox))->toBe($after);
});

it('scaffolds Livewire components for React and Svelte too', function (string $adapter, string $extension): void {
    $this->artisan('alfakit:migrate-from-filament', [
        '--path' => $this->sandbox,
        '--keep-filament' => true,
        '--no-npm' => true,
        '--rector' => 'none',
        '--adapter' => $adapter,
    ])->assertSuccessful();

    $component = $this->files->get($this->sandbox."/resources/js/alfakit/components/CartCounter.{$extension}");

    expect($component)
        ->toContain("from '@alfakit/{$adapter}'")
        ->toContain('useSchemaComponentContext')
        ->toContain('count?: number')
        ->toContain('label?: string')
        ->toContain(MigrationReport::TODO_MARKER);
})->with([
    'react' => ['react', 'tsx'],
    'svelte' => ['svelte', 'svelte'],
]);
