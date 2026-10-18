<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\IconPicker;
use AlfaSix\Alfakit\Infolists\Components\IconEntry;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Icons\IconCatalog;
use AlfaSix\Alfakit\Support\Icons\IconifySet;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tests\Fixtures\Icons\IconPickerPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/*
 * Iconify sets (`Icons::iconify()`), the IconPicker field and its search endpoint, and
 * `alfakit:icons`.
 */

beforeEach(function (): void {
    config(['alfakit.icons.cache' => 'array', 'alfakit.icons.iconify_path' => __DIR__.'/../Fixtures/Icons/iconify']);
    Cache::store('array')->flush();
    Icons::flush();
});

afterEach(fn () => Icons::flush());

describe('iconify sets', function (): void {
    it('resolves icons, aliases and the set defaults to sanitised svg', function (): void {
        Icons::iconify('demo');

        $home = Icons::resolve('demo-home');

        expect($home)->toStartWith('<svg')
            ->toContain('viewBox="0 0 24 24"')
            ->toContain('fill="currentColor"')
            ->and(Icons::resolve('demo-house'))->toBe($home)
            ->and(Icons::resolve('demo-arrow'))->toContain('viewBox="0 0 32 16"');
    });

    it('applies alias flips and rotation like Iconify', function (): void {
        Icons::iconify('demo');

        expect(Icons::resolve('demo-arrow-left'))->toContain('transform="translate(32 0) scale(-1 1)"')
            ->toContain('viewBox="0 0 32 16"')
            // A quarter turn swaps the box.
            ->and(Icons::resolve('demo-arrow-down'))->toContain('rotate(90 8 8)')
            ->toContain('viewBox="0 0 16 32"');
    });

    it('strips scripts and handlers from set bodies', function (): void {
        Icons::iconify('demo');

        $evil = (string) Icons::resolve('demo-evil');

        expect($evil)->toContain('<path')
            ->not->toContain('script')
            ->not->toContain('onclick');
    });

    it('falls through to Lucide for names the set does not have', function (): void {
        Icons::iconify('demo');

        expect(Icons::resolve('demo-nope'))->toBe('demo-nope');
    });

    it('reads a json file path per set', function (): void {
        $file = __DIR__.'/../Fixtures/Icons/iconify/demo/icons.json';

        Icons::iconify(['brand' => $file]);
        Icons::iconify('other', $file);

        expect(Icons::resolve('brand-home'))->toStartWith('<svg')
            ->and(Icons::resolve('other-home'))->toStartWith('<svg')
            ->and(Icons::getSets()['brand'])->toBeInstanceOf(IconifySet::class);
    });

    it('caches resolved icons by file modification time', function (): void {
        $directory = sys_get_temp_dir().'/alfakit-iconify-'.uniqid();
        (new Filesystem)->copyDirectory(__DIR__.'/../Fixtures/Icons/iconify', $directory);
        $file = "{$directory}/demo/icons.json";
        touch($file, 1_700_000_000);
        clearstatcache();

        Icons::iconify('demo', $directory);
        $first = Icons::resolve('demo-home');

        // Same mtime: the cache answers, even after the per-request memo is gone.
        file_put_contents($file, str_replace('M10 20v-6h4v6h5v-8h3L12 3L2 12h3v8z', 'M1 1h1', (string) file_get_contents($file)));
        touch($file, 1_700_000_000);
        clearstatcache();
        IconifySet::flushMemo();

        expect(Icons::resolve('demo-home'))->toBe($first);

        // A new mtime (the package was updated) reads the file again.
        touch($file, 1_700_000_100);
        clearstatcache();
        IconifySet::flushMemo();

        expect(Icons::resolve('demo-home'))->toContain('M1 1h1');

        (new Filesystem)->deleteDirectory($directory);
    });

    it('lists visible icons with their aliases as keywords and the set name', function (): void {
        Icons::iconify('demo');
        $set = Icons::getSets()['demo'];

        expect($set)->toBeInstanceOf(IconifySet::class)
            ->and($set->names())->toBe(['arrow' => '', 'evil' => '', 'home' => 'house'])
            ->and($set->label())->toBe('Demo Icons');
    });

    it('handles a missing package without errors', function (): void {
        Icons::iconify('absent');

        expect(Icons::resolve('absent-home'))->toBe('absent-home')
            ->and(IconCatalog::icons('absent'))->toBe([]);
    });

    it('resolves collisions with Lucide names: sets first, lucide- prefix always Lucide', function (): void {
        // A `flag` set claims every `flag-*` name it has; others still reach Lucide.
        Icons::iconify(['flag' => __DIR__.'/../Fixtures/Icons/iconify/demo/icons.json']);

        expect(Icons::resolve('flag-home'))->toStartWith('<svg')
            ->and(Icons::resolve('flag-off'))->toBe('flag-off')
            ->and(Icons::resolve('lucide-flag-off'))->toBe('flag-off')
            ->and(Icons::resolve('lucide-house'))->toBe('house')
            // The catalogue stores Lucide names a set would claim with the lucide- prefix.
            ->and(IconCatalog::icons('lucide'))->toHaveKey('lucide-flag-off')
            ->and(IconCatalog::icons('lucide'))->toHaveKey('house');
    });

    it('lets sets with shorter prefixes try when a longer one lacks the icon', function (): void {
        Icons::registerSet('demo', __DIR__.'/../Fixtures/Icons/svg');
        Icons::iconify(['demo-x' => __DIR__.'/../Fixtures/Icons/iconify/demo/icons.json']);

        expect(Icons::resolve('demo-x-home'))->toStartWith('<svg')
            ->and(Icons::resolve('demo-logo'))->toContain('circle');
    });

    it('lets the last registration of a prefix win', function (): void {
        Icons::iconify('demo');
        Icons::registerSet('demo', __DIR__.'/../Fixtures/Icons/svg');

        expect(Icons::resolve('demo-home'))->toBe('demo-home')
            ->and(Icons::resolve('demo-box'))->toContain('rect');
    });
});

describe('icon picker field', function (): void {
    it('serialises its options and the selected icon', function (): void {
        Icons::iconify('demo');

        $schema = Schema::make()->components([
            IconPicker::make('icon')->gridColumns(6)->previewSize('lg')->allowCustomSvg()->placeholder('Pick one'),
        ])->fill(['icon' => 'demo-home']);

        $field = $schema->toPayload()['components'][0];

        expect($field['type'])->toBe('IconPicker')
            ->and($field['sets'])->toBe([['key' => 'lucide', 'label' => 'Lucide'], ['key' => 'demo', 'label' => 'Demo Icons']])
            ->and($field['searchable'])->toBeTrue()
            ->and($field['gridColumns'])->toBe(6)
            ->and($field['previewSize'])->toBe('lg')
            ->and($field['allowCustomSvg'])->toBeTrue()
            ->and($field['placeholder'])->toBe('Pick one')
            ->and($field['selected']['value'])->toBe('demo-home')
            ->and($field['selected']['icon'])->toStartWith('<svg');
    });

    it('searches ranked, set-filtered and paginated', function (): void {
        Icons::iconify('demo');
        $field = IconPicker::make('icon')->perPage(2);
        Schema::make()->components([$field]);

        $results = $field->getSearchResults('home', 'demo');

        expect(array_column($results['icons'], 'value'))->toBe(['demo-home'])
            ->and($results['icons'][0]['icon'])->toStartWith('<svg');

        // `house` is Lucide's own name and a demo alias; Lucide's exact match comes first.
        $house = $field->getSearchResults('house');
        expect($house['icons'][0])->toBe(['value' => 'house', 'icon' => 'house'])
            ->and(array_column($house['icons'], 'value'))->toContain('demo-home');

        $all = $field->getSearchResults('', 'demo');
        expect(array_column($all['icons'], 'value'))->toBe(['demo-arrow', 'demo-evil'])
            ->and($all['hasMore'])->toBeTrue()
            ->and($all['total'])->toBe(3)
            ->and(array_column($field->getSearchResults('', 'demo', 2)['icons'], 'value'))->toBe(['demo-home']);

        expect(array_column($field->getSearchResults('arrow up', 'lucide')['icons'], 'value'))->toContain('arrow-up');
    });

    it('validates that the value is an icon on offer', function (): void {
        Icons::iconify('demo');

        $schema = Schema::make()->components([
            IconPicker::make('icon'),
            IconPicker::make('demo_icon')->sets(['demo'])->exclude(['demo-arrow']),
            IconPicker::make('allowed')->allowedIcons(['house', 'demo-home']),
            IconPicker::make('svg')->allowCustomSvg(),
        ]);

        $errors = static function (array $state) use ($schema): array {
            $schema->applyClientState($state);

            return array_keys($schema->getValidator()->errors()->toArray());
        };

        expect($errors(['icon' => 'house', 'demo_icon' => 'demo-home', 'allowed' => 'demo-home', 'svg' => '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>']))->toBe([])
            ->and($errors(['icon' => 'not-an-icon', 'demo_icon' => 'demo-arrow', 'allowed' => 'users', 'svg' => 'nope-nope']))->toBe(['icon', 'demo_icon', 'allowed', 'svg'])
            ->and($errors(['icon' => '<svg viewBox="0 0 1 1"/>', 'demo_icon' => 'house', 'allowed' => null, 'svg' => null]))->toBe(['icon', 'demo_icon'])
            ->and($errors(['icon' => 'heroicon-o-user', 'demo_icon' => null, 'allowed' => null, 'svg' => null]))->toBe(['icon']);
    });

    it('stores custom svg sanitised', function (): void {
        $schema = Schema::make()->components([IconPicker::make('svg')->allowCustomSvg()]);
        $schema->applyClientState(['svg' => '<svg viewBox="0 0 1 1"><script>x</script><path onload="x" d="M0 0"/></svg>']);

        $state = $schema->getState()['svg'];

        expect($state)->toContain('<path')->not->toContain('script')->not->toContain('onload');
    });

    it('offers heroicon names and directory sets when asked', function (): void {
        Icons::registerSet('app', __DIR__.'/../Fixtures/Icons/svg');
        $field = IconPicker::make('icon')->sets(['heroicon-o', 'app', 'closure-only', 'lucide']);
        Schema::make()->components([$field]);

        expect($field->getSets())->toBe(['heroicon-o', 'app', 'lucide'])
            ->and($field->getIcons('app'))->toBe(['app-box' => '', 'app-logo' => ''])
            ->and($field->getIcons('heroicon-o'))->toHaveKey('heroicon-o-user')
            ->and($field->getSearchResults('user', 'heroicon-o')['icons'][0])->toBe(['value' => 'heroicon-o-user', 'icon' => 'user']);
    });

    it('renders picked names everywhere icons are accepted', function (): void {
        Icons::iconify('demo');
        $svg = Icons::resolve('demo-home');

        // IconColumn / IconEntry draw the icon named by the state.
        $column = IconColumn::make('icon');

        expect($column->getIconForState('demo-home')['icon'])->toBe($svg)
            ->and($column->getIconForState('lucide-house')['icon'])->toBe('house');

        $entry = Schema::make()->components([IconEntry::make('icon')])->fill(['icon' => 'demo-home'])->toPayload()['components'][0];

        expect($entry['icon'])->toBe($svg);
    });
});

describe('icon picker endpoint', function (): void {
    beforeEach(function (): void {
        Icons::iconify('demo');
        $panel = Panel::make()->id('icons')->path('icons')->login()->middleware(['web'])->pages([IconPickerPage::class]);

        alfakit()->registerPanel($panel);
        $panel->registerRoutes(app('router'));
        app('router')->getRoutes()->refreshNameLookups();
    });

    /**
     * @param  array<string, mixed>  $query
     */
    function iconPickerUrl(string $token, string $path, array $query = []): string
    {
        return '/icons/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'path' => $path, ...$query]);
    }

    function iconPickerToken(AlfaSix\Alfakit\Tests\TestCase $test): string
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);

        return inertiaProps(inertiaGet($test->actingAs($user), '/icons/icon-picker'))['page']['token'];
    }

    it('serves a page of icons for a search', function (): void {
        $token = iconPickerToken($this);

        $this->getJson(iconPickerUrl($token, 'icon', ['search' => 'home']))
            ->assertOk()
            ->assertJsonPath('page', 1)
            // The exact name before Lucide's `house` (alias `home`).
            ->assertJsonPath('icons.0.value', 'demo-home')
            ->assertJsonPath('icons.1.value', 'house');

        $page = $this->getJson(iconPickerUrl($token, 'icon'))->assertOk();

        expect($page->json('icons'))->toHaveCount(5)
            ->and($page->json('hasMore'))->toBeTrue()
            ->and($page->json('total'))->toBe(count(IconCatalog::lucide()) + 3);

        $second = $this->getJson(iconPickerUrl($token, 'icon', ['page' => 2]))->assertOk();
        expect($second->json('icons.0.value'))->not->toBe($page->json('icons.0.value'));

        $this->getJson(iconPickerUrl($token, 'demo_icon', ['set' => 'demo']))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('icons.0.value', 'demo-evil');

        $this->getJson(iconPickerUrl($token, 'allowed_icon'))
            ->assertOk()
            ->assertJsonPath('icons.0.value', 'house')
            ->assertJsonPath('icons.1.value', 'demo-home');
    });

    it('refuses hidden, disabled and unknown pickers and forged tokens', function (): void {
        $token = iconPickerToken($this);

        $this->getJson(iconPickerUrl($token, 'hidden_icon'))->assertNotFound();
        $this->getJson(iconPickerUrl($token, 'locked_icon'))->assertForbidden();
        $this->getJson(iconPickerUrl($token, 'nope'))->assertNotFound();
        $this->getJson(iconPickerUrl('forged', 'icon'))->assertStatus(400);

        auth()->logout();
        $this->getJson(iconPickerUrl($token, 'icon'))->assertForbidden();
    });
});

describe('alfakit:icons', function (): void {
    beforeEach(function (): void {
        $this->sandbox = sys_get_temp_dir().'/alfakit-icons-'.uniqid();
        (new Filesystem)->ensureDirectoryExists("{$this->sandbox}/config");
        file_put_contents("{$this->sandbox}/package.json", '{}');
        file_put_contents("{$this->sandbox}/pnpm-lock.yaml", '');
        copy(__DIR__.'/../../config/alfakit.php', "{$this->sandbox}/config/alfakit.php");

        $this->originalBasePath = $this->app->basePath();
        $this->app->setBasePath($this->sandbox);
        $this->app->useConfigPath($this->sandbox.'/config');
        config(['alfakit.icons.iconify' => []]);

        Process::fake();
    });

    afterEach(function (): void {
        $this->app->setBasePath($this->originalBasePath);
        $this->app->useConfigPath($this->originalBasePath.'/config');
        (new Filesystem)->deleteDirectory($this->sandbox);
    });

    it('installs the packages and registers the sets in the config', function (): void {
        $this->artisan('alfakit:icons', ['sets' => ['mdi', 'tabler']])
            ->expectsConfirmation('Install @iconify-json/mdi, @iconify-json/tabler with pnpm?', 'yes')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['pnpm', 'add', '@iconify-json/mdi', '@iconify-json/tabler']);

        $config = require "{$this->sandbox}/config/alfakit.php";

        expect($config['icons']['iconify'])->toBe(['mdi', 'tabler'])
            ->and(config('alfakit.icons.iconify'))->toBe(['mdi', 'tabler'])
            ->and(Icons::getSets())->toHaveKeys(['mdi', 'tabler']);
    });

    it('removes sets and keeps the others', function (): void {
        config(['alfakit.icons.iconify' => ['mdi', 'brand' => '/icons/brand.json']]);

        $this->artisan('alfakit:icons', ['sets' => ['mdi'], '--remove' => true])
            ->expectsConfirmation('Remove @iconify-json/mdi with pnpm?', 'yes')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['pnpm', 'remove', '@iconify-json/mdi']);

        $config = require "{$this->sandbox}/config/alfakit.php";

        expect($config['icons']['iconify'])->toBe(['brand' => '/icons/brand.json']);
    });

    it('only edits the config with --no-install, and stops when declined', function (): void {
        $this->artisan('alfakit:icons', ['sets' => ['ph'], '--no-install' => true])->assertSuccessful();

        Process::assertNothingRan();
        expect((require "{$this->sandbox}/config/alfakit.php")['icons']['iconify'])->toBe(['ph']);

        $this->artisan('alfakit:icons', ['sets' => ['mdi']])
            ->expectsConfirmation('Install @iconify-json/mdi with pnpm?', 'no')
            ->assertSuccessful();

        Process::assertNothingRan();
        expect((require "{$this->sandbox}/config/alfakit.php")['icons']['iconify'])->toBe(['ph']);
    });

    it('rejects invalid set names and reports a failed install', function (): void {
        $this->artisan('alfakit:icons', ['sets' => ['../evil'], '--no-install' => true])->assertFailed();

        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('alfakit:icons', ['sets' => ['mdi']])
            ->expectsConfirmation('Install @iconify-json/mdi with pnpm?', 'yes')
            ->assertFailed();

        expect((require "{$this->sandbox}/config/alfakit.php")['icons']['iconify'])->toBe([]);
    });

    it('searches the registered sets', function (): void {
        Icons::iconify('demo', __DIR__.'/../Fixtures/Icons/iconify');

        $this->artisan('alfakit:icons', ['--search' => 'home'])
            ->expectsTable(['Icon', 'Set'], [['house', 'lucide'], ['demo-home', 'demo']])
            ->assertSuccessful();

        $this->artisan('alfakit:icons', ['--search' => 'zzzz-nothing'])->assertSuccessful();
    });
});
