<?php

declare(strict_types=1);

use AlfaSix\Alfakit\AvatarProviders\Contracts\AvatarProvider;
use AlfaSix\Alfakit\AvatarProviders\InitialsAvatarProvider;
use AlfaSix\Alfakit\AvatarProviders\UiAvatarsProvider;
use AlfaSix\Alfakit\Clusters\Cluster;
use AlfaSix\Alfakit\Contracts\HasAvatar;
use AlfaSix\Alfakit\GlobalSearch\GlobalSearchProvider;
use AlfaSix\Alfakit\GlobalSearch\GlobalSearchResult;
use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Pages\PlansPage;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\ListPosts;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\RelationManagers\CommentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;
use AlfaSix\Alfakit\Theming\Slot;
use AlfaSix\Alfakit\Theming\Slots;
use AlfaSix\Alfakit\Theming\ThemeMode;
use AlfaSix\Alfakit\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

afterEach(fn () => Slots::flush());

// ---------------------------------------------------------------------- Slots

it('renders slots registered for every panel with Slots::register()', function (): void {
    Slots::register(Slot::TOPBAR_END, '<span>global</span>');
    Slots::register(Slot::TOPBAR_END, '<span>other panel</span>', panels: 'other');
    Slots::register(Slot::PAGE_START, '<p>posts</p>', scopes: PostResource::class, panels: ['admin']);
    alfakit()->getPanel('admin')->slot(Slot::TOPBAR_END, '<span>panel</span>');

    $this->actingAs(admin());
    $slots = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['slots'];

    expect(array_column($slots[Slot::TOPBAR_END], 'html'))->toBe(['<span>panel</span>', '<span>global</span>'])
        ->and($slots[Slot::PAGE_START][0]['html'])->toBe('<p>posts</p>');

    $dashboard = inertiaProps(inertiaGet($this, '/admin'))['alfakit']['slots'];
    expect($dashboard)->not->toHaveKey(Slot::PAGE_START);
});

it('only sends page-type slots on their pages', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->slot(Slot::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, '<p>list</p>');
    $panel->slot(Slot::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE, '<p>related</p>');
    $panel->slot(Slot::AUTH_LOGIN_FORM_BEFORE, '<p>sso</p>');
    $panel->slot(Slot::AUTH_REGISTER_FORM_BEFORE, '<p>terms</p>');

    $login = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['slots'];
    expect($login)->toHaveKey(Slot::AUTH_LOGIN_FORM_BEFORE)
        ->not->toHaveKeys([Slot::AUTH_REGISTER_FORM_BEFORE, Slot::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE]);

    $this->actingAs(admin());
    $list = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['slots'];
    $create = inertiaProps(inertiaGet($this, '/admin/posts/create'))['alfakit']['slots'] ?? [];

    expect($list[Slot::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE][0]['html'])->toBe('<p>list</p>')
        ->and($list)->not->toHaveKeys([Slot::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE, Slot::AUTH_LOGIN_FORM_BEFORE])
        ->and($create)->not->toHaveKey(Slot::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE);
});

it('marks slot content scoped to relation managers and widgets for those components', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->slot(Slot::RESOURCE_RELATION_MANAGER_BEFORE, '<p>comments</p>', scopes: CommentsRelationManager::class);
    $panel->slot(Slot::TABLE_WIDGET_START, '<p>widget</p>', scopes: [ChromeTableWidget::class]);
    $panel->slot(Slot::TABLE_TOOLBAR_END, '<p>list only</p>', scopes: [ListPosts::class, CommentsRelationManager::class]);

    $this->actingAs(admin());
    $list = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['slots'];
    $create = inertiaProps(inertiaGet($this, '/admin/posts/create'))['alfakit']['slots'];

    expect($list[Slot::RESOURCE_RELATION_MANAGER_BEFORE][0])->toBe(['type' => 'html', 'html' => '<p>comments</p>', 'for' => ['relationManager:'.CommentsRelationManager::class]])
        ->and($list[Slot::TABLE_WIDGET_START][0]['for'])->toBe(['widget:'.Widget::idFromClass(ChromeTableWidget::class)])
        // The page matches: shown in every table of the page.
        ->and($list[Slot::TABLE_TOOLBAR_END][0])->not->toHaveKey('for')
        // Elsewhere only in the relation manager's table.
        ->and($create[Slot::TABLE_TOOLBAR_END][0]['for'])->toBe(['relationManager:'.CommentsRelationManager::class]);
});

it('passes the current page and resource to slot closures as $scopes', function (): void {
    alfakit()->getPanel('admin')->slot(Slot::PAGE_END, fn (array $scopes): string => '<p>'.implode(',', array_map('class_basename', $scopes)).'</p>');

    $this->actingAs(admin());
    $slots = inertiaProps(inertiaGet($this, '/admin/posts/create'))['alfakit']['slots'];

    expect($slots[Slot::PAGE_END][0]['html'])->toBe('<p>CreatePost,PostResource</p>');
});

it('renders global head slots in the root view', function (): void {
    Slots::register(Slot::HEAD_END, '<meta name="global-head" content="1">');

    expect(alfakit()->getPanel('admin')->renderHeadSlot(Slot::HEAD_END))->toContain('global-head');
});

it('places every renderer slot in all three renderers', function (): void {
    $root = dirname(__DIR__, 3);
    $sources = [];

    foreach (['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'] as $renderer => $extension) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$renderer.'/src'));
        $sources[$renderer] = '';

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $sources[$renderer] .= file_get_contents($file->getPathname());
            }
        }
    }

    foreach (array_diff(Slot::all(), Slot::head()) as $name) {
        foreach ($sources as $renderer => $source) {
            expect(str_contains($source, 'name="'.$name.'"'))->toBeTrue("Slot [{$name}] is missing from the {$renderer} renderer.");
        }
    }
});

// ---------------------------------------------------------------------- Panel chrome

it('serialises the top bar, theme switcher and default theme mode', function (): void {
    $panel = alfakit()->getPanel('admin');
    $schema = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit'];

    expect($schema)->not->toHaveKey('topbar')
        ->and($schema['darkMode'])->toBe(['enabled' => true, 'forced' => false]);

    $panel->topbar(false)->themeSwitcher(fn (): bool => false)->defaultThemeMode(ThemeMode::Dark);
    $schema = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit'];

    expect($schema['topbar'])->toBeFalse()
        ->and($schema['darkMode'])->toBe(['enabled' => true, 'forced' => false, 'default' => 'dark', 'switcher' => false]);
});

it('resolves the appearance from the stored choice, the default mode and the switcher', function (): void {
    $panel = Panel::make('chrome');

    expect($panel->resolveAppearance(null))->toBe('system')
        ->and($panel->resolveAppearance('light'))->toBe('light');

    $panel->defaultThemeMode(ThemeMode::Dark);
    expect($panel->resolveAppearance(null))->toBe('dark')
        ->and($panel->resolveAppearance('bogus'))->toBe('dark')
        ->and($panel->resolveAppearance('system'))->toBe('system');

    $panel->themeSwitcher(false);
    expect($panel->resolveAppearance('light'))->toBe('dark');

    expect($panel->darkMode(false)->resolveAppearance('dark'))->toBe('light')
        ->and($panel->darkMode(true, isForced: true)->resolveAppearance('light'))->toBe('dark');
});

it('makes the tenant menu searchable from 10 tenants or on request', function (): void {
    $panel = Panel::make('chrome');

    expect($panel->isTenantMenuSearchable(10))->toBeFalse()
        ->and($panel->isTenantMenuSearchable(11))->toBeTrue()
        ->and($panel->searchableTenantMenu()->isTenantMenuSearchable(2))->toBeTrue()
        ->and($panel->searchableTenantMenu(false)->isTenantMenuSearchable(50))->toBeFalse()
        ->and($panel->searchableTenantMenu(fn (): bool => true)->isTenantMenuSearchable(0))->toBeTrue()
        ->and($panel->hasTenantSwitcher())->toBeTrue()
        ->and($panel->tenantSwitcher(false)->hasTenantSwitcher())->toBeFalse();
});

it('serialises the tenant switcher and menu search for the current user', function (): void {
    $panel = Panels::register('chrometeams', fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->resources([ProjectResource::class]));

    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $user = TenantUser::query()->create(['name' => 'Mia', 'email' => 'mia@example.com', 'password' => 'secret']);
    $user->teams()->attach([$alpha->id, $beta->id]);
    $this->actingAs($user);

    $tenancy = inertiaProps(inertiaGet($this, '/chrometeams/alpha/projects'))['alfakit']['tenancy'];
    expect($tenancy['tenants'])->toHaveCount(2)->and($tenancy)->not->toHaveKeys(['switcher', 'searchable']);

    $panel->searchableTenantMenu();
    $tenancy = inertiaProps(inertiaGet($this, '/chrometeams/alpha/projects'))['alfakit']['tenancy'];
    expect($tenancy['searchable'])->toBeTrue();

    $panel->tenantSwitcher(false);
    $tenancy = inertiaProps(inertiaGet($this, '/chrometeams/alpha/projects'))['alfakit']['tenancy'];
    expect($tenancy['tenants'])->toBe([])
        ->and($tenancy['switcher'])->toBeFalse()
        ->and($tenancy)->not->toHaveKey('searchable')
        ->and($tenancy['current']['name'])->toBe('Alpha');
});

it('uses the default avatar provider when users have no avatar', function (): void {
    $panel = alfakit()->getPanel('admin');
    $user = new User(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    expect($panel->getUserAvatarUrl($user))->toBeNull();

    $panel->defaultAvatarProvider(UiAvatarsProvider::class);
    expect($panel->getUserAvatarUrl($user))->toBe('https://ui-avatars.com/api/?name=Ada%20Lovelace&color=FFFFFF&background=09090b');

    $panel->defaultAvatarProvider(new InitialsAvatarProvider);
    $url = (string) $panel->getUserAvatarUrl($user);
    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))))->toContain('>AL</text>');

    // HasAvatar wins; a null avatar falls back to the provider.
    expect($panel->getUserAvatarUrl(new ChromeAvatarUser(['name' => 'Bo'], '/bo.png')))->toBe('/bo.png')
        ->and($panel->getUserAvatarUrl(new ChromeAvatarUser(['name' => 'Bo'], null)))->toStartWith('data:image/svg+xml');

    $panel->defaultAvatarProvider(fn (): AvatarProvider => new class implements AvatarProvider
    {
        public function get(Model|Authenticatable $record): string
        {
            return '/avatars/'.$record->getAttribute('name');
        }
    });
    expect($panel->getUserAvatarUrl($user))->toBe('/avatars/Ada Lovelace');
});

it('rejects avatar provider classes that do not implement the contract', function (): void {
    alfakit()->getPanel('admin')->defaultAvatarProvider(stdClass::class);
})->throws(InvalidArgumentException::class);

it('draws initials from up to two words', function (): void {
    expect(InitialsAvatarProvider::initials('ada'))->toBe('A')
        ->and(InitialsAvatarProvider::initials('  ada  byron lovelace '))->toBe('AL')
        ->and(InitialsAvatarProvider::initials(''))->toBe('?')
        ->and(InitialsAvatarProvider::initials('élodie roux'))->toBe('ÉR');
});

it('serialises navigation group attributes without event handlers', function (): void {
    $schema = NavigationGroup::make('Shop')
        ->extraSidebarAttributes(['class' => 'shop', 'data-group' => 'shop', 'onclick' => 'alert(1)', 'bad name' => 'x'])
        ->extraTopbarAttributes(fn (): array => ['class' => 'shop-top'])
        ->items([NavigationItem::make('Orders')->url('/orders')])
        ->toSchema();

    expect($schema['extraSidebarAttributes'])->toBe(['class' => 'shop', 'data-group' => 'shop'])
        ->and($schema['extraTopbarAttributes'])->toBe(['class' => 'shop-top'])
        ->and(NavigationGroup::make('Plain')->toSchema())->not->toHaveKeys(['extraSidebarAttributes', 'extraTopbarAttributes']);
});

// ---------------------------------------------------------------------- Pages

it('passes the page navigation badge tooltip to its item', function (): void {
    $this->actingAs(admin());
    $this->get('/admin');

    $item = ChromeBadgePage::buildNavigationItems()[0]->toSchema();

    expect($item['badge'])->toBe(3)->and($item['badgeTooltip'])->toBe('Open plans');
});

it('serialises header actions alignment and body attributes', function (): void {
    $page = new ChromeBodyPage;
    $props = (fn (): array => [
        'alignment' => $this->getHeaderActionsAlignment(),
        'body' => $this->getExtraBodyAttributesSchema(),
    ])->call($page);

    expect($props['alignment'])->toBe(Alignment::Center)
        ->and($props['body'])->toBe(['class' => 'settings-page', 'data-page' => 'settings', 'hidden' => '']);
});

it('passes getWidgetData() to the widgets that declare the properties', function (): void {
    $page = new ChromeWidgetsPage;
    $widgets = array_values((fn (): array => $this->instantiateWidgets([ChromeDataWidget::class, ChromeDataWidget::make(['status' => 'given'])]))->call($page));

    expect($widgets[0]->status)->toBe('from page')
        ->and($widgets[0]->stats)->toBe(['total' => 3])
        // Properties given to make() win.
        ->and($widgets[1]->status)->toBe('given');
});

it('hides the cluster sub-navigation with $shouldRegisterSubNavigation', function (): void {
    expect(ChromeQuietCluster::shouldRegisterSubNavigation())->toBeFalse()
        ->and((new ChromeClusteredPage)->getSubNavigation())->toBe([]);
});

// ---------------------------------------------------------------------- Global search

it('sorts global search groups by $globalSearchSort, unsorted last', function (): void {
    $panel = Panel::make('chrome')->resources([ChromeSearchUnsorted::class, ChromeSearchSecond::class, ChromeSearchFirst::class]);

    $labels = array_column((new GlobalSearchProvider($panel))->search('x'), 'label');

    expect($labels)->toBe(['First', 'Second', 'Unsorted']);
});

it('only searches opted-in resources with globalSearchResourceOptIn()', function (): void {
    $panel = alfakit()->getPanel('admin');

    expect(ChromeSearchFirst::isGloballySearchable())->toBeTrue()
        ->and(ChromeSearchOptedIn::isGloballySearchable())->toBeTrue();

    $panel->globalSearchResourceOptIn();

    expect(ChromeSearchFirst::isGloballySearchable())->toBeFalse()
        ->and(ChromeSearchOptedIn::isGloballySearchable())->toBeTrue()
        ->and(PostResource::canGloballySearch())->toBeFalse();
});

it('searches the whole phrase when $shouldSplitGlobalSearchTerms is false', function (): void {
    $user = admin();
    $this->actingAs($user);
    Post::query()->create(['title' => 'Blue whale', 'slug' => 'a', 'author_id' => $user->id]);
    Post::query()->create(['title' => 'Whale blue', 'slug' => 'b', 'author_id' => $user->id]);

    expect(PostResource::getGlobalSearchResults('blue whale')->pluck('title')->all())->toEqualCanonicalizing(['Blue whale', 'Whale blue'])
        ->and(ChromePhrasePostResource::getGlobalSearchResults('blue whale')->pluck('title')->all())->toBe(['Blue whale']);
});

it('serialises the global search debounce and field suffix', function (): void {
    $panel = alfakit()->getPanel('admin');
    $this->actingAs(admin());
    Post::query()->create(['title' => 'x', 'slug' => 'x', 'author_id' => 1]);

    $search = inertiaProps(inertiaGet($this, '/admin'))['alfakit']['globalSearch'];
    expect($search)->not->toHaveKeys(['debounce', 'suffix', 'keyBindingSuffix']);

    $panel->globalSearchDebounce('1.5s')->globalSearchFieldSuffix(fn (): string => 'Ctrl K')->globalSearchFieldKeyBindingSuffix(false)->globalSearchKeyBindings(['ctrl+/']);
    $search = inertiaProps(inertiaGet($this, '/admin'))['alfakit']['globalSearch'];

    expect($search)->toMatchArray(['debounce' => 1500, 'suffix' => 'Ctrl K', 'keyBindingSuffix' => false, 'keyBindings' => ['ctrl+/']])
        ->and($panel->globalSearchDebounce('750ms')->getGlobalSearchDebounce())->toBe(750)
        ->and($panel->globalSearchDebounce(300)->getGlobalSearchDebounce())->toBe(300)
        ->and($panel->globalSearchDebounce('soon')->getGlobalSearchDebounce())->toBeNull();
});

// ---------------------------------------------------------------------- Fixtures

class ChromeAvatarUser extends User implements HasAvatar
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [], private ?string $avatar = null)
    {
        parent::__construct($attributes);
    }

    public function getAlfakitAvatarUrl(): ?string
    {
        return $this->avatar;
    }
}

class ChromeTableWidget extends Widget {}

class ChromeDataWidget extends Widget
{
    public ?string $status = null;

    /** @var array<string, int> */
    public array $stats = [];
}

class ChromeWidgetsPage extends Page
{
    public function getWidgetData(): array
    {
        return ['status' => 'from page', 'stats' => ['total' => 3], 'unknown' => true];
    }
}

class ChromeBadgePage extends PlansPage
{
    protected static ?string $navigationBadgeTooltip = 'Open plans';

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationBadge(): string|int|null
    {
        return 3;
    }
}

class ChromeBodyPage extends Page
{
    protected ?Alignment $headerActionsAlignment = Alignment::Center;

    protected array $extraBodyAttributes = ['class' => 'settings-page', 'data-page' => 'settings', 'onload' => 'x()', 'hidden' => true, 'data-off' => false];
}

class ChromeQuietCluster extends Cluster
{
    protected static bool $shouldRegisterSubNavigation = false;
}

class ChromeClusteredPage extends Page
{
    protected static ?string $cluster = ChromeQuietCluster::class;
}

abstract class ChromeSearchResource extends PostResource
{
    protected static string $label = '';

    public static function canGloballySearch(): bool
    {
        return true;
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return static::$label;
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        return collect([new GlobalSearchResult(title: static::$label, url: '/'.static::$label)]);
    }
}

class ChromeSearchUnsorted extends ChromeSearchResource
{
    protected static string $label = 'Unsorted';
}

class ChromeSearchSecond extends ChromeSearchResource
{
    protected static string $label = 'Second';

    protected static ?int $globalSearchSort = 2;
}

class ChromeSearchFirst extends ChromeSearchResource
{
    protected static string $label = 'First';

    protected static ?int $globalSearchSort = 1;
}

class ChromeSearchOptedIn extends PostResource
{
    protected static bool $isGloballySearchable = true;
}

class ChromePhrasePostResource extends PostResource
{
    protected static ?bool $shouldSplitGlobalSearchTerms = false;
}
