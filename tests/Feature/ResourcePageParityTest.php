<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\CreateParityPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\CreateWizardPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\EditParityPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\ListParityPosts;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\ViewParityPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityCommentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPostResource;
use Inertia\Support\SessionKey;

beforeEach(function (): void {
    ListParityPosts::$excludeDrafts = true;
    ListParityPosts::$badgeEvaluations = 0;
    ListParityPosts::$defaultActionName = null;
    EditParityPost::$defaultActionName = null;
    EditParityPost::$contentUsing = null;
    ViewParityPost::$contentUsing = null;
    CreateParityPost::$contentUsing = null;
    CreateParityPost::$preserve = [];
    CreateWizardPost::$startStep = 1;
});

afterEach(function (): void {
    ParityPanelProvider::$configureUsing = null;
});

function pageParityUser(): User
{
    return User::query()->create(['name' => 'Pat', 'email' => 'pat'.uniqid().'@example.com', 'password' => 'password']);
}

function pageParityPost(User $user, array $attributes = []): Post
{
    return Post::query()->create([
        'title' => 'Hello',
        'slug' => 'hello-'.uniqid(),
        'author_id' => $user->id,
        'status' => 'draft',
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function parityPage(AlfaSix\Alfakit\Tests\TestCase $test, string $url): array
{
    return inertiaProps(inertiaGet($test, $url))['page'];
}

function parityBlocks(array $page): array
{
    return array_map(static fn (array $block): string => $block['key'], $page['content']);
}

// ---------------------------------------------------------------- Default actions (#7)

it('opens a table record action from tableAction and tableActionRecord', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    $url = ParityPostResource::getUrl('index', ['tableAction' => 'publish', 'tableActionRecord' => $post->getKey()], panel: alfakit()->getPanel('parity'));

    expect($url)->toContain('tableAction=publish')->toContain('tableActionRecord='.$post->getKey());

    $default = parityPage($this, $url)['defaultAction'];

    expect($default['action']['name'])->toBe('publish')
        ->and($default['action']['hasModal'])->toBeTrue()
        ->and($default['component'])->toBe('table')
        ->and((string) $default['record'])->toBe((string) $post->getKey());
});

it('ignores table actions that would run without a modal, unknown actions and records outside the table', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    expect(parityPage($this, "/parity/parity-posts?tableAction=ping&tableActionRecord={$post->id}"))->not->toHaveKey('defaultAction')
        ->and(parityPage($this, "/parity/parity-posts?tableAction=nope&tableActionRecord={$post->id}"))->not->toHaveKey('defaultAction')
        ->and(parityPage($this, '/parity/parity-posts?tableAction=publish&tableActionRecord=999999'))->not->toHaveKey('defaultAction')
        ->and(parityPage($this, '/parity/parity-posts?tableAction=publish&tableActionRecord[]=1'))->not->toHaveKey('defaultAction');
});

it('opens the page default action from the property and from the query string', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    expect(parityPage($this, "/parity/parity-posts/{$post->id}/edit"))->not->toHaveKey('defaultAction');

    EditParityPost::$defaultActionName = 'rename';
    $default = parityPage($this, "/parity/parity-posts/{$post->id}/edit")['defaultAction'];

    expect($default['action']['name'])->toBe('rename')
        ->and($default['component'])->toBe('page')
        ->and($default)->not->toHaveKey('record');

    EditParityPost::$defaultActionName = null;
    $default = parityPage($this, "/parity/parity-posts/{$post->id}/edit?defaultAction=rename&defaultActionArguments[step]=2")['defaultAction'];

    expect($default['action']['name'])->toBe('rename')
        ->and($default['arguments'])->toBe(['step' => '2']);

    // `approve` has no modal: a link must not run it.
    expect(parityPage($this, "/parity/parity-posts/{$post->id}/edit?defaultAction=approve"))->not->toHaveKey('defaultAction');
});

it('accepts Filament\'s ?action= deep-link keys for page actions', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);
    EditParityPost::$defaultActionName = null;

    $default = parityPage($this, "/parity/parity-posts/{$post->id}/edit?action=rename&actionArguments[step]=3")['defaultAction'];

    expect($default['action']['name'])->toBe('rename')
        ->and($default['component'])->toBe('page')
        ->and($default['arguments'])->toBe(['step' => '3']);

    expect(parityPage($this, "/parity/parity-posts/{$post->id}/edit?action=rename&actionComponent=page")['defaultAction']['action']['name'])->toBe('rename')
        // Another component (a relation manager) is not a target: nothing opens.
        ->and(parityPage($this, "/parity/parity-posts/{$post->id}/edit?action=rename&actionComponent=comments"))->not->toHaveKey('defaultAction')
        // AlfaKit's own key wins, and a modal-less action never opens.
        ->and(parityPage($this, "/parity/parity-posts/{$post->id}/edit?defaultAction=rename&action=approve")['defaultAction']['action']['name'])->toBe('rename')
        ->and(parityPage($this, "/parity/parity-posts/{$post->id}/edit?action=approve"))->not->toHaveKey('defaultAction');
});

it('opens a list page default action from a closure-free getter', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs(pageParityUser());

    ListParityPosts::$defaultActionName = 'missing';

    expect(parityPage($this, '/parity/parity-posts'))->not->toHaveKey('defaultAction');
});

// ---------------------------------------------------------------- List tabs (#41)

it('resolves a record outside the active tab when the tab excludes its query', function (bool $exclude, int $status): void {
    ParityPanelProvider::registerPanel();
    ListParityPosts::$excludeDrafts = $exclude;
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user, ['status' => 'published']);
    $token = parityPage($this, '/parity/parity-posts?activeTab=drafts')['token'];

    $response = inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'publish',
        'record' => $post->id,
        'tableQuery' => 'activeTab=drafts',
    ]);

    expect($response->getStatusCode())->toBe($status);
})->with([
    'excluded' => [true, 302],
    'not excluded' => [false, 404],
]);

it('still lists only the tab records when the tab excludes its query from record lookups', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $draft = pageParityPost($user, ['title' => 'Draft']);
    pageParityPost($user, ['title' => 'Live', 'status' => 'published']);

    $table = collect(parityPage($this, '/parity/parity-posts?activeTab=drafts')['content'])->firstWhere('key', 'table');

    expect(array_column($table['records'], 'key'))->toBe([$draft->id]);
});

it('defers tab badges to a deferred prop and serialises tab extra attributes', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    pageParityPost($user, ['status' => 'published']);

    $response = inertiaGet($this, '/parity/parity-posts');
    $table = collect($response->json('props.page.content'))->firstWhere('key', 'table');
    $published = collect($table['tabs'])->firstWhere('key', 'published');

    expect($published['badgeDeferred'])->toBeTrue()
        ->and($published)->not->toHaveKey('badge')
        ->and($published['extraAttributes'])->toBe(['data-tab' => 'published', 'class' => 'font-bold'])
        ->and(ListParityPosts::$badgeEvaluations)->toBe(0)
        ->and($response->json('props.alfakitTabBadges'))->toBeNull()
        ->and($response->json('deferredProps'))->toHaveKey('alfakitTabBadges');

    $badges = $this->get('/parity/parity-posts', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $response->json('component'),
        'X-Inertia-Partial-Data' => 'alfakitTabBadges',
    ])->json('props.alfakitTabBadges');

    expect($badges)->toBe(['published' => ['badge' => 1, 'badgeColor' => 'success']]);
});

it('serialises relation manager badge colours and tooltips, also for lazy managers', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);
    Comment::query()->create(['post_id' => $post->id, 'author_name' => 'Ann', 'body' => 'Hi']);

    $managers = collect(parityPage($this, "/parity/parity-posts/{$post->id}/edit")['content'])->firstWhere('key', 'relationManagers')['managers'];

    expect($managers[0])->toMatchArray(['lazy' => true, 'badge' => '1', 'badgeColor' => 'danger', 'badgeTooltip' => 'Unanswered comments']);
});

// ---------------------------------------------------------------- content() (#9)

it('renders create, edit and view pages as before without content()', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    expect(parityBlocks(parityPage($this, '/parity/parity-posts/create')))->toBe(['form'])
        ->and(parityBlocks(parityPage($this, "/parity/parity-posts/{$post->id}/edit")))->toBe(['form', 'relationManagers'])
        ->and(parityBlocks(parityPage($this, "/parity/parity-posts/{$post->id}")))->toBe(['infolist', 'relationManagers']);
});

it('places content around the page blocks with content markers', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    EditParityPost::$contentUsing = fn (Schema $schema, EditParityPost $page): Schema => $schema->components([
        $page->getRelationManagersContentComponent(),
        Text::make('Between'),
        $page->getFormContentComponent(),
        Callout::make('After'),
        Text::make('More'),
    ]);

    $page = parityPage($this, "/parity/parity-posts/{$post->id}/edit");

    expect(parityBlocks($page))->toBe(['relationManagers', 'content-after', 'form', 'content-after-2'])
        ->and($page['content'][3]['components'])->toHaveCount(2);

    ViewParityPost::$contentUsing = fn (Schema $schema, ViewParityPost $page): Schema => $schema->components([
        Text::make('Summary'),
        $page->getInfolistContentComponent(),
    ]);

    expect(parityBlocks(parityPage($this, "/parity/parity-posts/{$post->id}")))->toBe(['content-before', 'infolist']);

    CreateParityPost::$contentUsing = fn (Schema $schema): Schema => $schema->components([Text::make('Read this first')]);

    expect(parityBlocks(parityPage($this, '/parity/parity-posts/create')))->toBe(['content-before', 'form']);
});

it('leaves out page blocks without a marker once markers are used', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    EditParityPost::$contentUsing = fn (Schema $schema, EditParityPost $page): Schema => $schema->components([
        $page->getFormContentComponent(),
        $page->getFormContentComponent(),
    ]);

    expect(parityBlocks(parityPage($this, "/parity/parity-posts/{$post->id}/edit")))->toBe(['form']);
});

// ---------------------------------------------------------------- Edit/create helpers (#8, #10)

it('flashes refreshed form data after an action changed the record', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);
    $token = parityPage($this, "/parity/parity-posts/{$post->id}/edit")['token'];

    inertiaPost($this, '/parity/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'approve'])->assertRedirect();

    expect(session(SessionKey::FLASH_DATA)['alfakitFormRefresh'] ?? null)->toBe([
        'component' => 'form',
        'state' => ['status' => 'published'],
    ]);
});

it('saves only the fields of one section', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user, ['title' => 'Old', 'status' => 'draft']);
    $token = parityPage($this, "/parity/parity-posts/{$post->id}/edit")['token'];

    // The status field is invalid, but it isn't in the saved section.
    inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'page',
        'action' => 'saveContent',
        'data' => ['title' => 'New title', 'slug' => $post->slug, 'status' => 'nonsense', 'is_published' => true],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($post->refresh()->only(['title', 'status', 'is_published']))->toBe(['title' => 'New title', 'status' => 'draft', 'is_published' => false]);

    inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'page',
        'action' => 'saveContent',
        'data' => ['title' => '', 'slug' => $post->slug, 'status' => 'draft'],
    ])->assertJsonValidationErrors('title');

    expect($post->refresh()->title)->toBe('New title');
});

it('serialises formId on actions that submit the page form', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    $page = parityPage($this, "/parity/parity-posts/{$post->id}/edit");

    expect(collect($page['headerActions'])->firstWhere('name', 'save')['formId'])->toBe('form')
        ->and(collect($page['headerActions'])->firstWhere('name', 'approve'))->not->toHaveKey('formId');
});

it('submits the page form from a header action with formId', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    PageTester::make(EditParityPost::class, ['record' => $post], 'parity')
        ->fillForm(['title' => 'From the header'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($post->refresh()->title)->toBe('From the header');
});

it('keeps the chosen values for "create another"', function (array $preserve, array $expected): void {
    ParityPanelProvider::registerPanel();
    CreateParityPost::$preserve = $preserve;
    $this->actingAs(pageParityUser());
    $token = parityPage($this, '/parity/parity-posts/create')['token'];

    inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'page',
        'action' => 'createAnother',
        'data' => ['title' => 'First', 'slug' => 'first', 'status' => 'review', 'is_published' => true],
    ])->assertRedirect('http://localhost/parity/parity-posts/create');

    $state = collect(parityPage($this, '/parity/parity-posts/create')['content'])->firstWhere('key', 'form')['state'];

    expect(array_intersect_key($state, ['title' => 1, 'status' => 1]))->toBe($expected);

    // Only the next page load gets them.
    $state = collect(parityPage($this, '/parity/parity-posts/create')['content'])->firstWhere('key', 'form')['state'];

    expect($state['status'] ?? null)->toBeNull();
})->with([
    'nothing by default' => [[], ['title' => null, 'status' => null]],
    'chosen fields' => [['status'], ['title' => null, 'status' => 'review']],
]);

it('redirects after create and save as the panel says', function (): void {
    ParityPanelProvider::$configureUsing = fn ($panel) => $panel->resourceCreatePageRedirect('index')->resourceEditPageRedirect('view');
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);

    $token = parityPage($this, '/parity/parity-posts/create')['token'];

    inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'page',
        'action' => 'create',
        'data' => ['title' => 'Created', 'slug' => 'created', 'status' => 'draft'],
    ])->assertRedirect('http://localhost/parity/parity-posts');

    $token = parityPage($this, "/parity/parity-posts/{$post->id}/edit")['token'];

    inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'page',
        'action' => 'save',
        'data' => ['title' => 'Saved', 'slug' => $post->slug, 'status' => 'draft'],
    ])->assertRedirect("http://localhost/parity/parity-posts/{$post->id}");
});

it('keeps the default redirects without panel settings and rejects unknown targets', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs(pageParityUser());
    $token = parityPage($this, '/parity/parity-posts/create')['token'];

    inertiaPost($this, '/parity/_alfakit/actions', [
        'token' => $token,
        'component' => 'page',
        'action' => 'create',
        'data' => ['title' => 'Created', 'slug' => 'created', 'status' => 'draft'],
    ])->assertRedirect('http://localhost/parity/parity-posts/'.Post::query()->where('slug', 'created')->value('id'));

    expect(fn () => alfakit()->getPanel('parity')->resourceEditPageRedirect('somewhere'))->toThrow(InvalidArgumentException::class);
});

it('renders a wizard create page whose form buttons wait for the last step', function (): void {
    ParityPanelProvider::registerPanel();
    CreateWizardPost::$startStep = 2;
    $this->actingAs(pageParityUser());

    $form = collect(parityPage($this, '/parity/wizard-posts/create')['content'])->firstWhere('key', 'form');

    expect($form['wizard'])->toBeTrue()
        ->and($form['components'][0]['type'])->toBe('Wizard')
        ->and($form['components'][0]['startStep'])->toBe(2)
        ->and($form['components'][0]['skippable'])->toBeTrue()
        ->and($form['components'][0]['schema'])->toHaveCount(3)
        ->and(array_column($form['actions'], 'name'))->toBe(['create', 'createAnother', 'cancel']);
});

// ---------------------------------------------------------------- Testing helpers (#32)

it('walks a wizard with the page tester', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs(pageParityUser());

    PageTester::make(CreateWizardPost::class, [], 'parity')
        ->assertWizardCurrentStep(1)
        ->assertWizardStepExists(3)
        ->goToNextWizardStep()
        ->assertHasFormErrors(['title' => 'required'])
        ->assertWizardCurrentStep(1)
        ->fillForm(['title' => 'Wizard post'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(2)
        ->goToWizardStep(3)
        ->assertWizardCurrentStep(2)
        ->assertHasFormErrors(['slug'])
        ->fillForm(['slug' => 'wizard-post'])
        ->goToWizardStep(3)
        ->assertWizardCurrentStep(3)
        ->goToPreviousWizardStep()
        ->assertWizardCurrentStep(2)
        ->goToWizardStep(1)
        ->assertWizardCurrentStep(1)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::query()->where('slug', 'wizard-post')->value('title'))->toBe('Wizard post');
});

it('drives a relation manager table with the page tester', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user);
    $ann = Comment::query()->create(['post_id' => $post->id, 'author_name' => 'Ann', 'body' => 'first']);
    $bob = Comment::query()->create(['post_id' => $post->id, 'author_name' => 'Bob', 'body' => 'second']);
    $other = Comment::query()->create(['post_id' => pageParityPost($user)->id, 'author_name' => 'Cy', 'body' => 'elsewhere']);

    PageTester::make(EditParityPost::class, ['record' => $post], 'parity')
        ->relationManager(ParityCommentsRelationManager::class)
        ->assertCanSeeTableRecords([$ann, $bob], inOrder: true)
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('Bob')
        ->assertCanSeeTableRecords([$bob])
        ->assertCanNotSeeTableRecords([$ann])
        ->assertTableActionVisible('shout', $bob)
        ->callTableAction('shout', $bob)
        ->assertCanSeeTableRecords([$bob])
        ->relationManager(null)
        ->assertSchemaComponentExists('content-section');

    expect($bob->refresh()->body)->toBe('SECOND')
        ->and($ann->refresh()->body)->toBe('first');
});

it('fails clearly for a relation manager the page does not show', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());

    expect(fn () => PageTester::make(EditParityPost::class, ['record' => pageParityPost($user)], 'parity')->relationManager('App\\Missing'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

it('asserts schema components by key and infolist state', function (): void {
    ParityPanelProvider::registerPanel();
    $this->actingAs($user = pageParityUser());
    $post = pageParityPost($user, ['title' => 'Shown', 'status' => 'review']);

    PageTester::make(EditParityPost::class, ['record' => $post], 'parity')
        ->assertSchemaComponentExists('content-section')
        ->assertSchemaComponentExists('status-section', checkComponentUsing: fn (array $component): bool => $component['heading'] === 'Status')
        ->assertSchemaComponentDoesNotExist('nope')
        ->assertSchemaStateSet(['title' => 'Shown']);

    PageTester::make(ViewParityPost::class, ['record' => $post], 'parity')
        ->assertSchemaStateSet(['title' => 'Shown', 'status' => 'review'], 'infolist')
        ->assertSchemaStateSet(fn (array $state): array => ['status' => 'review'], 'infolist');

    expect(fn () => PageTester::make(ViewParityPost::class, ['record' => $post], 'parity')->assertSchemaStateSet(['title' => 'Other'], 'infolist'))
        ->toThrow(PHPUnit\Framework\ExpectationFailedException::class);
});
