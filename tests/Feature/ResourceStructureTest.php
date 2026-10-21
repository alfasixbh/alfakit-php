<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Resources\ParentRecords;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\Pages\EditArticle;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\Pages\ViewArticle;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\RelationManagers\CommentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\RelationManagers\TagsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * F13: list page tabs, nested resources, singular resources, relation groups,
 * ManageRelatedRecords pages and global search result actions.
 */

class ResourceStructureTestPostPolicy
{
    public static bool $view = true;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return self::$view;
    }

    public function update(User $user, Post $post): bool
    {
        return self::$view;
    }
}

beforeEach(function (): void {
    ArPanelProvider::registerPanel();
    ResourceStructureTestPostPolicy::$view = true;
});

function rsUser(): User
{
    return User::query()->create(['name' => 'Rs', 'email' => 'rs'.uniqid().'@example.com', 'password' => 'password']);
}

function rsPost(array $attributes = []): Post
{
    return Post::query()->create(['title' => 'Post '.uniqid(), 'slug' => 'post-'.uniqid(), ...$attributes]);
}

function rsComment(Post $post, string $author = 'Ann'): Comment
{
    return $post->comments()->create(['author_name' => $author, 'body' => 'Hello']);
}

// ---------------------------------------------------------------- List tabs

it('serialises the list tabs with the active one and filters the table', function (): void {
    rsPost(['title' => 'Live', 'is_published' => true]);
    rsPost(['title' => 'Draft']);
    $this->actingAs(rsUser());

    $table = inertiaProps(inertiaGet($this, '/ar/articles'))['page']['content'][0];

    expect($table['activeTab'])->toBe('all')
        ->and($table['tabsQueryKey'])->toBe('activeTab')
        ->and(array_column($table['tabs'], 'key'))->toBe(['all', 'published', 'drafts'])
        ->and($table['tabs'][1])->toMatchArray(['label' => 'Published', 'icon' => 'check', 'badge' => 1, 'badgeColor' => 'success'])
        ->and($table['records'])->toHaveCount(2);

    $table = inertiaProps(inertiaGet($this, '/ar/articles?activeTab=published'))['page']['content'][0];

    expect($table['activeTab'])->toBe('published')
        ->and(array_column($table['records'], 'key'))->toHaveCount(1);
});

it('ignores unknown and hidden tabs in the query string', function (): void {
    rsPost();
    $this->actingAs(rsUser());

    foreach (['secret', 'nope', 'x[]'] as $tab) {
        $table = inertiaProps(inertiaGet($this, '/ar/articles?activeTab='.urlencode($tab)))['page']['content'][0];

        expect($table['activeTab'])->toBe('all')->and($table['records'])->toHaveCount(1);
    }

    $table = inertiaProps(inertiaGet($this, '/ar/articles?activeTab[]=published'))['page']['content'][0];
    expect($table['activeTab'])->toBe('all');
});

// ---------------------------------------------------------------- Nested resources

it('registers nested routes under the parent record', function (): void {
    $post = rsPost();
    $comment = rsComment($post);

    ParentRecords::flush();
    $panel = alfakit()->getPanel('ar');

    expect(ArticleCommentResource::getRoutePrefix())->toBe('articles/{post}/comments')
        ->and(ArticleCommentResource::getRouteBaseName())->toBe('resources.articles.comments')
        ->and(ArticleCommentResource::getUrl('edit', ['record' => $comment], panel: $panel))->toEndWith("/ar/articles/{$post->id}/comments/{$comment->id}/edit")
        ->and(ArticleCommentResource::getUrl('index', ['post' => $post], panel: $panel))->toEndWith("/ar/articles/{$post->id}/comments")
        ->and(ArticleCommentResource::getNavigationItems())->toBe([]);
});

it('links relation manager create and edit actions to the nested resource pages, even with a manager form', function (): void {
    $post = rsPost();
    $comment = rsComment($post);
    $this->actingAs(rsUser());

    $block = alfakit()->testPage(EditArticle::class, ['record' => $post], 'ar')->getContentBlock('relationManagers');
    $manager = collect($block['managers'] ?? $block['items'] ?? [])->first(fn (array $manager): bool => str_contains(json_encode($manager), 'comments'));
    $json = json_encode($manager ?? $block, JSON_UNESCAPED_SLASHES);

    preg_match_all('/"name":"(create|edit)"[^{}]*?"url":"([^"]*)"/', $json, $matches, PREG_SET_ORDER);
    $urls = collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]]);

    expect($urls->get('create'))->toEndWith("/ar/articles/{$post->id}/comments/create")
        ->and($urls->get('edit'))->toEndWith("/ar/articles/{$post->id}/comments/{$comment->id}/edit")
        ->and($json)->not->toContain('"hasModal":true');
});

it('keeps a nested resource manager usable on the parent\'s View page and links its rows', function (): void {
    $post = rsPost();
    $comment = rsComment($post);
    $this->actingAs(rsUser());
    alfakit()->setCurrentPanel(alfakit()->getPanel('ar'));
    ParentRecords::flush();

    $page = app(ViewArticle::class);

    // Linked to the nested resource: not read-only on a View page, rows open the record.
    $manager = CommentsRelationManager::make($post, $page);
    $plain = (new class extends CommentsRelationManager
    {
        protected static ?string $relatedResource = null;
    })::make($post, $page);

    expect($manager->isReadOnly())->toBeFalse()
        ->and($plain->isReadOnly())->toBeTrue();

    $json = json_encode($manager->getTable()->toSchema(), JSON_UNESCAPED_SLASHES);

    expect($json)->toContain("/ar/articles/{$post->id}/comments/create")
        ->and($json)->toContain("/ar/articles/{$post->id}/comments/{$comment->id}/edit");
    expect(collect($manager->getTable()->toSchema()['records'])->firstWhere('key', $comment->id)['url'] ?? null)
        ->toEndWith("/ar/articles/{$post->id}/comments/{$comment->id}/edit");
});

it('scopes nested pages to the parent record', function (): void {
    $post = rsPost(['title' => 'Parent']);
    $other = rsPost();
    $mine = rsComment($post, 'Mine');
    $theirs = rsComment($other, 'Theirs');
    $this->actingAs(rsUser());

    $page = inertiaProps(inertiaGet($this, "/ar/articles/{$post->id}/comments"))['page'];
    $table = $page['content'][0];

    expect(array_column($table['records'], 'key'))->toBe([$mine->id])
        ->and(array_column($page['breadcrumbs'], 'label'))->toBe(['Posts', 'Parent', 'Comments', __('alfakit::panel.resources.list')]);

    inertiaGet($this, "/ar/articles/{$post->id}/comments/{$mine->id}/edit")->assertOk();
    inertiaGet($this, "/ar/articles/{$post->id}/comments/{$theirs->id}/edit")->assertNotFound();
    inertiaGet($this, '/ar/articles/99999/comments')->assertNotFound();
});

it('refuses nested pages of a parent the user may not see', function (): void {
    Gate::policy(Post::class, ResourceStructureTestPostPolicy::class);
    $post = rsPost();
    $comment = rsComment($post);
    $this->actingAs(rsUser());

    inertiaGet($this, "/ar/articles/{$post->id}/comments/{$comment->id}/edit")->assertOk();

    ResourceStructureTestPostPolicy::$view = false;

    inertiaGet($this, "/ar/articles/{$post->id}/comments/{$comment->id}/edit")->assertForbidden();
});

it('creates nested records under the parent and keeps the parent for internal requests', function (): void {
    $post = rsPost();
    $this->actingAs(rsUser());

    $token = inertiaProps(inertiaGet($this, "/ar/articles/{$post->id}/comments/create"))['page']['token'];

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'create', 'data' => ['author_name' => 'Bob', 'body' => 'Hi']])
        ->assertRedirect();

    $comment = Comment::query()->where('author_name', 'Bob')->first();

    expect($comment?->post_id)->toBe($post->id);
});

it('saves a nested record within its parent', function (): void {
    $post = rsPost();
    $comment = rsComment($post);
    $this->actingAs(rsUser());

    $token = inertiaProps(inertiaGet($this, "/ar/articles/{$post->id}/comments/{$comment->id}/edit"))['page']['token'];

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'save', 'data' => ['author_name' => 'Changed', 'body' => 'Hi']])
        ->assertRedirect("http://localhost/ar/articles/{$post->id}/comments/{$comment->id}/edit");

    expect($comment->fresh()->author_name)->toBe('Changed');
});

it('links relation manager actions to the nested resource pages', function (): void {
    $post = rsPost();
    $comment = rsComment($post);
    $this->actingAs(rsUser());

    $content = inertiaProps(inertiaGet($this, "/ar/articles/{$post->id}/edit"))['page']['content'];
    $managers = collect($content)->firstWhere('type', 'RelationManagers');
    $comments = collect($managers['managers'])->firstWhere('component', 'relationManager:'.CommentsRelationManager::class);

    expect($comments['table']['headerActions'][0]['url'])->toEndWith("/ar/articles/{$post->id}/comments/create")
        ->and($comments['table']['records'][0]['actions'][0]['url'] ?? null)->toEndWith("/ar/articles/{$post->id}/comments/{$comment->id}/edit");
});

// ---------------------------------------------------------------- Relation groups

it('groups relation managers under one tab', function (): void {
    $post = rsPost();
    rsComment($post);
    $this->actingAs(rsUser());

    $content = inertiaProps(inertiaGet($this, "/ar/articles/{$post->id}/edit"))['page']['content'];
    $block = collect($content)->firstWhere('type', 'RelationManagers');

    expect(array_column($block['managers'], 'component'))->toBe([
        'relationManager:'.CommentsRelationManager::class,
        'relationManager:'.TagsRelationManager::class,
    ])->and($block['groups'])->toHaveCount(1)
        ->and($block['groups'][0])->toMatchArray([
            'title' => 'Discussion',
            'icon' => 'message-circle',
            'badge' => 1,
            'managers' => ['relationManager:'.CommentsRelationManager::class, 'relationManager:'.TagsRelationManager::class],
        ]);
});

// ---------------------------------------------------------------- ManageRelatedRecords

it('lists, creates and deletes related records on a ManageRelatedRecords page', function (): void {
    $post = rsPost(['title' => 'Owner']);
    $other = rsPost();
    $mine = rsComment($post);
    rsComment($other);
    $this->actingAs(rsUser());

    $page = inertiaProps(inertiaGet($this, "/ar/articles/{$post->id}/discussion"))['page'];
    expect($page['title'])->toBe('Comments')
        ->and(array_column($page['breadcrumbs'], 'label'))->toBe(['Posts', 'Owner', 'Comments'])
        ->and(array_column($page['content'][0]['records'], 'key'))->toBe([$mine->id]);

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $page['token'], 'component' => 'table', 'action' => 'create', 'data' => ['author_name' => 'New', 'body' => 'Body']])
        ->assertRedirect();

    expect($post->comments()->where('author_name', 'New')->exists())->toBeTrue();

    $foreign = $other->comments()->first();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $page['token'], 'component' => 'table', 'action' => 'delete', 'record' => $foreign->id])
        ->assertNotFound();

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $page['token'], 'component' => 'table', 'action' => 'delete', 'record' => $mine->id])
        ->assertRedirect();

    expect(Comment::query()->find($mine->id))->toBeNull();
});

// ---------------------------------------------------------------- Singular resources

it('edits the single record of a singular resource, creating it on the first save', function (): void {
    $this->actingAs(rsUser());

    $page = inertiaProps(inertiaGet($this, '/ar/settings'))['page'];

    expect($page['title'])->toBe('Settings')
        ->and(array_column($page['breadcrumbs'], 'label'))->toBe(['Settings'])
        ->and(collect($page['content'])->firstWhere('type', 'Form'))->not->toBeNull();

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $page['token'], 'action' => 'save', 'data' => ['name' => 'Site', 'slug' => 'site']])
        ->assertRedirect('http://localhost/ar/settings');

    expect(Category::query()->count())->toBe(1);

    $page = inertiaProps(inertiaGet($this, '/ar/settings'))['page'];

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $page['token'], 'action' => 'save', 'data' => ['name' => 'Renamed', 'slug' => 'site']])
        ->assertRedirect();

    expect(Category::query()->count())->toBe(1)
        ->and(Category::query()->first()->name)->toBe('Renamed');
});

it('ignores a record key in the context token of a singular page', function (): void {
    $first = Category::query()->create(['name' => 'First', 'slug' => 'first']);
    $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
    $this->actingAs($user = rsUser());

    $token = (new AlfaSix\Alfakit\Support\SchemaContext('ar', AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\SettingsResource\Pages\ManageSettings::class, ['record' => $other->id], user: (string) $user->id))->toToken();

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'save', 'data' => ['name' => 'Changed', 'slug' => 'first']])
        ->assertRedirect();

    expect($first->fresh()->name)->toBe('Changed')
        ->and($other->fresh()->name)->toBe('Other');
});

// ---------------------------------------------------------------- Global search

it('adds URL actions to global search results', function (): void {
    $post = rsPost(['title' => 'Searchable']);
    $this->actingAs(rsUser());

    $groups = $this->getJson('/ar/_alfakit/global-search?search=Searchable')->assertOk()->json('groups');
    $result = collect($groups)->flatMap(fn (array $group): array => $group['results'])->firstWhere('title', 'Searchable');

    expect($result['actions'])->toHaveCount(1)
        ->and($result['actions'][0]['name'])->toBe('comments')
        ->and($result['actions'][0]['url'])->toEndWith("/ar/articles/{$post->id}/discussion");
});

// ---------------------------------------------------------------- Generators

it('generates a nested resource', function (): void {
    $directory = app_path('Alfakit/Admin/Resources');
    $path = "{$directory}/CommentResource.php";
    @unlink($path);

    $this->artisan('make:alfakit-resource', ['model' => 'Comment', '--nested' => 'PostResource', '--panel' => 'admin', '--force' => true])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('protected static ?string $parentResource = PostResource::class;')
        ->toContain('namespace App\\Alfakit\\Admin\\Resources;');

    app(Illuminate\Filesystem\Filesystem::class)->deleteDirectory("{$directory}/CommentResource");
    @unlink($path);
});

it('generates a singular resource', function (): void {
    $directory = app_path('Alfakit/Admin/Resources');
    @unlink("{$directory}/SettingResource.php");

    $this->artisan('make:alfakit-resource', ['model' => 'Setting', '--singular' => true, '--panel' => 'admin', '--force' => true])->assertSuccessful();

    expect(file_get_contents("{$directory}/SettingResource.php"))
        ->toContain("'index' => Pages\\ManageSetting::route('/'),")
        ->toContain("protected static ?string \$navigationLabel = 'Settings';")
        ->and(file_get_contents("{$directory}/SettingResource/Pages/ManageSetting.php"))
        ->toContain('class ManageSetting extends ManageSingularRecord');

    app(Illuminate\Filesystem\Filesystem::class)->deleteDirectory("{$directory}/SettingResource");
    @unlink("{$directory}/SettingResource.php");
});

it('writes generated resources with sorted imports, the way Pint expects them', function (): void {
    $directory = app_path('Alfakit/Admin/Resources');
    @unlink("{$directory}/InvoiceResource.php");

    $this->artisan('make:alfakit-resource', ['model' => 'Invoice', '--view' => true, '--panel' => 'admin', '--force' => true])->assertSuccessful();

    foreach (["{$directory}/InvoiceResource.php", "{$directory}/InvoiceResource/Pages/EditInvoice.php", "{$directory}/InvoiceResource/Pages/ListInvoices.php"] as $file) {
        preg_match_all('/^use ([^;]+);$/m', (string) file_get_contents($file), $matches);
        $sorted = $matches[1];
        usort($sorted, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        expect($matches[1])->not->toBe([])->toBe($sorted);
    }

    app(Illuminate\Filesystem\Filesystem::class)->deleteDirectory("{$directory}/InvoiceResource");
    @unlink("{$directory}/InvoiceResource.php");
});

it('tells you how to create a model that does not exist yet', function (): void {
    $directory = app_path('Alfakit/Admin/Resources');

    $this->artisan('make:alfakit-resource', ['model' => 'Gizmo', '--generate' => true, '--panel' => 'admin', '--force' => true])
        ->expectsOutputToContain('Model App\\Models\\Gizmo not found. Create it first (php artisan make:model Gizmo --migration), or pass --model --migration')
        ->assertSuccessful();

    app(Illuminate\Filesystem\Filesystem::class)->deleteDirectory("{$directory}/GizmoResource");
    @unlink("{$directory}/GizmoResource.php");
});

it('does not warn about a missing model it has just created with --model', function (): void {
    $directory = app_path('Alfakit/Admin/Resources');

    $this->artisan('make:alfakit-resource', ['model' => 'Doohickey', '--model' => true, '--panel' => 'admin', '--force' => true])
        ->doesntExpectOutputToContain('not found')
        ->assertSuccessful();

    expect(class_exists('App\\Models\\Doohickey', false))->toBeTrue();

    app(Illuminate\Filesystem\Filesystem::class)->deleteDirectory("{$directory}/DoohickeyResource");
    @unlink("{$directory}/DoohickeyResource.php");
    @unlink(app_path('Models/Doohickey.php'));
});
