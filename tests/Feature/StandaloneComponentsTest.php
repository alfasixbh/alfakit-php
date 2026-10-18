<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Standalone\StandalonePanel;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\ContactForm;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\PostDetails;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\PostsTable;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\PostStats;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\SecureForm;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\UploadForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

/*
 * Components used outside panels: `Standalone\Form`, `Table`, `Infolist` and `Widgets`
 * rendered as props of the application's own Inertia pages, served by the hidden
 * `standalone` panel's internal endpoints.
 */

beforeEach(function (): void {
    ContactForm::$submitted = [];
    UploadForm::$submitted = [];
    PostsTable::$mounted = [];
    SecureForm::$allow = true;

    // The application's own pages, rendered by the application's own routes.
    Route::middleware('web')->group(function (): void {
        Route::get('/contact', fn () => Inertia::render('Contact', ['form' => ContactForm::make()->fill(['email' => 'hi@example.com'])]));
        Route::get('/authors/{author}/posts', fn (User $author) => Inertia::render('Posts', ['posts' => PostsTable::make(['author' => $author])]));
        Route::get('/secure', fn () => Inertia::render('Secure', ['form' => SecureForm::make()]));
        Route::get('/upload', fn () => Inertia::render('Upload', ['form' => UploadForm::make()]));
        Route::get('/stats', fn () => Inertia::render('Stats', ['stats' => PostStats::make()]));
        Route::get('/posts/{post}', fn (Post $post) => Inertia::render('Post', ['details' => PostDetails::make(['record' => $post])]));
    });
});

function saUser(string $email = 'author@example.com'): User
{
    return User::query()->create(['name' => 'Author', 'email' => $email, 'password' => 'password']);
}

function saPost(User $author, string $title, string $status = 'draft'): Post
{
    return Post::query()->create(['title' => $title, 'slug' => Str::slug($title).uniqid(), 'author_id' => $author->id, 'status' => $status]);
}

// ---------------------------------------------------------------- Payload

it('serialises a form into an Inertia prop with everything the renderer needs', function (): void {
    $props = inertiaProps(inertiaGet($this, '/contact'));
    $form = $props['form'];

    expect($form['alfakit'])->toBe('standalone')
        ->and($form['class'])->toBe(ContactForm::class)
        ->and($form['content'][0]['type'])->toBe('Form')
        ->and($form['content'][0]['state']['email'])->toBe('hi@example.com')
        ->and(array_column($form['content'][0]['actions'], 'name'))->toBe(['submit'])
        ->and($form['content'][0]['actions'][0]['label'])->toBe('Submit')
        ->and($form['endpoints']['action'])->toBe('http://localhost/_alfakit/actions')
        ->and($form['endpoints']['components'])->toBe('http://localhost/_alfakit/standalone/components')
        ->and($form['endpoints'])->not->toHaveKeys(['logout', 'notifications', 'globalSearch'])
        ->and($form['localization']['code'])->toBe('en')
        ->and($form['translations']['save'])->toBe('Save')
        ->and($form['csrf'])->toBeString()
        ->and($form['flashKey'])->toBe(Notification::FLASH_KEY)
        ->and($form['token'])->toBeString();

    $context = SchemaContext::fromToken($form['token']);

    expect($context->panel)->toBe(StandalonePanel::ID)
        ->and($context->page)->toBe(ContactForm::class)
        ->and($context->user)->toBeNull();
});

it('does not list the standalone panel among the panels or register panel routes for it', function (): void {
    expect(array_keys(alfakit()->getPanels()))->not->toContain(StandalonePanel::ID)
        ->and(alfakit()->getDefaultPanel()?->getId())->toBe('admin')
        ->and(alfakit()->getPanel('standalone'))->toBeInstanceOf(StandalonePanel::class)
        ->and(Route::has('alfakit.standalone.internal.actions.execute'))->toBeTrue()
        ->and(Route::has('alfakit.standalone.auth.logout'))->toBeFalse()
        ->and(Route::has('alfakit.standalone.home'))->toBeFalse();
});

// ---------------------------------------------------------------- Guest form

it('lets a guest submit a form, validates it and flashes the notification', function (): void {
    $token = inertiaProps(inertiaGet($this, '/contact'))['form']['token'];

    $this->postJson('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['email' => 'nope', 'message' => 'x']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'message']);

    expect(ContactForm::$submitted)->toBe([]);

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['email' => 'ann@example.com', 'message' => 'Hello there']])
        ->assertRedirect();

    expect(ContactForm::$submitted)->toBe([['email' => 'ann@example.com', 'message' => 'Hello there']]);

    $flashed = Inertia::getFlashed();

    expect($flashed[Notification::FLASH_KEY][0]['title'])->toBe('Thanks!');
});

it('refreshes the form schema and serves the components endpoint from the token', function (): void {
    $token = inertiaProps(inertiaGet($this, '/contact'))['form']['token'];

    $this->postJson('/_alfakit/schema/refresh', ['token' => $token, 'component' => 'form', 'state' => ['email' => 'a@b.c', 'message' => '']])
        ->assertOk()
        ->assertJsonPath('state.email', 'a@b.c');

    $this->getJson('/_alfakit/standalone/components?'.http_build_query(['token' => $token, 'targets' => 'table']))
        ->assertOk()
        ->assertExactJson([]);
});

// ---------------------------------------------------------------- Table with parameters

it('renders a table for its make() parameters and keeps them on internal requests', function (): void {
    $author = saUser();
    $other = saUser('other@example.com');
    saPost($author, 'Bravo');
    saPost($author, 'Alpha');
    saPost($other, 'Charlie');

    $props = inertiaProps(inertiaGet($this, "/authors/{$author->id}/posts?sort=title&direction=desc"));
    $table = $props['posts']['content'][0];

    expect($table['type'])->toBe('Table')
        ->and(array_column($table['records'], 'key'))->toHaveCount(2)
        ->and(array_column(array_column(array_column($table['records'], 'cells'), 'title'), 'state'))->toBe(['Bravo', 'Alpha'])
        ->and(PostsTable::$mounted)->toHaveCount(1)
        ->and(PostsTable::$mounted[0]['author']->is($author))->toBeTrue()
        ->and(SchemaContext::fromToken($props['posts']['token'])->parameters['author'])->toBe(['@model' => User::class, 'key' => $author->id]);

    $searched = inertiaProps(inertiaGet($this, "/authors/{$author->id}/posts?search=alp"))['posts']['content'][0];

    expect(array_column(array_column(array_column($searched['records'], 'cells'), 'title'), 'state'))->toBe(['Alpha']);
});

it('runs record and bulk table actions within the author scope', function (): void {
    $author = saUser();
    $other = saUser('other@example.com');
    $mine = saPost($author, 'Mine');
    $second = saPost($author, 'Second');
    $theirs = saPost($other, 'Theirs');

    $token = inertiaProps(inertiaGet($this, "/authors/{$author->id}/posts"))['posts']['token'];

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'publish', 'record' => $mine->id])->assertRedirect();
    expect($mine->fresh()->status)->toBe('published');

    // Records outside the table's query are not found.
    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'publish', 'record' => $theirs->id])->assertNotFound();

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'archive', 'bulk' => true, 'records' => [$second->id, $theirs->id]])->assertRedirect();
    expect($second->fresh()->status)->toBe('archived')
        ->and($theirs->fresh()->status)->toBe('draft');
});

it('returns 404 when a model from the token no longer exists', function (): void {
    $author = saUser();
    $token = inertiaProps(inertiaGet($this, "/authors/{$author->id}/posts"))['posts']['token'];

    $author->delete();

    $this->getJson('/_alfakit/standalone/components?'.http_build_query(['token' => $token, 'targets' => 'table']))->assertNotFound();
});

// ---------------------------------------------------------------- Authentication and tokens

it('rejects guests on components that require authentication', function (): void {
    $token = inertiaProps(inertiaGet($this, '/secure'))['form']['token'];

    $this->postJson('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['name' => 'x']])->assertStatus(401);
});

it('binds tokens to the user they were issued to', function (): void {
    $ann = saUser('ann@example.com');
    $bob = saUser('bob@example.com');

    $token = inertiaProps(inertiaGet($this->actingAs($ann), '/secure'))['form']['token'];

    expect(SchemaContext::fromToken($token)->user)->toBe((string) $ann->id);

    $this->actingAs($bob)->postJson('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['name' => 'x']])->assertForbidden();
    inertiaPost($this->actingAs($ann), '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['name' => 'x']])->assertRedirect();
});

it('denies the page and internal requests when authorize() fails', function (): void {
    $ann = saUser('ann@example.com');
    $token = inertiaProps(inertiaGet($this->actingAs($ann), '/secure'))['form']['token'];

    SecureForm::$allow = false;

    $this->get('/secure')->assertForbidden();
    $this->postJson('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['name' => 'x']])->assertForbidden();
});

// ---------------------------------------------------------------- Uploads

it('accepts guest uploads and stores them when the form is submitted', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local']);

    $response = inertiaGet($this, '/upload');
    $token = inertiaProps($response)['form']['token'];

    // The browser keeps its session cookie between requests; the test client has to be told.
    $cookie = $response->getCookie((string) config('session.cookie'));
    $this->withCookie((string) config('session.cookie'), (string) $cookie?->getValue());

    $key = $this->post('/_alfakit/uploads', ['file' => UploadedFile::fake()->image('photo.png', 10, 10)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');

    expect($key)->toStartWith('tmp:');

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['attachment' => [$key]]])->assertRedirect();

    $stored = UploadForm::$submitted[0]['attachment'];

    expect($stored)->toStartWith('attachments/')
        ->and(Storage::disk('public')->exists($stored))->toBeTrue();
});

it('requires authentication for uploads when guest uploads are disabled', function (): void {
    config(['alfakit.standalone.guest_uploads' => false]);

    $panel = StandalonePanel::make();
    $router = app('router');
    $panel->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    $this->post('/_alfakit/uploads', ['file' => UploadedFile::fake()->image('photo.png')], ['Accept' => 'application/json'])->assertUnauthorized();
});

// ---------------------------------------------------------------- Infolist, widgets, polling

it('renders an infolist for a record with its actions', function (): void {
    $author = saUser();
    $post = saPost($author, 'Read me');

    $props = inertiaProps(inertiaGet($this, "/posts/{$post->id}"));
    $details = $props['details'];

    expect($details['content'][0]['type'])->toBe('Infolist')
        ->and($details['record'])->toBe($post->id)
        ->and(array_column($details['headerActions'], 'name'))->toBe(['publish']);

    inertiaPost($this, '/_alfakit/actions', ['token' => $details['token'], 'action' => 'publish'])->assertRedirect();
    expect($post->fresh()->status)->toBe('published');
});

it('sends lazy widgets as placeholders and serves them from the components endpoint', function (): void {
    saPost(saUser(), 'One');

    $props = inertiaProps(inertiaGet($this, '/stats'));
    $stats = $props['stats'];
    $widget = $stats['content'][0]['widgets'][0];

    expect($stats['content'][0]['type'])->toBe('Widgets')
        ->and($widget['lazy'])->toBeTrue()
        ->and($stats['lazy'])->toBe(["widget:{$widget['id']}"]);

    $fresh = $this->getJson('/_alfakit/standalone/components?'.http_build_query(['token' => $stats['token'], 'targets' => "widget:{$widget['id']}"]))
        ->assertOk()
        ->json();

    expect($fresh["widget:{$widget['id']}"]['stats'][0]['value'])->toBe(1)
        ->and($fresh["widget:{$widget['id']}"])->not->toHaveKey('lazy');
});
