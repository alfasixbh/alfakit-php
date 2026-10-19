<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\PanelNav\NavPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\PanelNav\NoteResource;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    NavPanelProvider::registerPanel();
    $this->actingAs(User::query()->create(['name' => 'Nav', 'email' => 'nav@example.com', 'password' => 'password']));
});

afterEach(function (): void {
    Icons::flush();
});

function navNote(): Post
{
    return Post::query()->create(['title' => 'First note', 'slug' => 'first-note']);
}

// ---------------------------------------------------------------- Clusters

it('prefixes clustered page and resource routes with the cluster slug', function (): void {
    expect(route('alfakit.nav.pages.settings'))->toBe('http://localhost/nav/settings')
        ->and(route('alfakit.nav.pages.general-settings'))->toBe('http://localhost/nav/settings/general-settings')
        ->and(route('alfakit.nav.resources.tags.index'))->toBe('http://localhost/nav/settings/tags');
});

it('redirects the cluster URL to its first page', function (): void {
    $this->get('/nav/settings')->assertRedirect('http://localhost/nav/settings/general-settings');
});

it('shows one cluster item in the main navigation and the cluster pages as sub-navigation', function (): void {
    $props = inertiaProps(inertiaGet($this, '/nav/settings/general-settings'));

    $labels = collect($props['alfakit']['navigation'])->flatMap(fn (array $group): array => array_column($group['items'], 'label'))->all();

    expect($labels)->toContain('Settings')
        ->not->toContain('General')
        ->not->toContain('Billing')
        ->not->toContain('Tags');

    // The navigation structure is a once prop; the active items travel as `navigationState`.
    $active = collect($props['alfakit']['navigation'])->flatMap(fn (array $group, int $g): array => collect($group['items'])->map(fn (array $item, int $i): array => [...$item, 'id' => "{$g}.{$i}"])->all())
        ->filter(fn (array $item): bool => in_array($item['id'], $props['alfakit']['navigationState']['active'], true))
        ->pluck('label')->all();
    expect($active)->toBe(['Settings']);

    $sub = $props['page']['subNavigation'];

    expect($sub['position'])->toBe('start')
        ->and(array_column($sub['groups'][0]['items'], 'label'))->toBe(['General', 'Tags'])
        ->and($sub['groups'][0]['items'][0]['active'])->toBeTrue()
        ->and($sub['groups'][1]['label'])->toBe('Account')
        ->and($sub['groups'][1]['items'][0]['label'])->toBe('Billing')
        ->and($sub['groups'][1]['collapsible'])->toBeFalse();

    expect($props['page']['breadcrumbs'])->toBe([
        ['label' => 'Settings', 'url' => 'http://localhost/nav/settings'],
        ['label' => 'General'],
    ]);
});

it('uses the page sub-navigation position over the cluster default', function (): void {
    $props = inertiaProps(inertiaGet($this, '/nav/settings/billing-settings'));

    expect($props['page']['subNavigation']['position'])->toBe('top');
});

it('gives clustered resource pages the cluster sub-navigation and breadcrumb', function (): void {
    $props = inertiaProps(inertiaGet($this, '/nav/settings/tags'));

    expect($props['page']['subNavigation']['groups'][0]['items'][1])->toMatchArray(['label' => 'Tags', 'active' => true])
        ->and($props['page']['breadcrumbs'][0]['label'])->toBe('Settings');
});

it('hides a cluster whose pages the user cannot access', function (): void {
    Gate::policy(Tag::class, NavDenyAllPolicy::class);

    $props = inertiaProps(inertiaGet($this, '/nav/settings/general-settings'));
    $labels = array_column($props['page']['subNavigation']['groups'][0]['items'], 'label');

    expect($labels)->toBe(['General']);
});

it('generates a cluster class', function (): void {
    $path = app_path('Alfakit/Nav/Clusters/Reports.php');
    @unlink($path);

    $this->artisan('make:alfakit-cluster', ['name' => 'Reports', '--panel' => 'nav'])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('class Reports extends Cluster')
        ->toContain('namespace App\\Alfakit\\Nav\\Clusters;')
        ->toContain("protected static ?string \$navigationLabel = 'Reports';");

    @unlink($path);
});

it('registers the clusters directory on the panel provider once, unless it discovers clusters already', function (): void {
    $provider = app_path('Providers/Alfakit/NavPanelProvider.php');
    $path = app_path('Alfakit/Nav/Clusters/Reports.php');
    expect(is_file($provider))->toBeFalse();
    @mkdir(dirname($provider), 0777, true);
    file_put_contents($provider, "<?php\n\nclass NavPanelProvider\n{\n    public function panel(\$panel)\n    {\n        return \$panel\n            ->id('nav')\n            ->path('nav');\n    }\n}\n");

    try {
        $this->artisan('make:alfakit-cluster', ['name' => 'Reports', '--panel' => 'nav'])
            ->doesntExpectOutputToContain('Register it on the panel')
            ->assertSuccessful();
        $this->artisan('make:alfakit-cluster', ['name' => 'Billing', '--panel' => 'nav'])->assertSuccessful();

        $contents = (string) file_get_contents($provider);

        expect(substr_count($contents, '->discoverClusters('))->toBe(1)
            ->and($contents)->toContain("->discoverClusters(in: app_path('Alfakit/Nav/Clusters'), for: 'App\\\\Alfakit\\\\Nav\\\\Clusters')")
            ->and(shell_exec('php -l '.escapeshellarg($provider)))->toContain('No syntax errors');

        // Clusters discovered elsewhere: leave the provider alone and say what to add.
        file_put_contents($provider, str_replace("app_path('Alfakit/Nav/Clusters'), for: 'App\\\\Alfakit\\\\Nav\\\\Clusters'", "app_path('Other'), for: 'App\\\\Other'", $contents));
        $this->artisan('make:alfakit-cluster', ['name' => 'Audit', '--panel' => 'nav', '--force' => true])
            ->expectsOutputToContain('Register it on the panel')
            ->assertSuccessful();
    } finally {
        @unlink($provider);

        foreach (['Reports', 'Billing', 'Audit'] as $cluster) {
            @unlink(app_path("Alfakit/Nav/Clusters/{$cluster}.php"));
        }
    }
});

// ---------------------------------------------------------------- Record sub-navigation

it('renders record sub-navigation on record pages', function (): void {
    $note = navNote();

    $props = inertiaProps(inertiaGet($this, "/nav/notes/{$note->getKey()}/edit"));
    $sub = $props['page']['subNavigation'];

    expect($sub['position'])->toBe('top')
        ->and($sub['groups'][0]['items'])->toHaveCount(2)
        ->and($sub['groups'][0]['items'][0])->toMatchArray(['label' => 'View', 'url' => "http://localhost/nav/notes/{$note->getKey()}"])
        ->and($sub['groups'][0]['items'][0])->not->toHaveKey('active')
        ->and($sub['groups'][0]['items'][1])->toMatchArray(['label' => 'Edit', 'active' => true])
        ->and($sub['groups'][1]['label'])->toBe('Help');

    expect(inertiaProps(inertiaGet($this, '/nav/notes'))['page'])->not->toHaveKey('subNavigation');
});

it('leaves record pages the user may not open out of the sub-navigation', function (): void {
    $note = navNote();
    Gate::policy(Post::class, NavNoEditPolicy::class);

    $sub = inertiaProps(inertiaGet($this, "/nav/notes/{$note->getKey()}"))['page']['subNavigation'];

    expect(array_column($sub['groups'][0]['items'], 'label'))->toBe(['View']);
});

// ---------------------------------------------------------------- Panel options

it('serialises the layout and navigation options', function (): void {
    $panel = inertiaProps(inertiaGet($this, '/nav/elements'))['alfakit'];

    expect($panel['collapsibleNavigationGroups'])->toBeFalse()
        ->and($panel['sidebarWidth'])->toBe('20rem')
        ->and($panel)->not->toHaveKey('collapsedSidebarWidth')
        ->and($panel['simplePageMaxContentWidth'])->toBe('lg')
        ->and($panel['spaUrlExceptions'])->toBe(['http://localhost/nav/reports', '*/exports/*']);

    $content = collect($panel['navigation'])->firstWhere('label', 'Content');

    expect($content['collapsible'])->toBeFalse()
        ->and($content)->not->toHaveKey('collapsed');
});

it('leaves the default panel options out of the schema', function (): void {
    $panel = alfakit()->getPanel('admin')->toSchema();

    expect($panel)->not->toHaveKeys(['collapsibleNavigationGroups', 'sidebarWidth', 'collapsedSidebarWidth', 'simplePageMaxContentWidth', 'spa', 'spaUrlExceptions']);
});

it('sends error notifications only outside debug mode', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('nav'));
    config()->set('app.debug', true);
    expect(alfakit()->getPanel('nav')->toSchema())->not->toHaveKey('errorNotifications');

    config()->set('app.debug', false);
    $notifications = alfakit()->getPanel('nav')->toSchema()['errorNotifications'];

    expect($notifications['403'])->toBe(['title' => 'Not allowed', 'body' => 'Ask an admin.', 'icon' => 'circle-x'])
        ->and($notifications['default']['title'])->toBe('Something went wrong')
        ->and($notifications['404']['title'])->toBe('Not found');

    alfakit()->getPanel('nav')->errorNotifications(false);
    expect(alfakit()->getPanel('nav')->toSchema())->not->toHaveKey('errorNotifications');
    alfakit()->getPanel('nav')->errorNotifications();
});

it('turns every link into a full page load with spa(false)', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->spa(false);

    expect($panel->toSchema())->toMatchArray(['spa' => false])->not->toHaveKey('spaUrlExceptions');

    $panel->spa();
});

it('denies abilities without a policy method under strictAuthorization()', function (): void {
    Gate::policy(Post::class, NavNoEditPolicy::class);
    $note = navNote();
    $panel = alfakit()->getPanel('nav');
    alfakit()->setCurrentPanel($panel);

    expect(NoteResource::canDelete($note))->toBeTrue();

    $panel->strictAuthorization();

    expect(NoteResource::canDelete($note))->toBeFalse()
        ->and(NoteResource::canView($note))->toBeTrue()
        ->and(AlfaSix\Alfakit\Support\Authorization::allows('delete', $note))->toBeFalse();

    $panel->strictAuthorization(false);
});

// ---------------------------------------------------------------- Layout components

it('serialises the layout components', function (): void {
    $props = inertiaProps(inertiaGet($this, '/nav/elements'));
    $components = collect($props['page']['content'])->firstWhere('type', 'Schema')['components'];

    [$flex, $callout, $empty, $image, $list] = $components;

    expect($flex)->toMatchArray(['type' => 'Flex', 'from' => 'md', 'verticalAlignment' => 'center'])
        ->and($flex)->not->toHaveKey('columns')
        ->and($flex['schema'][0])->not->toHaveKey('grow')
        ->and($flex['schema'][1]['grow'])->toBeFalse()
        ->and($flex['schema'][1]['schema'][0])->toMatchArray(['type' => 'Icon', 'icon' => 'star', 'color' => 'warning']);

    expect($callout)->toMatchArray([
        'type' => 'Callout',
        'heading' => 'Heads up',
        'description' => '&lt;b&gt;Plain&lt;/b&gt; text',
        'color' => 'warning',
        'icon' => 'triangle-alert',
    ])->and(array_column($callout['actions'], 'name'))->toBe(['acknowledge', 'explode', 'explodeWithoutTransaction']);

    expect($empty)->toMatchArray(['type' => 'EmptyState', 'heading' => 'Nothing here', 'icon' => 'inbox', 'contained' => true])
        ->and($empty['actions'][0]['url'])->toBe('/nav/notes');

    expect($image)->toMatchArray(['type' => 'Image', 'url' => '/chart.png', 'alt' => 'Chart', 'imageHeight' => '120px', 'alignment' => 'center']);

    expect($list['type'])->toBe('UnorderedList')
        ->and(array_column($list['schema'], 'content'))->toBe(['First', 'Second']);
});

it('runs callout actions through the action endpoint', function (): void {
    $props = inertiaProps(inertiaGet($this, '/nav/elements'));

    inertiaPost($this, '/nav/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'acknowledge', 'data' => []])->assertRedirect();

    expect(Tag::query()->where('name', 'acknowledged')->exists())->toBeTrue();
});

it('rolls an action back when the panel uses database transactions', function (): void {
    $this->withoutExceptionHandling();
    $token = inertiaProps(inertiaGet($this, '/nav/elements'))['page']['token'];

    expect(fn () => inertiaPost($this, '/nav/_alfakit/actions', ['token' => $token, 'action' => 'explode', 'data' => []]))
        ->toThrow(RuntimeException::class, 'Boom');
    expect(Tag::query()->where('name', 'rolled back')->exists())->toBeFalse();

    expect(fn () => inertiaPost($this, '/nav/_alfakit/actions', ['token' => $token, 'action' => 'explodeWithoutTransaction', 'data' => []]))
        ->toThrow(RuntimeException::class, 'Boom');
    expect(Tag::query()->where('name', 'kept')->exists())->toBeTrue();
});

it('accepts container-query columns', function (): void {
    $grid = AlfaSix\Alfakit\Schemas\Components\Grid::make(['default' => 1, '@md' => 2, '@xl' => 4])
        ->schema([AlfaSix\Alfakit\Schemas\Components\Text::make('A')->columnSpan(['@md' => 2])]);

    $schema = schema([$grid])->toSchema()[0];

    expect($schema['columns'])->toBe(['default' => 1, '@md' => 2, '@xl' => 4])
        ->and($schema['schema'][0]['columnSpan'])->toBe(['@md' => 2]);
});

// ---------------------------------------------------------------- Icon aliases

it('resolves icon aliases on the server', function (): void {
    expect(AlfaSix\Alfakit\Actions\DeleteAction::make()->toSchema()['icon'])->toBe('trash-2');

    Icons::register(['alfakit::actions.delete' => 'trash', 'alfakit::notifications.success' => 'party-popper', 'alfakit::tables.empty-state' => 'folder-open']);

    expect(AlfaSix\Alfakit\Actions\DeleteAction::make()->toSchema()['icon'])->toBe('trash')
        ->and(AlfaSix\Alfakit\Notifications\Notification::make()->success()->toArray()['icon'])->toBe('party-popper')
        ->and(AlfaSix\Alfakit\Schemas\Components\Text::make('x')->icon('alfakit::actions.delete')->toSchema()['icon'])->toBe('trash')
        ->and(AlfaSix\Alfakit\Navigation\NavigationItem::make('Posts')->icon('alfakit::actions.delete')->toSchema()['icon'])->toBe('trash')
        ->and(Icons::get('alfakit::actions.edit'))->toBe('pencil')
        ->and(Icons::resolve('users'))->toBe('users');

    $props = inertiaProps(inertiaGet($this, '/nav/settings/tags'));
    expect(collect($props['page']['content'])->firstWhere('type', 'Table')['emptyState']['icon'])->toBe('folder-open');
});

it('resolves icon aliases of navigation groups and user menu items', function (): void {
    Icons::register(['app::catalog' => 'package', 'alfakit::actions.delete' => 'trash']);

    expect(AlfaSix\Alfakit\Navigation\NavigationGroup::make('Catalog')->icon('app::catalog')->toSchema()['icon'])->toBe('package')
        ->and(AlfaSix\Alfakit\Navigation\NavigationGroup::make('Catalog')->icon(fn (): string => 'app::catalog')->getIcon())->toBe('package')
        ->and(AlfaSix\Alfakit\Navigation\MenuItem::make()->label('Bin')->icon('alfakit::actions.delete')->toSchema()['icon'])->toBe('trash')
        // Plain Lucide names, legacy ones included, reach the renderers unchanged.
        ->and(AlfaSix\Alfakit\Navigation\MenuItem::make()->label('Home')->icon('home')->toSchema()['icon'])->toBe('home')
        ->and(AlfaSix\Alfakit\Navigation\NavigationGroup::make('Bin')->icon('trash-2')->toSchema()['icon'])->toBe('trash-2')
        ->and(AlfaSix\Alfakit\Navigation\MenuItem::make()->label('None')->toSchema())->not->toHaveKey('icon');
});

class NavNoEditPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return false;
    }
}

class NavDenyAllPolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}
