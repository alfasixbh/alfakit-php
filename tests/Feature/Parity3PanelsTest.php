<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Pages\PageConfiguration;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\ParentRecords;
use AlfaSix\Alfakit\Resources\ParentResourceRegistration;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManagerConfiguration;
use AlfaSix\Alfakit\Support\Assets\Assets;
use AlfaSix\Alfakit\Support\Enums\GlobalSearchPosition;
use AlfaSix\Alfakit\Support\Enums\UserMenuPosition;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PostCommentResource;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3SettingsConfiguration;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3SettingsPage;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\RelationManagers\P3CommentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\RelationManagers\P3TagsRelationManager;
use Illuminate\Routing\Router;

beforeEach(function (): void {
    $this->panel = P3PanelProvider::registerPanel();
    alfakit()->setCurrentPanel($this->panel);
    $this->actingAs(User::query()->create(['name' => 'P3', 'email' => 'p3'.uniqid().'@example.com', 'password' => 'password']));
    $this->post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello-'.uniqid()]);
    $this->post->comments()->create(['author_name' => 'Ann', 'body' => 'first']);
    $this->post->comments()->create(['author_name' => 'Bob', 'body' => 'second']);
    $this->post->tags()->attach(Tag::query()->create(['name' => 'news']));
    P3TagsRelationManager::$badgeCalls = 0;
});

afterEach(function (): void {
    P3SettingsPage::setConfiguration(null);
    ParentRecords::flush();
});

/**
 * @return array<string, mixed>
 */
function p3Managers(array $props): array
{
    return collect($props['page']['content'])->firstWhere('type', 'RelationManagers');
}

// ------------------------------------------------------------ Relation managers

it('passes make([...]) properties to relation managers', function (): void {
    $configuration = P3CommentsRelationManager::make(['author' => 'Ann']);

    expect($configuration)->toBeInstanceOf(RelationManagerConfiguration::class)
        ->and($configuration->getProperties())->toBe(['author' => 'Ann']);

    $managers = p3Managers(inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit")));
    $comments = collect($managers['managers'])->firstWhere('slug', 'comments');

    expect(array_map(fn (array $record): string => $record['cells']['author_name']['state'], $comments['table']['records']))->toBe(['Ann']);
});

it('refuses properties a relation manager does not declare', function (): void {
    P3CommentsRelationManager::make($this->post, app(AlfaSix\Alfakit\Tests\Fixtures\Parity3\Pages\EditP3Post::class))->fillProperties(['missing' => 1]);
})->throws(InvalidArgumentException::class);

it('uses getRelations() keys as tab slugs, identifiers and table query-string prefixes', function (): void {
    $managers = p3Managers(inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit")));
    $comments = collect($managers['managers'])->firstWhere('slug', 'comments');

    expect($comments['component'])->toBe('relationManager:'.P3CommentsRelationManager::class.'@comments')
        ->and(collect($managers['managers'])->pluck('slug')->all())->toBe(['comments', 'tags'])
        ->and($comments['table']['queryStringIdentifier'] ?? $comments['table']['queryKey'] ?? 'comments')->toContain('comments');
});

it('defers relation manager badges to the deferred request', function (): void {
    $response = inertiaGet($this, "/p3/posts/{$this->post->id}/edit");
    $tags = collect(p3Managers(inertiaProps($response))['managers'])->firstWhere('slug', 'tags');

    expect($tags['badgeDeferred'])->toBeTrue()
        ->and($tags)->not->toHaveKey('badge')
        ->and(P3TagsRelationManager::$badgeCalls)->toBe(0)
        ->and($response->json('deferredProps'))->toBe([Page::LAZY_PROP => [Page::LAZY_PROP]]);

    $url = "/p3/posts/{$this->post->id}/edit";
    $deferred = inertiaProps($this->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $response->json('component'),
        'X-Inertia-Partial-Data' => Page::LAZY_PROP,
    ]));
    $target = 'relationManagerBadge:'.P3TagsRelationManager::class.'@tags';

    expect($deferred[Page::LAZY_PROP][$target])->toBe(['badge' => '42']);
});

// ------------------------------------------------------------ Nested resources

it('links a nested resource without an index to the parent page named by page()', function (): void {
    $registration = P3PostCommentResource::getParentResourceRegistration();

    expect($registration)->toBeInstanceOf(ParentResourceRegistration::class)
        ->and($registration->getPageName(P3PostCommentResource::class))->toBe('manageComments')
        ->and(P3PostResource::asParent()->getPageName(P3PostCommentResource::class))->toBe('comments');

    $comment = $this->post->comments()->first();
    $breadcrumbs = inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/comments/{$comment->id}/edit"))['page']['breadcrumbs'];

    expect(collect($breadcrumbs)->pluck('url')->filter()->values()->all())->toContain("http://localhost/p3/posts/{$this->post->id}/comments");

    alfakit()->setCurrentPanel($this->panel);
    ParentRecords::set(P3PostResource::class, $this->post);

    expect(P3PostCommentResource::getUrl())->toBe("http://localhost/p3/posts/{$this->post->id}/comments");
});

it('redirects a delete on a nested record page without an index to the parent page (W-V5-12)', function (): void {
    $comment = $this->post->comments()->first();
    $props = inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/comments/{$comment->id}/edit"));

    inertiaPost($this, '/p3/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'delete'])
        ->assertRedirect("http://localhost/p3/posts/{$this->post->id}/comments");

    expect($comment->fresh())->toBeNull();
});

// ------------------------------------------------------------ Configurable pages

it('registers configurable pages under their own URLs with their options', function (): void {
    $configuration = P3SettingsPage::make('general');

    expect($configuration)->toBeInstanceOf(P3SettingsConfiguration::class)
        ->and($configuration->getKey())->toBe('general')
        ->and($this->panel->getPageConfigurations(P3SettingsPage::class))->toHaveCount(2)
        ->and(P3SettingsPage::withConfiguration('general', fn (): string => P3SettingsPage::getUrl()))->toBe('http://localhost/p3/settings/general')
        ->and(P3SettingsPage::withConfiguration('advanced', fn (): string => P3SettingsPage::getUrl()))->toBe('http://localhost/p3/advanced-settings');

    expect(inertiaProps(inertiaGet($this, '/p3/settings/general'))['page']['title'])->toBe('Settings general')
        ->and(inertiaProps(inertiaGet($this, '/p3/advanced-settings'))['page']['title'])->toBe('Settings advanced');

    // The plain class is not registered, so there is no `/p3/settings`.
    $this->get('/p3/settings', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertNotFound();
});

it('lists one navigation item per page configuration, active on its own route', function (): void {
    $navigation = navigationWithState(inertiaProps(inertiaGet($this, '/p3/advanced-settings'))['alfakit']);
    $items = collect($navigation)->flatMap(fn (array $group): array => $group['items'])->keyBy('label');

    expect($items->keys()->all())->toContain('Settings: general', 'Settings: advanced')
        ->and($items['Settings: advanced']['active'] ?? false)->toBeTrue()
        ->and($items['Settings: general']['active'] ?? false)->toBeFalse();
});

it('keeps the page configuration on internal requests', function (): void {
    $token = inertiaProps(inertiaGet($this, '/p3/advanced-settings'))['page']['token'];
    P3SettingsPage::setConfiguration(null);
    alfakit()->setCurrentPanel($this->panel);

    $page = P3SettingsPage::fromContext(AlfaSix\Alfakit\Support\SchemaContext::fromToken($token));

    expect($page->category)->toBe('advanced')
        ->and(P3SettingsPage::getConfiguration()?->getKey())->toBe('advanced');
});

it('rejects invalid page configuration keys', function (): void {
    PageConfiguration::make(P3SettingsPage::class, 'two words');
})->throws(InvalidArgumentException::class);

// ------------------------------------------------------------ Page database transactions

it('runs page actions in transactions from $hasDatabaseTransactions unless the action chooses', function (): void {
    $page = P3SettingsPage::withConfiguration('general', fn (): P3SettingsPage => app(P3SettingsPage::class));

    expect($page->hasDatabaseTransactions())->toBeTrue()
        ->and($page->resolveAction('page', 'touch')->shouldUseDatabaseTransaction())->toBeTrue()
        ->and($page->resolveAction('page', 'outside')->shouldUseDatabaseTransaction())->toBeFalse()
        ->and($this->panel->hasDatabaseTransactions())->toBeFalse();
});

// ------------------------------------------------------------ Panel options

it('serialises the global search and user menu positions', function (): void {
    $panel = inertiaProps(inertiaGet($this, '/p3/posts'))['alfakit'];

    expect($panel['userMenu']['position'])->toBe('topbar')
        ->and($this->panel->getGlobalSearchPosition())->toBe(GlobalSearchPosition::Sidebar)
        ->and($this->panel->getUserMenuPosition())->toBe(UserMenuPosition::Topbar);

    // Without a top bar the user menu stays in the sidebar and search moves there.
    $bare = Panel::make('p3-bare')->topbar(false)->userMenu(position: UserMenuPosition::Topbar);

    expect($bare->getUserMenuPosition())->toBe(UserMenuPosition::Sidebar)
        ->and($bare->getGlobalSearchPosition())->toBe(GlobalSearchPosition::Sidebar)
        ->and(Panel::make('p3-default')->getGlobalSearchPosition())->toBe(GlobalSearchPosition::Topbar)
        ->and(Panel::make('p3-default')->globalSearchPosition('sidebar')->getGlobalSearchPosition())->toBe(GlobalSearchPosition::Sidebar);
});

it('uses the panel sub-navigation position as the default', function (): void {
    expect($this->panel->getSubNavigationPosition())->toBe(SubNavigationPosition::End)
        ->and(Panel::make('p3-default')->getSubNavigationPosition())->toBe(SubNavigationPosition::Start)
        ->and(app(P3SettingsPage::class)->getSubNavigationPosition())->toBe(SubNavigationPosition::End);
});

it('starts breadcrumbs with the navigation group with hasNavigationHierarchy', function (): void {
    $breadcrumbs = inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit"))['page']['breadcrumbs'];

    expect($breadcrumbs[0])->toBe(['label' => 'Content'])
        ->and(collect($breadcrumbs)->pluck('label')->all())->toContain('Posts');

    $settings = inertiaProps(inertiaGet($this, '/p3/settings/general'))['page']['breadcrumbs'];

    expect(collect($settings)->pluck('label')->all())->toBe(['System', 'Settings general']);
});

it('registers panel assets for the panel only', function (): void {
    expect($this->panel->getAssets())->toHaveCount(1)
        ->and(collect(Assets::getStyles('p3'))->map->getId()->all())->toContain('p3-panel')
        ->and(collect(Assets::getStyles('admin'))->map->getId()->all())->not->toContain('p3-panel');
});

it('serves a panel on several domains and links to the current one', function (): void {
    $panel = Panel::make('p3-domains')->path('multi')->domains(['one.test', 'two.test'])->middleware(['web']);
    alfakit()->registerPanel($panel);
    $router = app(Router::class);
    $panel->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    expect($panel->getDomains())->toBe(['one.test', 'two.test'])
        ->and($panel->getDomain())->toBe('one.test')
        ->and(collect($router->getRoutes()->getRoutes())->filter(fn ($route): bool => $route->getName() === 'alfakit.p3-domains.home')->map->getDomain()->values()->all())
        ->toEqualCanonicalizing(['one.test', 'two.test'])
        ->and($panel->route('home'))->toBe('http://one.test/multi');

    $this->app['request']->headers->set('HOST', 'two.test');
    $this->app['request']->server->set('HTTP_HOST', 'two.test');

    expect($panel->route('home'))->toBe('http://two.test/multi')
        ->and(Panel::make('p3-single')->getDomains())->toBe([null]);
});

it('requires a tenant subscription per page and resource', function (): void {
    expect(P3SettingsPage::isTenantSubscriptionRequired($this->panel))->toBeFalse()
        ->and(P3PostResource::isTenantSubscriptionRequired($this->panel))->toBeFalse()
        ->and(AlfaSix\Alfakit\Tests\Fixtures\Parity3\Pages\EditP3Post::isTenantSubscriptionRequired($this->panel))->toBeFalse();
});

it('runs exposed page methods in a transaction from the page setting unless the method chooses', function (): void {
    $controller = app(AlfaSix\Alfakit\Http\Controllers\PageMethodController::class);
    $uses = fn (AlfaSix\Alfakit\Pages\Attributes\Expose $expose, object $page): bool => (fn () => $this->usesTransaction($expose, $page))->call($controller);

    $withTransactions = new class
    {
        public function hasDatabaseTransactions(): ?bool
        {
            return true;
        }
    };
    $withoutSetting = new class
    {
        public function hasDatabaseTransactions(): ?bool
        {
            return null;
        }
    };

    expect($uses(new AlfaSix\Alfakit\Pages\Attributes\Expose, $withTransactions))->toBeTrue()
        ->and($uses(new AlfaSix\Alfakit\Pages\Attributes\Expose(transaction: false), $withTransactions))->toBeFalse()
        ->and($uses(new AlfaSix\Alfakit\Pages\Attributes\Expose, $withoutSetting))->toBe(alfakit()->getCurrentOrDefaultPanel()->hasDatabaseTransactions());
});

it('refuses one relation manager class registered twice with different properties and no keys (W-V5-11)', function (): void {
    P3PostResource::$relationsOverride = [
        AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup::make('Discussion', [
            P3CommentsRelationManager::make(['author' => 'Ann']),
            P3CommentsRelationManager::class,
        ]),
    ];

    try {
        $this->withoutExceptionHandling();

        expect(fn () => $this->get("/p3/posts/{$this->post->id}/edit"))
            ->toThrow(InvalidArgumentException::class, 'registered more than once');
    } finally {
        P3PostResource::$relationsOverride = null;
    }
});

it('still accepts the same relation manager twice when keyed or identical', function (): void {
    P3PostResource::$relationsOverride = [
        'ann' => P3CommentsRelationManager::make(['author' => 'Ann']),
        'all' => P3CommentsRelationManager::class,
        AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup::make('Again', [P3TagsRelationManager::class]),
        P3TagsRelationManager::class,
    ];

    try {
        $managers = p3Managers(inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit")));

        expect(collect($managers['managers'])->pluck('slug')->filter()->values()->all())->toBe(['ann', 'all']);
    } finally {
        P3PostResource::$relationsOverride = null;
    }
});

it('defers relation group badges with deferBadge()', function (): void {
    $calls = 0;
    P3PostResource::$relationsOverride = [
        'discussion' => AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup::make('Discussion', [
            'comments' => P3CommentsRelationManager::class,
            'tags' => P3TagsRelationManager::class,
        ])->badge(function () use (&$calls): int {
            $calls++;

            return 7;
        })->badgeColor(fn (int $badge): string => $badge > 5 ? 'danger' : 'gray')->deferBadge(),
    ];

    try {
        $url = "/p3/posts/{$this->post->id}/edit";
        $response = inertiaGet($this, $url);
        $group = p3Managers(inertiaProps($response))['groups'][0];

        expect($group['badgeDeferred'])->toBeTrue()
            ->and($group)->not->toHaveKey('badge')
            ->and($calls)->toBe(0);

        $deferred = inertiaProps($this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
            'X-Inertia-Partial-Component' => $response->json('component'),
            'X-Inertia-Partial-Data' => Page::LAZY_PROP,
        ]));

        expect($deferred[Page::LAZY_PROP]['relationGroupBadge:'.$group['key']])->toBe(['badge' => 7, 'badgeColor' => 'danger']);
    } finally {
        P3PostResource::$relationsOverride = null;
    }
});
