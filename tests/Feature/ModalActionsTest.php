<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ActionsPage;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/*
 * F12: wizard steps in action modals, nested modal actions, rate limits, password
 * confirmation, per-record authorisation and chunking of bulk actions, and the
 * detach / dissociate bulk actions.
 */

class ModalActionsTestPostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Post $post): bool
    {
        return $post->status !== 'locked';
    }
}

beforeEach(function (): void {
    ArPanelProvider::registerPanel();
    ActionsPage::$runs = [];
    ActionsPage::$hideParent = false;
    ArticleResource::$touched = [];
    ArticleResource::$sawLazyCollection = false;
});

function arUser(): User
{
    return User::query()->create(['name' => 'Ar', 'email' => 'ar'.uniqid().'@example.com', 'password' => 'password']);
}

function arToken(mixed $test, string $url): string
{
    return inertiaProps(inertiaGet($test, $url))['page']['token'];
}

function arPost(array $attributes = []): Post
{
    static $i = 0;
    $i++;

    return Post::query()->create(['title' => "Post {$i}", 'slug' => "post-{$i}-".uniqid(), ...$attributes]);
}

function arFlash(): array
{
    return Inertia::getFlashed();
}

// ---------------------------------------------------------------- Wizard

it('turns ->steps() into a wizard form and shows submit on the last step only', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    $modal = $this->getJson('/ar/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'onboard']))
        ->assertOk()
        ->json();

    expect($modal['wizard'])->toBeTrue()
        ->and($modal['form']['components'][0]['type'])->toBe('Wizard')
        ->and($modal['form']['components'][0]['skippable'])->toBeTrue()
        ->and(array_column($modal['form']['components'][0]['schema'], 'label'))->toBe(['Account', 'Profile']);
});

it('validates every wizard step on submit', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'onboard', 'data' => ['name' => 'Ann']])
        ->assertStatus(422);

    expect(ActionsPage::$runs)->toBe([]);

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'onboard', 'data' => ['name' => 'Ann', 'bio' => 'Hi']])
        ->assertRedirect();

    expect(ActionsPage::$runs)->toBe([['onboard' => ['name' => 'Ann', 'bio' => 'Hi']]]);
});

// ---------------------------------------------------------------- Nested modal actions

it('serialises footer actions and opens a nested action modal by its path', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    $parent = $this->getJson('/ar/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'parent']))->assertOk()->json();
    expect(array_column($parent['extraFooterActions'], 'name'))->toBe(['child'])
        ->and($parent['extraFooterActions'][0]['hasModal'])->toBeTrue();

    $child = $this->getJson('/ar/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'parent>child']))->assertOk()->json();
    expect($child['name'])->toBe('child')
        ->and(array_column($child['extraFooterActions'], 'name'))->toBe(['grandchild']);
});

it('runs nested, deeply nested and registered modal actions', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'parent>child'])->assertRedirect();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'parent>child>grandchild'])->assertRedirect();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'parent>registered'])->assertRedirect();

    expect(ActionsPage::$runs)->toBe([['child' => true], ['grandchild' => true], ['registered' => true]]);
});

it('refuses nested actions of a hidden parent and unknown paths', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');
    ActionsPage::$hideParent = true;

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'parent>child'])->assertForbidden();
    $this->getJson('/ar/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'parent>child']))->assertForbidden();

    ActionsPage::$hideParent = false;
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'parent>nope'])->assertNotFound();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'child'])->assertNotFound();

    expect(ActionsPage::$runs)->toBe([]);
});

it('gives nested table actions the parent record and flashes cancelParentActions', function (): void {
    $post = arPost();
    $token = arToken($this->actingAs(arUser()), '/ar/articles');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'edit>preview', 'record' => $post->id, 'data' => ['note' => 'hi']])
        ->assertRedirect();

    expect(collect(arFlash()[Notification::FLASH_KEY] ?? [])->pluck('title')->all())->toContain("preview {$post->id} hi");

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'edit>publish', 'record' => $post->id])
        ->assertRedirect();

    expect($post->fresh()->is_published)->toBeTrue()
        ->and(arFlash()['alfakitAction']['cancelParentActions'])->toBeTrue();
});

it('validates the form of a nested action', function (): void {
    $post = arPost();
    $token = arToken($this->actingAs(arUser()), '/ar/articles');

    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'edit>preview', 'record' => $post->id, 'data' => []])
        ->assertStatus(422);
});

it('shares the selection of a bulk action with its nested actions', function (): void {
    $posts = [arPost(), arPost(), arPost()];
    $token = arToken($this->actingAs(arUser()), '/ar/articles');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'mark>count', 'records' => [$posts[0]->id, $posts[1]->id]])
        ->assertRedirect();

    expect(collect(arFlash()[Notification::FLASH_KEY] ?? [])->pluck('title')->all())->toContain('count 2');
});

it('builds nested action paths', function (): void {
    $parent = Action::make('parent')->extraModalFooterActions([Action::make('child')->extraModalFooterActions([Action::make('leaf')])]);

    $leaf = $parent->getNestedModalAction(['child', 'leaf']);

    expect($leaf?->getActionPath())->toBe('parent>child>leaf')
        ->and($leaf?->getRootAction())->toBe($parent)
        ->and($parent->getNestedModalAction(['missing']))->toBeNull();
});

// ---------------------------------------------------------------- Rate limiting

it('rate limits an action per user and notifies the user', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limited'])->assertRedirect();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limited'])->assertRedirect();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limited'])->assertRedirect();

    expect(ActionsPage::$runs)->toHaveCount(2)
        ->and(arFlash()['alfakitAction']['status'])->toBe('halted')
        ->and(collect(arFlash()[Notification::FLASH_KEY])->pluck('title')->all())->toContain(__('alfakit::actions.rate_limited.title'));

    // Another user has their own budget.
    $token = arToken($this->actingAs(arUser()), '/ar/actions');
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limited'])->assertRedirect();

    expect(ActionsPage::$runs)->toHaveCount(3);
});

it('does not count submissions that fail validation against the rate limit', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    // Two typos: validation errors, no run, no attempt used.
    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'limitedForm', 'data' => ['email' => 'not-an-email']])->assertUnprocessable();
    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'limitedForm', 'data' => ['email' => '']])->assertUnprocessable();

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limitedForm', 'data' => ['email' => 'a@example.com']])->assertRedirect();
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limitedForm', 'data' => ['email' => 'b@example.com']])->assertRedirect();

    expect(ActionsPage::$runs)->toBe([['limitedForm' => 'a@example.com'], ['limitedForm' => 'b@example.com']]);

    // The limit still applies to valid runs, and is checked before validation.
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'limitedForm', 'data' => ['email' => '']])->assertRedirect();

    expect(ActionsPage::$runs)->toHaveCount(2)
        ->and(arFlash()['alfakitAction']['status'])->toBe('halted');
});

it('translates the rate limit notification', function (): void {
    $notification = Action::make('x')->getRateLimitedNotification(30)->toArray();

    expect($notification['title'])->toBe('Too many attempts')
        ->and($notification['body'])->toContain('30 seconds');

    expect(Action::make('x')->getRateLimitedNotification(1)->toArray()['body'])->toBe('Please wait 1 second before trying again.')
        ->and(Action::make('x')->rateLimitedNotificationTitle(fn (int $minutes): string => "Wait {$minutes} min")->getRateLimitedNotification(90)->toArray()['title'])->toBe('Wait 2 min');
});

// ---------------------------------------------------------------- Password confirmation

it('asks for the current password before running a sensitive action', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    $modal = $this->getJson('/ar/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'sensitive']))->assertOk()->json();
    expect($modal['passwordConfirmation'])->toBeTrue();

    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(Action::PASSWORD_CONFIRMATION_INPUT);

    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive', 'confirmPassword' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonValidationErrors([Action::PASSWORD_CONFIRMATION_INPUT => __('alfakit::actions.password_confirmation.incorrect')]);

    expect(ActionsPage::$runs)->toBe([]);

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive', 'confirmPassword' => 'password'])->assertRedirect();
    expect(ActionsPage::$runs)->toBe([['sensitive' => true]])
        ->and(session(Action::PASSWORD_CONFIRMED_AT_SESSION_KEY))->toBeInt();

    // Remembered: no password needed within the timeout.
    $modal = $this->getJson('/ar/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'sensitive']))->assertOk()->json();
    expect($modal)->not->toHaveKey('passwordConfirmation');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive'])->assertRedirect();
    expect(ActionsPage::$runs)->toHaveCount(2);
});

it('asks again after the timeout or every time with rememberForMinutes: 0', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    $this->withSession([Action::PASSWORD_CONFIRMED_AT_SESSION_KEY => time() - 20000]);
    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive'])->assertStatus(422);

    $this->withSession([Action::PASSWORD_CONFIRMED_AT_SESSION_KEY => time()]);
    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'alwaysAsk'])->assertStatus(422);
    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'action' => 'alwaysAsk', 'confirmPassword' => 'password'])->assertRedirect();

    expect(ActionsPage::$runs)->toBe([['alwaysAsk' => true]]);
});

it('throttles wrong passwords', function (): void {
    $token = arToken($this->actingAs(arUser()), '/ar/actions');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive', 'confirmPassword' => 'wrong'])->assertStatus(422);
    }

    $this->postJson('/ar/_alfakit/actions', ['token' => $token, 'action' => 'sensitive', 'confirmPassword' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(Action::PASSWORD_CONFIRMATION_INPUT);

    expect(ActionsPage::$runs)->toBe([]);
});

it('opens a modal for password-confirmed actions', function (): void {
    expect(Action::make('x')->requiresPasswordConfirmation()->action(fn () => null)->toSchema()['hasModal'])->toBeTrue();
});

// ---------------------------------------------------------------- Bulk actions

it('skips the records the user may not delete and tells them', function (): void {
    Gate::policy(Post::class, ModalActionsTestPostPolicy::class);
    $open = arPost();
    $locked = arPost(['status' => 'locked']);
    $token = arToken($this->actingAs(arUser()), '/ar/articles');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'delete', 'records' => [$open->id, $locked->id]])
        ->assertRedirect();

    expect(Post::withTrashed()->find($open->id)->trashed())->toBeTrue()
        ->and(Post::query()->find($locked->id))->not->toBeNull()
        ->and(collect(arFlash()[Notification::FLASH_KEY])->pluck('title')->all())
        ->toContain(trans_choice('alfakit::actions.bulk.unauthorized_records', 1, ['count' => 1]));
});

it('cancels when every selected record is refused', function (): void {
    $post = arPost(['rating' => 0]);
    $token = arToken($this->actingAs(arUser()), '/ar/articles');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'mark', 'records' => [$post->id]])
        ->assertRedirect();

    expect($post->fresh()->status)->toBe('draft')
        ->and(arFlash()['alfakitAction']['status'])->toBe('cancelled');
});

it('processes chunked selections lazily without skipping records it deletes', function (): void {
    $posts = collect(range(1, 5))->map(fn () => arPost());
    $token = arToken($this->actingAs(arUser()), '/ar/articles');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'touch', 'records' => [], 'allRecords' => 1])
        ->assertRedirect();

    expect(ArticleResource::$sawLazyCollection)->toBeTrue()
        ->and(ArticleResource::$touched)->toEqualCanonicalizing($posts->pluck('id')->all())
        ->and(Post::query()->count())->toBe(0);
});

it('applies the list tab of the page to "all records" selections', function (): void {
    $published = arPost(['is_published' => true]);
    $draft = arPost(['is_published' => false]);
    $token = arToken($this->actingAs(arUser()), '/ar/articles?activeTab=published');

    inertiaPost($this, '/ar/_alfakit/actions?activeTab=published', ['token' => $token, 'component' => 'table', 'action' => 'touch', 'records' => [], 'allRecords' => 1])
        ->assertRedirect();

    expect(ArticleResource::$touched)->toBe([$published->id])
        ->and(Post::query()->find($draft->id))->not->toBeNull();
});

it('only acts on selected keys inside the table query', function (): void {
    $post = arPost();
    $token = arToken($this->actingAs(arUser()), '/ar/articles?activeTab=drafts');

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'touch', 'records' => [$post->id, 99999, ['nested']]])
        ->assertRedirect();

    expect(ArticleResource::$touched)->toBe([$post->id]);
});

it('detaches the selected tags of the owner record only', function (): void {
    $post = arPost();
    $other = arPost();
    [$a, $b] = [Tag::query()->create(['name' => 'A']), Tag::query()->create(['name' => 'B'])];
    $post->tags()->attach([$a->id, $b->id]);
    $other->tags()->attach([$a->id]);

    $token = arToken($this->actingAs(arUser()), "/ar/articles/{$post->id}/edit");
    $component = 'relationManager:'.ArticleResource\RelationManagers\TagsRelationManager::class;

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => $component, 'action' => 'detach', 'records' => [$a->id]])
        ->assertRedirect();

    expect($post->tags()->pluck('tags.id')->all())->toBe([$b->id])
        ->and($other->tags()->pluck('tags.id')->all())->toBe([$a->id]);
});

it('dissociates the selected records of a has-many relation manager', function (): void {
    $author = arUser();
    $mine = arPost(['author_id' => $author->id]);
    $theirs = arPost(['author_id' => arUser()->id]);

    $token = arToken($this->actingAs($author), "/ar/authors/{$author->id}/edit");
    $component = 'relationManager:'.AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\AuthorResource\RelationManagers\PostsRelationManager::class;

    inertiaPost($this, '/ar/_alfakit/actions', ['token' => $token, 'component' => $component, 'action' => 'dissociate', 'records' => [$mine->id, $theirs->id]])
        ->assertRedirect();

    expect($mine->fresh()->author_id)->toBeNull()
        ->and($theirs->fresh()->author_id)->not->toBeNull();
});
