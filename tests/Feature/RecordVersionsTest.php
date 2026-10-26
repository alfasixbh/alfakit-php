<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\HistoryAction;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\PalettePostPolicy;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\VersionedPost;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\VersionedPostResource;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\VersionedProject;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\VersionedProjectResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Versions\RecordVersion;
use AlfaSix\Alfakit\Versions\VersionDiff;
use Illuminate\Support\Facades\Gate;

/*
 * Record history: `HasRecordVersions` snapshots, the History slide-over (diffs), restoring
 * a version (update policy), tenancy and pruning.
 */

function versionsPanel(string $id = 'versions'): Panel
{
    return Panels::register($id, fn (Panel $panel): Panel => $panel
        ->login()
        ->resources([VersionedPostResource::class])
        ->pages([Dashboard::class]));
}

function versionsUser(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).uniqid().'@example.com', 'password' => 'secret']);
}

function versionedPost(User $author, array $attributes = []): VersionedPost
{
    return VersionedPost::query()->create([...['title' => 'Hello', 'slug' => 'hello-'.uniqid(), 'is_published' => true, 'author_id' => $author->getKey()], ...$attributes]);
}

/**
 * @return array<string, mixed>
 */
function historyModal(object $test, string $url, string $panel = 'versions'): array
{
    $token = inertiaProps(inertiaGet($test, $url))['page']['token'];

    return $test->getJson("/{$panel}/_alfakit/actions/modal?".http_build_query(['token' => $token, 'component' => 'page', 'action' => 'history']))
        ->assertOk()
        ->json();
}

beforeEach(function (): void {
    PalettePostPolicy::$canCreate = true;
    Gate::policy(VersionedPost::class, PalettePostPolicy::class);
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000300_create_alfakit_record_versions_table.php')->up();
});

// ---------------------------------------------------------------- Capture

it('stores a snapshot of the tracked attributes on create, update and delete', function (): void {
    $ada = versionsUser();
    $this->actingAs($ada);

    $post = versionedPost($ada, ['title' => 'First', 'rating' => 3]);
    $post->update(['title' => 'Second']);
    // Only untracked attributes changed: no version.
    $post->update(['rating' => 5]);
    $post->delete();

    $versions = RecordVersion::query()->orderBy('id')->get();

    expect($versions->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($versions[1]->getSnapshot()['title'])->toBe('Second')
        ->and($versions[0]->getSnapshot())->not->toHaveKeys(['id', 'rating', 'created_at', 'updated_at', 'deleted_at'])
        ->and($versions[0]->author_id)->toBe((string) $ada->getKey())
        ->and($versions[0]->versionable_type)->toBe(VersionedPost::class);
});

it('keeps only the newest versions per record', function (): void {
    $ada = versionsUser();
    $post = versionedPost($ada);

    foreach (range(1, 8) as $i) {
        $post->update(['title' => "Title {$i}"]);
    }

    // `$maxVersions = 5` on the fixture model.
    expect($post->recordVersions()->count())->toBe(5)
        ->and($post->recordVersions()->first()?->getSnapshot()['title'])->toBe('Title 8');
});

it('pauses versioning with withoutVersions()', function (): void {
    $post = VersionedPost::withoutVersions(fn () => versionedPost(versionsUser()));

    expect($post->recordVersions()->count())->toBe(0);
});

it('keeps a baseline of a record that existed before versioning, so its first change shows old and new (W-B-11)', function (): void {
    versionsPanel();
    $ada = versionsUser();
    $this->actingAs($ada);
    // Created before the model used HasRecordVersions: no versions.
    $post = VersionedPost::withoutVersions(fn () => versionedPost($ada, ['title' => 'Published one']));

    $post->update(['title' => 'Published one v2']);
    $post->update(['title' => 'Published one v3']);

    expect(RecordVersion::query()->orderBy('id')->pluck('event')->all())->toBe(['baseline', 'updated', 'updated']);

    $versions = historyModal($this, "/versions/versioned-posts/{$post->getKey()}/edit")['content'][0]['versions'];

    expect(array_column($versions, 'event'))->toBe(['updated', 'updated', 'baseline'])
        ->and($versions[2]['eventLabel'])->toBe('Before the history started')
        ->and($versions[2])->not->toHaveKey('author')
        ->and(collect($versions[1]['changes'])->firstWhere('attribute', 'title'))
        ->toMatchArray(['old' => 'Published one', 'new' => 'Published one v2']);
});

// ---------------------------------------------------------------- History slide-over

it('adds a History action with field-by-field diffs to edit and view pages', function (): void {
    versionsPanel();
    $ada = versionsUser();
    $this->actingAs($ada);
    $post = versionedPost($ada, ['title' => 'Draft', 'content' => '<p>The quick brown fox</p>']);
    $post->update(['title' => 'Final', 'content' => '<p>The quick <strong>red</strong> fox</p>']);

    $actions = inertiaProps(inertiaGet($this, "/versions/versioned-posts/{$post->getKey()}/edit"))['page']['headerActions'];
    expect(collect($actions)->pluck('name')->all())->toContain('history');

    $actions = inertiaProps(inertiaGet($this, "/versions/versioned-posts/{$post->getKey()}"))['page']['headerActions'];
    expect(collect($actions)->pluck('name')->all())->toContain('history');

    $modal = historyModal($this, "/versions/versioned-posts/{$post->getKey()}/edit");
    $list = $modal['content'][0];

    expect($modal['slideOver'])->toBeTrue()
        ->and($list['type'])->toBe('RecordVersions')
        ->and($list['actionPath'])->toBe(['history'])
        ->and($list['restoreAction']['name'])->toBe(HistoryAction::RESTORE_ACTION)
        ->and(array_column($list['versions'], 'event'))->toBe(['updated', 'created'])
        ->and($list['versions'][0]['author'])->toBe('Ada')
        ->and($list['versions'][0]['current'])->toBeTrue();

    $changes = collect($list['versions'][0]['changes'])->keyBy('attribute');

    expect($changes->keys()->all())->toBe(['title', 'content'])
        ->and($changes['title']['old'])->toBe('Draft')
        ->and($changes['title']['new'])->toBe('Final')
        // Rich text is compared as text, word by word.
        ->and($changes['content']['old'])->toBe('The quick brown fox')
        ->and($changes['content']['new'])->toBe('The quick red fox')
        ->and($changes['content']['diff'])->toBe([
            ['type' => 'equal', 'text' => 'The quick '],
            ['type' => 'delete', 'text' => 'brown '],
            ['type' => 'insert', 'text' => 'red '],
            ['type' => 'equal', 'text' => 'fox'],
        ]);

    // The first version lists the values it was created with.
    expect(collect($list['versions'][1]['changes'])->firstWhere('attribute', 'title'))
        ->toMatchArray(['old' => null, 'new' => 'Draft']);
});

it('restores a version for users the update policy allows, as a new version', function (): void {
    versionsPanel();
    $ada = versionsUser();
    $this->actingAs($ada);
    $post = versionedPost($ada, ['title' => 'Draft', 'rating' => 1]);
    $post->update(['title' => 'Final', 'rating' => 9]);
    $created = $post->recordVersions()->get()->last();

    $token = inertiaProps(inertiaGet($this, "/versions/versioned-posts/{$post->getKey()}/edit"))['page']['token'];

    inertiaPost($this, '/versions/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'history>restoreVersion', 'arguments' => ['version' => $created->getKey()]])
        ->assertRedirect("http://localhost/versions/versioned-posts/{$post->getKey()}/edit");

    $post->refresh();
    $latest = $post->recordVersions()->first();

    expect($post->title)->toBe('Draft')
        // Untracked attributes are left alone.
        ->and($post->rating)->toBe(9)
        ->and($latest?->event)->toBe(RecordVersion::EVENT_REVERTED)
        ->and($latest?->restored_from_id)->toBe($created->getKey())
        ->and($post->recordVersions()->count())->toBe(3);
});

it('refuses restoring without the update ability, and versions of other records', function (): void {
    versionsPanel();
    $ada = versionsUser();
    $bob = versionsUser('Bob');
    $post = versionedPost($ada, ['title' => 'Draft']);
    $post->update(['title' => 'Final']);
    $other = versionedPost($bob, ['title' => 'Other']);
    $foreign = $other->recordVersions()->first();

    // Bob may view Ada's published post but not update it.
    $this->actingAs($bob);
    $list = historyModal($this, "/versions/versioned-posts/{$post->getKey()}")['content'][0];

    expect($list)->not->toHaveKey('restoreAction')
        ->and($list['versions'])->toHaveCount(2);

    $token = inertiaProps(inertiaGet($this, "/versions/versioned-posts/{$post->getKey()}"))['page']['token'];
    inertiaPost($this, '/versions/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'history>restoreVersion', 'arguments' => ['version' => $post->recordVersions()->get()->last()->getKey()]])
        ->assertForbidden();

    // Ada can't restore Bob's version onto her post.
    $this->actingAs($ada);
    $token = inertiaProps(inertiaGet($this, "/versions/versioned-posts/{$post->getKey()}/edit"))['page']['token'];
    inertiaPost($this, '/versions/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'history>restoreVersion', 'arguments' => ['version' => $foreign->getKey()]])
        ->assertNotFound();
    inertiaPost($this, '/versions/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'history>restoreVersion', 'arguments' => ['version' => 'drop table']])
        ->assertStatus(422);

    expect($post->refresh()->title)->toBe('Final');
});

it('hides the history from users who may not view the record', function (): void {
    versionsPanel();
    $ada = versionsUser();
    $post = versionedPost($ada, ['is_published' => false]);

    $this->actingAs(versionsUser('Bob'));

    inertiaGet($this, "/versions/versioned-posts/{$post->getKey()}")->assertForbidden();
});

it('lists and restores only versions of the current tenant', function (): void {
    $panel = Panels::register('vteams', fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->resources([VersionedProjectResource::class])
        ->pages([Dashboard::class]));

    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada-teams@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'valpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'vbeta']);
    $user->teams()->attach([$alpha->id, $beta->id]);
    $this->actingAs($user);

    alfakit()->setCurrentPanel($panel);
    alfakit()->setTenant($alpha);
    $project = VersionedProject::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha v1']);
    $project->update(['name' => 'Alpha v2']);
    $betaProject = VersionedProject::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    // A version written in another tenant (e.g. a record that moved) stays hidden here.
    $foreign = $project->recordVersions()->create(['event' => 'updated', 'snapshot' => ['name' => 'Leaked'], 'tenant_type' => $beta->getMorphClass(), 'tenant_id' => (string) $beta->getKey()]);
    alfakit()->setTenant(null);

    expect($project->recordVersions()->get()->last()?->tenant_id)->toBe((string) $alpha->getKey());

    $list = historyModal($this, "/vteams/valpha/versioned-projects/{$project->getKey()}/edit", 'vteams/valpha')['content'][0];

    expect(array_column($list['versions'], 'id'))->not->toContain($foreign->getKey())
        ->and($list['versions'])->toHaveCount(2);

    $token = inertiaProps(inertiaGet($this, "/vteams/valpha/versioned-projects/{$project->getKey()}/edit"))['page']['token'];
    inertiaPost($this, '/vteams/valpha/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'history>restoreVersion', 'arguments' => ['version' => $foreign->getKey()]])
        ->assertNotFound();

    // The record of another tenant can't be opened from this tenant at all.
    inertiaGet($this, "/vteams/valpha/versioned-projects/{$betaProject->getKey()}/edit")->assertNotFound();

    expect($project->refresh()->name)->toBe('Alpha v2');
});

// ---------------------------------------------------------------- Pruning

it('prunes versions by age and count', function (): void {
    $ada = versionsUser();
    $post = versionedPost($ada);

    foreach (range(1, 3) as $i) {
        $post->update(['title' => "Title {$i}"]);
    }

    RecordVersion::query()->whereKey($post->recordVersions()->get()->last()->getKey())->update(['created_at' => now()->subDays(40)]);

    $this->artisan('alfakit:prune-versions')->assertExitCode(2);
    $this->artisan('alfakit:prune-versions', ['--days' => 'x'])->assertExitCode(2);
    $this->artisan('alfakit:prune-versions', ['--days' => 30])->assertSuccessful();

    expect($post->recordVersions()->count())->toBe(3);

    $this->artisan('alfakit:prune-versions', ['--keep' => 1, '--model' => VersionedPost::class])->assertSuccessful();

    expect($post->recordVersions()->count())->toBe(1)
        ->and($post->recordVersions()->first()?->getSnapshot()['title'])->toBe('Title 3');
});

// ---------------------------------------------------------------- Diff

it('diffs text word by word and shows JSON and empty values as text', function (): void {
    expect(VersionDiff::words('a b c', 'a x c'))->toBe([
        ['type' => 'equal', 'text' => 'a '],
        ['type' => 'delete', 'text' => 'b '],
        ['type' => 'insert', 'text' => 'x '],
        ['type' => 'equal', 'text' => 'c'],
    ])
        ->and(VersionDiff::toText('{"a":1}'))->toBe('{"a":1}')
        ->and(VersionDiff::toText(['a' => 1]))->toBe('{"a":1}')
        ->and(VersionDiff::toText(''))->toBeNull()
        ->and(VersionDiff::toText('<p>Hi&nbsp;<em>there</em></p><p>Bye</p>'))->toBe('Hi there Bye')
        ->and(VersionDiff::compare(['a' => '1', 'b' => 'x'], ['a' => 1, 'b' => 'y']))->toBe([
            ['attribute' => 'b', 'label' => 'B', 'old' => 'x', 'new' => 'y'],
        ])
        // Labelled like fields and columns: "Author", "Cover image URL", not "Author Id".
        ->and(array_column(VersionDiff::compare(null, ['author_id' => 1, 'cover_image_url' => 'x']), 'label'))->toBe(['Author', 'Cover image URL']);
});

it('keeps the current owner when a version is restored in a scopeToUser() panel', function (): void {
    Panels::register('vportal', fn (Panel $panel): Panel => $panel
        ->login()
        ->resources([VersionedPostResource::class])
        ->scopeToUser('author_id')
        ->pages([Dashboard::class]));

    $cora = versionsUser('Cora');
    $dan = versionsUser('Dan');
    $post = versionedPost($cora, ['title' => 'Draft']);
    $created = $post->recordVersions()->first();

    // Staff hand the record over to Dan, who edits it.
    $post->update(['author_id' => $dan->getKey()]);
    $post->update(['title' => 'Final']);

    $this->actingAs($dan);
    $token = inertiaProps(inertiaGet($this, "/vportal/versioned-posts/{$post->getKey()}/edit"))['page']['token'];

    inertiaPost($this, '/vportal/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'history>restoreVersion', 'arguments' => ['version' => $created->getKey()]])
        ->assertRedirect();

    $post->refresh();

    // The content comes back; the owner stays Dan (not Cora, the owner in that version).
    expect($post->title)->toBe('Draft')
        ->and($post->author_id)->toBe($dan->getKey());
});

it('explains that there is nothing to prune before the versions table exists', function (): void {
    Illuminate\Support\Facades\Schema::dropIfExists((new RecordVersion)->getTable());

    $this->artisan('alfakit:prune-versions', ['--days' => 30])
        ->expectsOutputToContain('Nothing to prune')
        ->assertSuccessful();
});
