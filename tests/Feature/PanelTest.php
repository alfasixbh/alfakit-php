<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

it('registers panel routes', function (): void {
    expect(route('alfakit.admin.auth.login'))->toBe('http://localhost/admin/login')
        ->and(route('alfakit.admin.resources.posts.index'))->toBe('http://localhost/admin/posts')
        ->and(route('alfakit.admin.resources.posts.edit', ['record' => 5]))->toBe('http://localhost/admin/posts/5/edit')
        ->and(route('alfakit.admin.pages.dashboard'))->toBe('http://localhost/admin')
        ->and(route('alfakit.admin.internal.actions.execute'))->toBe('http://localhost/admin/_alfakit/actions');
});

it('redirects guests to the login page', function (): void {
    $this->get('/admin/posts')->assertRedirect('http://localhost/admin/login');
});

it('renders the login page as a schema-driven auth page', function (): void {
    $props = inertiaProps(inertiaGet($this, '/admin/login'));

    expect($props['page']['layout'])->toBe('auth')
        ->and($props['page']['content'][0]['type'])->toBe('Form')
        ->and(collect($props['page']['content'][0]['components'][0]['schema'] ?? $props['page']['content'][0]['components'])->pluck('name')->all())->toBe(['email', 'password', 'remember'])
        ->and($props['page']['content'][0]['actions'][0]['name'])->toBe('authenticate')
        ->and($props['alfakit']['brandName'])->toBe('Alfakit Test')
        ->and($props['alfakit'])->not->toHaveKey('user');
});

it('authenticates through the login action and shares panel data', function (): void {
    $user = admin();
    $props = inertiaProps(inertiaGet($this, '/admin/login'));

    $response = inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'authenticate',
        'data' => ['email' => 'admin@example.com', 'password' => 'password', 'remember' => false],
    ]);

    $response->assertRedirect('http://localhost/admin');
    $this->assertAuthenticatedAs($user);

    $dashboard = inertiaProps(inertiaGet($this, '/admin'));

    expect($dashboard['alfakit']['user']['name'])->toBe('Admin')
        ->and($dashboard['alfakit']['navigation'][0]['items'][0]['label'])->toBe('Dashboard')
        ->and($dashboard['alfakit']['navigation'][1]['label'])->toBe('Content')
        ->and($dashboard['alfakit']['navigation'][1]['items'][0]['url'])->toBe('http://localhost/admin/posts')
        ->and($dashboard['alfakit']['userMenu']['items'][0]['label'])->toBe('Docs')
        ->and($dashboard['alfakit']['endpoints']['action'])->toBe('http://localhost/admin/_alfakit/actions')
        ->and($dashboard['alfakit']['databaseNotifications']['unreadCount'])->toBe(0)
        ->and($dashboard['page']['content'][0]['type'])->toBe('Widgets')
        // Widgets are lazy by default: a placeholder now, the stats in the deferred prop.
        ->and($dashboard['page']['content'][0]['widgets'][0]['lazy'])->toBeTrue();
});

it('rejects wrong credentials with a validation error', function (): void {
    admin();
    $props = inertiaProps(inertiaGet($this, '/admin/login'));

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'authenticate',
        'data' => ['email' => 'admin@example.com', 'password' => 'nope'],
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);

    $this->assertGuest();
});

it('renders the list page with a table and header actions', function (): void {
    $user = admin();
    Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts?search=hel&sort=title&direction=desc'));
    $table = $props['page']['content'][0];

    expect($props['page']['title'])->toBe('Posts')
        ->and($props['page']['breadcrumbs'])->toBe([['label' => 'Posts', 'url' => 'http://localhost/admin/posts'], ['label' => 'List']])
        ->and($props['page']['headerActions'][0])->toMatchArray(['name' => 'create', 'label' => 'New post', 'url' => 'http://localhost/admin/posts/create'])
        ->and($table['type'])->toBe('Table')
        ->and($table['component'])->toBe('table')
        ->and($table['records'][0]['cells']['title']['state'])->toBe('Hello')
        ->and($table['records'][0]['cells']['author.name']['state'])->toBe('Admin')
        ->and($table['records'][0]['url'])->toBe('http://localhost/admin/posts/1/edit')
        ->and(array_column($table['records'][0]['actions'], 'name'))->toBe(['edit', 'delete'])
        ->and($table['sort'])->toBe(['column' => 'title', 'direction' => 'desc'])
        ->and($table['search'])->toBe('hel')
        ->and($table['filters'][0]['name'])->toBe('is_published')
        ->and($table['bulkActions'][0]['name'])->toBe('delete');
});

it('creates a record through the create page action with relationships', function (): void {
    $user = admin();
    $tag = Tag::query()->create(['name' => 'php']);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts/create'));
    $form = $props['page']['content'][0];

    expect($form['type'])->toBe('Form')
        ->and($form['operation'])->toBe('create')
        ->and(array_column($form['actions'], 'name'))->toBe(['create', 'createAnother', 'cancel'])
        ->and($form['state'])->toMatchArray(['title' => null, 'slug' => null, 'is_published' => false, 'tags' => [], 'comments' => []]);

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'create',
        'data' => ['title' => 'My Post', 'slug' => 'my-post', 'author_id' => $user->id, 'tags' => [$tag->id], 'is_published' => true, 'comments' => [['author_name' => 'Ann', 'body' => 'Nice']]],
    ])->assertRedirect('http://localhost/admin/posts/1/edit');

    $post = Post::query()->first();

    expect($post->title)->toBe('My Post')
        ->and($post->is_published)->toBeTrue()
        ->and($post->tags->pluck('name')->all())->toBe(['php'])
        ->and($post->comments->pluck('body')->all())->toBe(['Nice'])
        ->and(Notification::getFlashed()[0]['title'])->toBe('Created');
});

it('saves relationships when using create & create another', function (): void {
    $user = admin();
    $tag = Tag::query()->create(['name' => 'php']);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts/create'));

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'createAnother',
        'data' => ['title' => 'Another', 'slug' => 'another', 'author_id' => $user->id, 'tags' => [$tag->id], 'is_published' => false, 'comments' => [['author_name' => 'Ann', 'body' => 'Nice']]],
    ])->assertRedirect('http://localhost/admin/posts/create');

    $post = Post::query()->firstOrFail();

    expect($post->tags->pluck('name')->all())->toBe(['php'])
        ->and($post->comments->pluck('body')->all())->toBe(['Nice']);
});

it('returns validation errors for invalid create data', function (): void {
    $user = admin();
    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts/create'));

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'create',
        'data' => ['title' => '', 'slug' => 'x', 'author_id' => 999],
    ])->assertStatus(422)->assertJsonValidationErrors(['title', 'author_id']);
});

it('edits a record and runs the delete header action', function (): void {
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts/1/edit'));
    $form = $props['page']['content'][0];

    expect($props['page']['title'])->toBe('Edit Old')
        ->and($props['page']['record'])->toBe(1)
        ->and($form['state']['title'])->toBe('Old')
        ->and($form['components'][0]['schema'][2]['selectedLabels'])->toBe(['1' => 'Admin'])
        ->and($props['page']['headerActions'][0]['name'])->toBe('delete');

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'save',
        'data' => ['title' => 'New', 'slug' => 'new', 'author_id' => $user->id, 'tags' => [], 'is_published' => false, 'comments' => []],
    ])->assertRedirect('http://localhost/admin/posts/1/edit');

    expect($post->fresh()->title)->toBe('New');

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'delete'])
        ->assertRedirect('http://localhost/admin/posts');

    expect($post->fresh()->trashed())->toBeTrue();
});

it('serves table action modals and executes record and bulk actions', function (): void {
    $user = admin();
    $a = Post::query()->create(['title' => 'A', 'slug' => 'a', 'author_id' => $user->id]);
    $b = Post::query()->create(['title' => 'B', 'slug' => 'b', 'author_id' => $user->id]);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'));
    $token = $props['page']['token'];

    $this->getJson('/admin/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'component' => 'table', 'action' => 'delete', 'record' => $a->id]))
        ->assertOk()
        ->assertJsonPath('heading', 'Delete A')
        ->assertJsonPath('requiresConfirmation', true);

    $this->getJson('/admin/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'component' => 'table', 'action' => 'edit', 'record' => $a->id]))
        ->assertOk()
        ->assertJsonPath('form', null);

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'delete', 'record' => $a->id])
        ->assertRedirect();

    expect($a->fresh()->trashed())->toBeTrue();

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'delete', 'bulk' => true, 'records' => [$b->id]])
        ->assertRedirect();

    expect($b->fresh()->trashed())->toBeTrue();
});

it('refreshes a live form and serves select options', function (): void {
    $user = admin();
    User::query()->create(['name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'x']);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts/create'));
    $token = $props['page']['token'];

    $this->postJson('/admin/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'form',
        'path' => 'title',
        'state' => ['title' => 'Hello World', 'slug' => null, 'comments' => []],
    ])->assertOk()->assertJsonPath('state.slug', 'hello-world');

    $this->getJson('/admin/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'path' => 'author_id', 'search' => 'ze']))
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Zed');

    $this->getJson('/admin/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'path' => 'author_id', 'values' => [$user->id]]))
        ->assertOk()
        ->assertJsonPath('labels.'.$user->id, 'Admin');
});

it('updates inline table cells and lists database notifications', function (): void {
    $user = admin();
    $post = Post::query()->create(['title' => 'A', 'slug' => 'a', 'author_id' => $user->id]);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'));

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $props['page']['token'], 'component' => 'table', 'record' => $post->id, 'column' => 'is_published', 'state' => true])
        ->assertOk()->assertJsonPath('state', true);

    expect($post->fresh()->is_published)->toBeTrue();

    Notification::make()->title('Hi there')->body('Welcome')->sendToDatabase($user);

    $this->getJson('/admin/_alfakit/notifications')->assertOk()->assertJsonPath('notifications.0.title', 'Hi there')->assertJsonPath('unreadCount', 1);
    $this->postJson('/admin/_alfakit/notifications/read-all')->assertOk()->assertJsonPath('unreadCount', 0);
});

it('rejects forged context tokens and other panels', function (): void {
    $user = admin();

    $this->actingAs($user)->postJson('/admin/_alfakit/actions', ['token' => 'nonsense', 'action' => 'x'])->assertStatus(400);
});

it('performs global search across resources', function (): void {
    $user = admin();
    Post::query()->create(['title' => 'Searchable thing', 'slug' => 'st', 'author_id' => $user->id]);

    $this->actingAs($user)->getJson('/admin/_alfakit/global-search?search=searchable')
        ->assertOk()
        ->assertJsonPath('groups.0.label', 'Posts')
        ->assertJsonPath('groups.0.results.0.title', 'Searchable thing')
        ->assertJsonPath('groups.0.results.0.url', 'http://localhost/admin/posts/1/edit');
});

it('logs out', function (): void {
    $user = admin();

    $this->actingAs($user)->post('/admin/logout')->assertRedirect('http://localhost/admin/login');
    $this->assertGuest();
});

it('treats actions that resolve to a page as links that are never executed', function (): void {
    $user = admin();
    Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'));
    $create = $props['page']['headerActions'][0];
    $edit = $props['page']['content'][0]['records'][0]['actions'][0];

    expect($create)->toMatchArray(['name' => 'create', 'url' => 'http://localhost/admin/posts/create'])
        ->and($create)->not->toHaveKeys(['hasAction', 'hasModal'])
        ->and($edit)->toMatchArray(['name' => 'edit', 'url' => 'http://localhost/admin/posts/1/edit'])
        ->and($edit)->not->toHaveKeys(['hasAction', 'hasModal']);

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'create',
        'data' => [],
    ])->assertStatus(400);

    expect(Post::query()->count())->toBe(1);
});

it('credits Alfa Six in the footer unless the panel removes it', function (): void {
    $this->actingAs(admin());
    $panel = alfakit()->getPanel('admin');

    $footer = $panel->getSlotsSchema()['footer'] ?? [];

    expect($footer)->toHaveCount(1)
        ->and($footer[0]['type'])->toBe('html')
        ->and($footer[0]['html'])->toContain('data-slot="credit"')
        ->toContain('href="https://alfasixbh.com"')
        ->toContain('Developed by <strong>Alfa Six</strong>')
        ->toContain('<svg')
        ->toContain('aria-hidden="true"');

    $panel->credit(false);
    expect($panel->getSlotsSchema())->not->toHaveKey('footer');

    $panel->credit()->creditUrl('https://example.com');
    expect($panel->getSlotsSchema()['footer'][0]['html'])->toContain('href="https://example.com"');
    $panel->creditUrl(null);
});
