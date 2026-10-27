<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\CreatePost;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\EditPost;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\ListPosts;
use AlfaSix\Alfakit\Tests\Fixtures\Testing\HelperActionsPage;
use AlfaSix\Alfakit\Tests\Fixtures\Testing\HelperBulkActionsPage;
use AlfaSix\Alfakit\Tests\Fixtures\Testing\HelpersPanelProvider;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

class PageTesterTestPostPolicy
{
    public static bool $delete = true;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return true;
    }

    public function delete(User $user, Post $post): bool
    {
        return self::$delete;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    PageTesterTestPostPolicy::$delete = true;
    HelperActionsPage::$runs = [];
});

function testerUser(): User
{
    return User::query()->create(['name' => 'Tess', 'email' => 'tess'.uniqid().'@example.com', 'password' => 'password']);
}

function testerPost(User $user, array $attributes = []): Post
{
    static $i = 0;
    $i++;

    return Post::query()->create(['title' => "Post {$i}", 'slug' => "post-{$i}", 'author_id' => $user->id, ...$attributes]);
}

// ---------------------------------------------------------------- Forms

it('fills a form through the refresh endpoint and saves it', function (): void {
    $user = testerUser();
    $post = testerPost($user);
    $this->actingAs($user);

    alfakit()->testPage(EditPost::class, ['record' => $post])
        ->assertOk()
        ->assertFormSet(['title' => $post->title, 'slug' => $post->slug])
        ->fillForm(['title' => 'Hello World'])
        // `afterStateUpdated` ran on the server and derived the slug.
        ->assertFormSet(['slug' => 'hello-world'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved')
        ->assertRedirect(PostResource::getUrl('edit', ['record' => $post]))
        // The page was reloaded from the saved record.
        ->assertFormSet(fn (array $state): array => ['title' => 'Hello World']);

    expect($post->refresh())->title->toBe('Hello World')->slug->toBe('hello-world');
});

it('reports validation errors by rule or message', function (): void {
    $user = testerUser();
    $post = testerPost($user);
    $other = testerPost($user);
    $this->actingAs($user);

    alfakit()->testPage(EditPost::class, ['record' => $post])
        ->fillForm(['title' => '', 'slug' => $other->slug])
        ->call('save')
        ->assertHasFormErrors(['title' => 'required', 'data.slug' => 'unique'])
        ->assertHasFormErrors(['title'])
        ->assertHasNoFormErrors(['author_id'])
        ->assertNotNotified('Saved');

    $failed = alfakit()->testPage(EditPost::class, ['record' => $post])->fillForm(['title' => ''])->call('save');

    expect(fn () => $failed->assertHasNoFormErrors())->toThrow(AssertionFailedError::class)
        ->and(fn () => $failed->assertHasFormErrors(['title' => 'unique']))->toThrow(AssertionFailedError::class)
        ->and(fn () => $failed->assertHasFormErrors(['author_id']))->toThrow(AssertionFailedError::class);

    expect($post->refresh()->title)->not->toBe('');
});

it('creates records and asserts fields', function (): void {
    $user = testerUser();
    $this->actingAs($user);

    $page = PageTester::make(CreatePost::class)
        ->assertFormFieldExists('title')
        ->assertFormFieldIsRequired('title')
        ->assertFormFieldEnabled('slug')
        ->assertFormFieldDoesNotExist('secret')
        ->assertActionVisible('create')
        ->fillForm(['title' => 'Brand new', 'author_id' => $user->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $post = Post::query()->where('slug', 'brand-new')->firstOrFail();

    $page->assertRedirect(PostResource::getUrl('edit', ['record' => $post]));
});

it('fails clearly when the action request is refused', function (): void {
    $user = testerUser();
    $post = testerPost($user);
    $this->actingAs($user);

    $page = alfakit()->testPage(EditPost::class, ['record' => $post])->call('missing');

    $page->assertNotFound();

    expect(fn () => $page->assertHasNoFormErrors())->toThrow(AssertionFailedError::class, 'failed with status 404');
});

// ---------------------------------------------------------------- Tables

it('searches, sorts and filters tables', function (): void {
    $user = testerUser();
    $alpha = testerPost($user, ['title' => 'Alpha', 'is_published' => true]);
    $beta = testerPost($user, ['title' => 'Beta']);
    $gamma = testerPost($user, ['title' => 'Gamma', 'is_published' => true]);
    $this->actingAs($user);

    alfakit()->testPage(ListPosts::class)
        ->assertCanSeeTableRecords([$alpha, $beta, $gamma])
        ->assertCountTableRecords(3)
        ->assertTableColumnExists('title')
        ->assertTableColumnStateSet('title', 'Beta', $beta)
        ->searchTable('Bet')
        ->assertCanSeeTableRecords([$beta])
        ->assertCanNotSeeTableRecords([$alpha, $gamma])
        ->searchTable()
        ->sortTable('title', 'desc')
        ->assertCanSeeTableRecords([$gamma, $beta, $alpha], inOrder: true)
        ->filterTable('is_published', true)
        ->assertCanSeeTableRecords([$alpha, $gamma])
        ->assertCanNotSeeTableRecords([$beta])
        ->filterTable('is_published', false)
        ->assertCanSeeTableRecords([$beta])
        ->resetTableFilters()
        ->assertCountTableRecords(3);

    expect(fn () => alfakit()->testPage(ListPosts::class)->sortTable('title')->assertCanSeeTableRecords([$gamma, $alpha], inOrder: true))
        ->toThrow(AssertionFailedError::class);
});

it('runs record and bulk actions from the table', function (): void {
    Gate::policy(Post::class, PageTesterTestPostPolicy::class);
    $user = testerUser();
    [$a, $b, $c] = [testerPost($user), testerPost($user), testerPost($user)];
    $this->actingAs($user);

    alfakit()->testPage(ListPosts::class)
        ->assertTableActionVisible('edit', $a)
        ->assertTableActionVisible(DeleteAction::class, $a)
        ->assertTableBulkActionVisible(DeleteBulkAction::class)
        ->callTableAction(DeleteAction::class, $a)
        ->assertActionSucceeded()
        ->assertNotified()
        ->assertCanNotSeeTableRecords([$a])
        ->callTableBulkAction('delete', [$b])
        ->assertHasNoActionErrors()
        ->assertCanSeeTableRecords([$c])
        ->assertCanNotSeeTableRecords([$b]);

    expect(Post::query()->pluck('id')->all())->toBe([$c->id]);

    PageTesterTestPostPolicy::$delete = false;

    alfakit()->testPage(ListPosts::class)->assertTableActionHidden('delete', $c);
});

it('runs every filtered record with a select-all bulk action', function (): void {
    $user = testerUser();
    $kept = testerPost($user, ['title' => 'Keep me']);
    testerPost($user, ['title' => 'Drop one']);
    testerPost($user, ['title' => 'Drop two']);
    $this->actingAs($user);

    alfakit()->testPage(ListPosts::class)
        ->searchTable('Drop')
        ->callTableBulkAction('delete', null)
        ->assertActionSucceeded()
        ->searchTable()
        ->assertCanSeeTableRecords([$kept])
        ->assertCountTableRecords(1);
});

it('fills the modal form of a bulk action with the selection, as the browser does', function (): void {
    HelpersPanelProvider::registerPanel();
    HelperBulkActionsPage::$runs = [];
    $user = testerUser();
    [$a, $b, $c] = [testerPost($user), testerPost($user), testerPost($user)];
    $this->actingAs($user);

    // Every filled field refreshes the form, which must address the bulk action and its
    // selected records (it answered 404 without them).
    alfakit()->testPage(HelperBulkActionsPage::class, panel: 'helpers')
        ->callTableBulkAction('approveSelected', [$a, $b], ['comment' => 'Photos checked'])
        ->assertHasNoActionErrors()
        ->mountTableBulkAction('approveSelected', [$c])
        ->fillForm(['summary' => 'Late'])
        ->assertFormSet(['comment' => 'Re: Late'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(HelperBulkActionsPage::$runs)->toBe([
        ['ids' => [$a->id, $b->id], 'data' => ['comment' => 'Photos checked', 'summary' => null]],
        ['ids' => [$c->id], 'data' => ['comment' => 'Re: Late', 'summary' => 'Late']],
    ]);
});

it('fills the modal form of a select-all bulk action', function (): void {
    HelpersPanelProvider::registerPanel();
    HelperBulkActionsPage::$runs = [];
    $user = testerUser();
    testerPost($user, ['title' => 'Keep me']);
    $first = testerPost($user, ['title' => 'Drop one']);
    $second = testerPost($user, ['title' => 'Drop two']);
    $this->actingAs($user);

    alfakit()->testPage(HelperBulkActionsPage::class, panel: 'helpers')
        ->searchTable('Drop')
        ->callTableBulkAction('approveSelected', null, ['comment' => 'All of them'])
        ->assertHasNoActionErrors();

    expect(HelperBulkActionsPage::$runs[0]['ids'])->toEqualCanonicalizing([$first->id, $second->id])
        ->and(HelperBulkActionsPage::$runs[0]['data']['comment'])->toBe('All of them');
});

// ---------------------------------------------------------------- Page actions

it('mounts modal actions, fills their form and runs them', function (): void {
    HelpersPanelProvider::registerPanel();
    $this->actingAs(testerUser());

    $page = alfakit()->testPage(HelperActionsPage::class, panel: 'helpers')
        ->assertActionVisible('rename')
        ->assertActionHidden('secret')
        ->assertActionDisabled('locked')
        ->assertActionEnabled('rename')
        ->mountAction('rename')
        ->assertActionMounted('rename')
        ->assertFormSet(['name' => 'Default'])
        ->fillForm(['name' => 'Hello There'])
        ->assertFormSet(['slug' => 'hello-there'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionNotMounted()
        ->assertNotified(Notification::make()->title('Renamed')->body('All good'))
        ->assertNoRedirect();

    expect(HelperActionsPage::$runs)->toBe([['rename' => ['name' => 'Hello There', 'slug' => 'hello-there']]]);

    $page->callAction('rename', ['name' => str_repeat('x', 30)])
        ->assertHasActionErrors(['name' => 'max'])
        ->assertActionMounted('rename')
        ->setActionData(['name' => 'Fixed'])
        ->callMountedAction()
        ->assertActionSucceeded('rename');

    expect(HelperActionsPage::$runs)->toHaveCount(2);
});

it('runs plain, halted, redirecting, nested and hidden actions', function (): void {
    HelpersPanelProvider::registerPanel();
    $this->actingAs(testerUser());

    $page = alfakit()->testPage(HelperActionsPage::class, panel: 'helpers');

    $page->callAction('ping', arguments: ['id' => 5])->assertActionSucceeded('ping');
    $page->callAction('stop')->assertActionHalted('stop');
    $page->callAction('away')->assertRedirect('/helpers/elsewhere');
    $page->callAction(['parent', 'child'])->assertActionSucceeded();
    $page->callAction('secret')->assertForbidden();

    expect(HelperActionsPage::$runs)->toBe([['ping' => ['id' => 5]], ['child' => true]]);
});

it('validates and saves translatable fields through the action endpoints', function (): void {
    HelpersPanelProvider::registerPanel();
    $this->actingAs(testerUser());

    alfakit()->testPage(HelperActionsPage::class, panel: 'helpers')
        ->mountAction('translate')
        ->assertFormFieldExists('title', fn (array $field): bool => array_column($field['translatable']['locales'], 'code') === ['en', 'ar'])
        ->assertFormSet(['title' => ['en' => null, 'ar' => null]])
        ->fillForm(['title' => ['en' => '', 'ar' => 'طويل جدا جدا جدا']])
        ->callMountedAction()
        ->assertHasActionErrors(['title.en' => 'required', 'title.ar' => 'max'])
        ->fillForm(['title.en' => 'Hello', 'title.ar' => 'مرحبا'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(HelperActionsPage::$runs)->toBe([['translate' => ['title' => ['en' => 'Hello', 'ar' => 'مرحبا']]]]);
});

it('runs the delete header action of an edit page', function (): void {
    Gate::policy(Post::class, PageTesterTestPostPolicy::class);
    $user = testerUser();
    $post = testerPost($user);
    $this->actingAs($user);

    alfakit()->testPage(EditPost::class, ['record' => $post])
        ->assertActionVisible(DeleteAction::class)
        ->callAction(DeleteAction::class)
        ->assertActionSucceeded()
        ->assertRedirect(PostResource::getUrl());

    expect(Post::query()->find($post->id))->toBeNull();

    PageTesterTestPostPolicy::$delete = false;
    $other = testerPost($user);

    alfakit()->testPage(EditPost::class, ['record' => $other])->assertActionHidden('delete');
});

it('loads guest-denied and forbidden pages without throwing', function (): void {
    alfakit()->testPage(ListPosts::class)->assertStatus(302);
});

it('gives raw requests the asset version a built app expects', function (): void {
    $manifest = public_path('build/manifest.json');
    $existed = file_exists($manifest);

    if (! $existed) {
        @mkdir(dirname($manifest), 0777, true);
        file_put_contents($manifest, '{"resources/js/app.ts":{"file":"assets/app.js"}}');
    }

    try {
        $this->actingAs(testerUser());

        // Before any request Inertia doesn't know the version yet; an empty one answers 409.
        $this->get('/admin/posts', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertStatus(409);

        Inertia::version('');

        $headers = PageTester::inertiaHeaders();

        expect($headers['X-Inertia-Version'])->not->toBe('');
        $this->get('/admin/posts', $headers)->assertOk()->assertJsonPath('component', 'Alfakit/Page');
    } finally {
        if (! $existed) {
            @unlink($manifest);
        }
    }
});
