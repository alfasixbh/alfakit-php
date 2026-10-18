<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\GuestPostPolicy;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\HookedPostResource;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\PrivateCategoryResource;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\PrivatePage;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\PublicPage;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\PublicPostResource;
use AlfaSix\Alfakit\Tests\Fixtures\GuestAccess\PublicProjectResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;
use Illuminate\Support\Facades\Gate;

/*
 * Guest access to panels (`Panel::guestAccess()`, `$isGuestAccessible`) and resource page
 * lifecycle hooks (`beforeFill` … `afterValidate`, trait hooks, `halt()`).
 */

function guestPanel(string $id = 'guests', bool $guestAccess = true): Panel
{
    return Panels::register($id, fn (Panel $panel): Panel => $panel
        ->login()
        ->databaseNotifications()
        ->guestAccess($guestAccess)
        ->resources([PublicPostResource::class, PrivateCategoryResource::class, HookedPostResource::class])
        ->pages([PublicPage::class, PrivatePage::class]));
}

function guestUser(): User
{
    return User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']);
}

function guestPost(array $attributes = []): Post
{
    return Post::query()->create([...['title' => 'Hello', 'slug' => 'hello-'.uniqid(), 'is_published' => true], ...$attributes]);
}

beforeEach(function (): void {
    HookedPostResource::$log = [];
    PublicPage::$runs = [];
    PrivatePage::$runs = [];
});

// ---------------------------------------------------------------- Guest access

it('shows guest-accessible pages to guests, without the user parts of the panel', function (): void {
    Gate::policy(Post::class, GuestPostPolicy::class);
    guestPanel();
    guestPost(['title' => 'Public news']);

    $props = inertiaProps(inertiaGet($this, '/guests/public-posts'));

    expect($props['page']['content'][0]['records'][0]['cells']['title']['state'])->toBe('Public news')
        ->and($props['alfakit'])->not->toHaveKey('user')
        ->and($props['alfakit'])->not->toHaveKey('userMenu')
        ->and($props['alfakit'])->not->toHaveKey('databaseNotifications')
        ->and($props['alfakit'])->not->toHaveKey('globalSearch')
        ->and($props['alfakit']['auth']['login'])->toBe('http://localhost/guests/login');

    // Guests only see what they may open in the navigation.
    $urls = collect($props['alfakit']['navigation'])->flatMap(fn (array $group): array => array_column($group['items'], 'url'))->all();

    expect($urls)->toBe(['http://localhost/guests/status', 'http://localhost/guests/public-posts']);

    inertiaGet($this, '/guests/status')->assertOk();
});

it('redirects guests to the login page for pages that are not guest-accessible', function (): void {
    guestPanel();

    $this->get('/guests/private-categories')->assertRedirect('http://localhost/guests/login');
    $this->get('/guests/members')->assertRedirect('http://localhost/guests/login');
    $this->get('/guests/hooked-posts')->assertRedirect('http://localhost/guests/login');

    // Signed-in users open everything as before.
    $this->actingAs(guestUser());

    inertiaGet($this, '/guests/private-categories')->assertOk();
    $props = inertiaProps(inertiaGet($this, '/guests/members'));

    expect($props['alfakit']['user']['name'])->toBe('Ann')
        ->and($props['alfakit']['userMenu'])->toBeArray();
});

it('keeps guest-accessible pages closed without guestAccess() on the panel', function (): void {
    guestPanel('closed', guestAccess: false);

    $this->get('/closed/public-posts')->assertRedirect('http://localhost/closed/login');
    $this->get('/closed/status')->assertRedirect('http://localhost/closed/login');
    $this->get('/closed')->assertRedirect('http://localhost/closed/login');
});

it('authorises guests through policies that accept a null user', function (): void {
    Gate::policy(Post::class, GuestPostPolicy::class);
    guestPanel();
    $published = guestPost();
    $draft = guestPost(['is_published' => false]);

    inertiaGet($this, "/guests/public-posts/{$published->id}")->assertOk();
    // `view(?User $user, Post $post)` says no to drafts for guests.
    inertiaGet($this, "/guests/public-posts/{$draft->id}")->assertForbidden();
    // `create(User $user)` doesn't accept guests at all: Laravel denies without calling it.
    inertiaGet($this, '/guests/public-posts/create')->assertForbidden();

    $this->actingAs(guestUser());

    inertiaGet($this, "/guests/public-posts/{$draft->id}")->assertOk();
    inertiaGet($this, '/guests/public-posts/create')->assertOk();
});

it('accepts guest tokens for guest-accessible pages only', function (): void {
    guestPanel();

    $token = inertiaProps(inertiaGet($this, '/guests/status'))['page']['token'];

    inertiaPost($this, '/guests/_alfakit/actions', ['token' => $token, 'action' => 'ping'])->assertRedirect();
    expect(PublicPage::$runs)->toBe(['ping']);

    // A guest token naming a page that needs a user (e.g. issued before guest access was
    // removed from it) is refused.
    $forged = (new SchemaContext('guests', PrivatePage::class))->toToken();

    $this->postJson('/guests/_alfakit/actions', ['token' => $forged, 'action' => 'ping'])->assertUnauthorized();

    // A signed-in user's token can't be replayed by a guest.
    $user = guestUser();
    $this->actingAs($user);
    $userToken = inertiaProps(inertiaGet($this, '/guests/members'))['page']['token'];
    auth()->logout();

    $this->postJson('/guests/_alfakit/actions', ['token' => $userToken, 'action' => 'ping'])->assertForbidden();
    expect(PrivatePage::$runs)->toBe([]);

    // Guest endpoints that need a user stay closed.
    $this->getJson('/guests/_alfakit/notifications')->assertUnauthorized();
    $this->getJson('/guests/_alfakit/global-search?search=x')->assertUnauthorized();
});

it('stops a wizard step cleanly when its hook halts', function (): void {
    guestPanel();

    $token = inertiaProps(inertiaGet($this, '/guests/status'))['page']['token'];

    $this->postJson('/guests/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'state' => ['email' => 'full@example.com'], 'step' => 'contact'])
        ->assertOk()
        ->assertJsonPath('halted', true)
        ->assertJsonPath('notifications.0.title', 'Seats are full');

    // The notification was taken out of the flash data: the next page doesn't repeat it.
    expect(session('_flash.new', []))->not->toContain('alfakitNotifications');

    $this->postJson('/guests/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'state' => ['email' => 'ok@example.com'], 'step' => 'contact'])
        ->assertNoContent();

    $this->postJson('/guests/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'state' => ['email' => ''], 'step' => 'contact'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('lets guests see the public pages of the tenants guest access allows', function (): void {
    Panels::register('guestteams', fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->guestAccess(fn (?Team $tenant): bool => $tenant === null || $tenant->slug !== 'secret')
        ->resources([PublicProjectResource::class, ProjectResource::class]));

    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    Team::query()->create(['name' => 'Secret', 'slug' => 'secret']);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    $props = inertiaProps(inertiaGet($this, '/guestteams/alpha/public-projects'));

    expect(array_map(fn (array $record): string => $record['cells']['name']['state'], $props['page']['content'][0]['records']))->toBe(['Alpha project'])
        ->and($props['alfakit']['tenancy']['current']['name'])->toBe('Alpha')
        ->and($props['alfakit']['tenancy']['tenants'] ?? [])->toBe([])
        ->and($props['alfakit']['tenancy']['menu'] ?? null)->toBeNull()
        ->and($props['alfakit']['tenancy']['registrationUrl'] ?? null)->toBeNull();

    $urls = collect($props['alfakit']['navigation'])->flatMap(fn (array $group): array => array_column($group['items'], 'url'))->all();
    expect($urls)->toBe(['http://localhost/guestteams/alpha/public-projects']);

    // Tenants the closure refuses, unknown tenants and pages that need a member.
    $this->get('/guestteams/secret/public-projects')->assertRedirect('http://localhost/guestteams/login');
    $this->get('/guestteams/nope/public-projects')->assertRedirect('http://localhost/guestteams/login');
    $this->get('/guestteams/alpha/projects')->assertRedirect('http://localhost/guestteams/login');

    // The guest token works on the tenant's internal endpoints, for the same tenant only.
    $token = $props['page']['token'];

    $this->getJson('/guestteams/alpha/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'nope']))->assertNotFound();
    $this->getJson('/guestteams/beta/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'nope']))->assertNotFound();
    $this->getJson('/guestteams/secret/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'nope']))->assertUnauthorized();

    // Members still get their own tenants.
    $member = TenantUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'secret']);
    $member->teams()->attach($beta->id);
    $this->actingAs($member);

    $memberProps = inertiaProps(inertiaGet($this, '/guestteams/beta/projects'));
    expect($memberProps['alfakit']['tenancy']['tenants'][0]['name'])->toBe('Beta');
    $this->get('/guestteams/alpha/projects')->assertNotFound();
});

// ---------------------------------------------------------------- Page lifecycle hooks

it('runs fill, validate and create hooks, including trait hooks, in order', function (): void {
    guestPanel();
    $this->actingAs(guestUser());

    $token = inertiaProps(inertiaGet($this, '/guests/hooked-posts/create'))['page']['token'];

    expect(HookedPostResource::$log)->toBe(['beforeFill', 'afterFill', 'trait:afterFill']);
    HookedPostResource::$log = [];

    inertiaPost($this, '/guests/_alfakit/actions', ['token' => $token, 'action' => 'create', 'data' => ['title' => 'New', 'slug' => 'new']])
        ->assertRedirect();

    // Internal requests rebuild the form without the fill hooks.
    expect(HookedPostResource::$log)->toBe([
        'beforeValidate', 'trait:beforeValidate', 'afterValidate',
        'beforeCreate', 'trait:beforeCreate:New', 'afterCreate',
    ])->and(Post::query()->where('slug', 'new')->exists())->toBeTrue();
});

it('skips the after-validate hooks when validation fails', function (): void {
    guestPanel();
    $this->actingAs(guestUser());

    $token = inertiaProps(inertiaGet($this, '/guests/hooked-posts/create'))['page']['token'];
    HookedPostResource::$log = [];

    $this->postJson('/guests/_alfakit/actions', ['token' => $token, 'action' => 'create', 'data' => ['title' => '']])
        ->assertStatus(422);

    expect(HookedPostResource::$log)->toBe(['beforeValidate', 'trait:beforeValidate']);
});

it('halts creating a record from a hook with $this->halt()', function (): void {
    guestPanel();
    $this->actingAs(guestUser());

    $token = inertiaProps(inertiaGet($this, '/guests/hooked-posts/create'))['page']['token'];
    HookedPostResource::$log = [];

    inertiaPost($this, '/guests/_alfakit/actions', ['token' => $token, 'action' => 'create', 'data' => ['title' => 'halt', 'slug' => 'halted']])
        ->assertRedirect()
        ->assertSessionHas('_flash.new');

    expect(HookedPostResource::$log)->toContain('beforeCreate')
        ->not->toContain('afterCreate')
        ->and(Post::query()->where('slug', 'halted')->exists())->toBeFalse();
});

it('runs fill and save hooks on edit pages, and fill hooks on view pages', function (): void {
    guestPanel();
    $this->actingAs(guestUser());
    $post = guestPost(['title' => 'Old']);

    $token = inertiaProps(inertiaGet($this, "/guests/hooked-posts/{$post->id}/edit"))['page']['token'];

    expect(HookedPostResource::$log)->toBe(['beforeFill:Old', 'afterFill:Old', 'trait:afterFill']);
    HookedPostResource::$log = [];

    inertiaPost($this, '/guests/_alfakit/actions', ['token' => $token, 'action' => 'save', 'data' => ['title' => 'New', 'slug' => $post->slug]])
        ->assertRedirect();

    expect(HookedPostResource::$log)->toBe([
        'beforeValidate', 'trait:beforeValidate', 'afterValidate',
        'beforeSave', 'afterSave', 'trait:afterSave:New',
    ]);

    HookedPostResource::$log = [];
    inertiaGet($this, "/guests/hooked-posts/{$post->id}")->assertOk();

    expect(HookedPostResource::$log)->toBe(['view:afterFill', 'trait:afterFill']);
});

it('lets guests only view resources that have no policy', function (): void {
    guestPanel();
    Panels::register('guest-projects', fn (Panel $panel): Panel => $panel->guestAccess()->resources([PublicProjectResource::class]));
    alfakit()->setCurrentPanel(alfakit()->getPanel('guest-projects'));

    expect(Gate::getPolicyFor(Project::class))->toBeNull()
        ->and(PublicProjectResource::canViewAny())->toBeTrue()
        ->and(PublicProjectResource::can('view'))->toBeTrue()
        ->and(PublicProjectResource::canCreate())->toBeFalse()
        ->and(PublicProjectResource::can('update'))->toBeFalse()
        ->and(PublicProjectResource::can('delete'))->toBeFalse();

    $this->actingAs(guestUser());

    expect(PublicProjectResource::canCreate())->toBeTrue();
});
