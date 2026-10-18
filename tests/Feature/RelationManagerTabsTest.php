<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Resources\Pages\Enums\ContentTabPosition;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages\EditRtPost;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages\ViewRtPost;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtCommentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPostResource;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtTagsRelationManager;

beforeEach(function (): void {
    $this->panel = RtPanelProvider::registerPanel();
    EditRtPost::resetTabs();
    ViewRtPost::resetTabs();
    RtPostResource::$titleCase = true;
    $this->actingAs(User::query()->create(['name' => 'Rt', 'email' => 'rt'.uniqid().'@example.com', 'password' => 'password']));
    $this->post = Post::query()->create(['title' => 'Tabs', 'slug' => 'tabs-'.uniqid()]);
    $this->post->comments()->create(['author_name' => 'Ann', 'body' => 'Hi']);
    $this->post->comments()->create(['author_name' => 'Bob', 'body' => 'Hey']);
});

afterEach(function (): void {
    $this->panel->readOnlyRelationManagersOnResourceViewPagesByDefault();
});

/**
 * @return array<int, array<string, mixed>>
 */
function rtContent(AlfaSix\Alfakit\Tests\TestCase $test, string $url): array
{
    return inertiaProps(inertiaGet($test, $url))['page']['content'];
}

/**
 * @param  array<int, array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function rtManagers(array $content): array
{
    return collect($content)->firstWhere('type', 'RelationManagers');
}

it('builds the relation manager tab from getTabComponent()', function (): void {
    $managers = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}/edit"))['managers'];

    expect($managers[0])->toMatchArray([
        'title' => 'Discussion',
        'icon' => 'message-circle',
        'badge' => '2',
        'badgeColor' => 'warning',
        // No `Tab::badgeTooltip()`: the manager's own tooltip is kept.
        'badgeTooltip' => 'Comments on this post',
    ])->and($managers[1])->toMatchArray(['title' => 'Tags', 'icon' => 'tag', 'badge' => '3']);
});

it('uses the default tab component without an override', function (): void {
    $tab = RtTagsRelationManager::getTabComponent($this->post, EditRtPost::class);

    expect($tab->getLabel())->toBe('Tags')
        ->and($tab->getIcon())->toBe('tag')
        ->and($tab->getBadge())->toBe('3');
});

it('shows other relation managers on one page with getAllRelationManagers()', function (): void {
    ViewRtPost::$relationManagers = [RtTagsRelationManager::class];

    $view = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}"));
    $edit = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}/edit"));

    expect(array_column($view['managers'], 'component'))->toBe(['relationManager:'.RtTagsRelationManager::class])
        ->and($edit['managers'])->toHaveCount(2);
});

it('leaves out the relation managers block when a page returns none', function (): void {
    EditRtPost::$relationManagers = [];

    $content = rtContent($this, "/rt/rt-posts/{$this->post->id}/edit");

    expect(collect($content)->firstWhere('type', 'RelationManagers'))->toBeNull();
});

it('combines the form with the relation manager tabs', function (): void {
    EditRtPost::$combined = true;

    $content = rtContent($this, "/rt/rt-posts/{$this->post->id}/edit");
    $block = rtManagers($content);

    expect(array_column($content, 'key'))->toBe(['relationManagers'])
        ->and($block['content'])->toMatchArray(['label' => 'Edit', 'icon' => 'file-text', 'position' => 'before'])
        ->and($block['content']['block'])->toMatchArray(['type' => 'Form', 'key' => 'form']);
});

it('combines the infolist with a custom label after the tabs', function (): void {
    ViewRtPost::$combined = true;
    ViewRtPost::$contentLabel = 'Details';
    ViewRtPost::$contentPosition = ContentTabPosition::After;

    $block = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}"));

    expect($block['content'])->toMatchArray(['label' => 'Details', 'position' => 'after'])
        ->and($block['content']['block']['key'])->toBe('infolist');
});

it('keeps the form on its own when there are no relation managers to combine with', function (): void {
    EditRtPost::$combined = true;
    EditRtPost::$relationManagers = [];

    expect(array_column(rtContent($this, "/rt/rt-posts/{$this->post->id}/edit"), 'key'))->toBe(['form']);
});

it('makes relation managers read-only on View pages by default', function (): void {
    $comments = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}"))['managers'][0];

    expect($comments['table']['headerActions'] ?? [])->toBe([]);
});

it('lets relation managers write on View pages when the panel allows it', function (): void {
    $this->panel->readOnlyRelationManagersOnResourceViewPagesByDefault(false);

    $comments = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}"))['managers'][0];

    expect(array_column($comments['table']['headerActions'], 'name'))->toBe(['create']);
});

it('still hides write actions from users who may not update the owner record', function (): void {
    $this->panel->readOnlyRelationManagersOnResourceViewPagesByDefault(fn (): bool => false);
    alfakit()->setCurrentPanel($this->panel);

    $page = ViewRtPost::fromContext(new AlfaSix\Alfakit\Support\SchemaContext('rt', ViewRtPost::class, ['record' => $this->post->id]));
    $manager = $page->getRelationManager(RtCommentsRelationManager::class);

    expect($manager?->isReadOnly())->toBeFalse();

    Gate::policy(Post::class, RtDenyUpdatePolicy::class);

    $page = ViewRtPost::fromContext(new AlfaSix\Alfakit\Support\SchemaContext('rt', ViewRtPost::class, ['record' => $this->post->id]));

    expect($page->getRelationManager(RtCommentsRelationManager::class)?->isReadOnly())->toBeTrue();
});

it('title-cases model labels unless $hasTitleCaseModelLabel is off', function (): void {
    expect(RtPostResource::getTitleCaseModelLabel())->toBe('EBook')
        ->and(RtPostResource::getTitleCasePluralModelLabel())->toBe('EBooks')
        ->and(RtPostResource::getNavigationLabel())->toBe('EBooks');

    RtPostResource::$titleCase = false;

    expect(RtPostResource::getTitleCaseModelLabel())->toBe('eBook')
        ->and(RtPostResource::getTitleCasePluralModelLabel())->toBe('eBooks')
        ->and(RtPostResource::getNavigationLabel())->toBe('eBooks');
});

class RtDenyUpdatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return false;
    }
}

it('places the content tab between relation manager tabs with getCombinedTabComponents()', function (): void {
    EditRtPost::$combined = true;
    EditRtPost::$relationManagers = ['comments' => RtCommentsRelationManager::class, 'tags' => RtTagsRelationManager::class];
    EditRtPost::$combinedTabs = fn (EditRtPost $page): array => [
        'comments' => $page->getRelationManagerTabComponent('comments'),
        '' => $page->getContentTabComponent(),
        'tags' => $page->getRelationManagerTabComponent('tags'),
        'missing' => $page->getRelationManagerTabComponent('missing'),
    ];

    $block = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}/edit"));

    expect($block['tabOrder'])->toBe([
        'relationManager:'.RtCommentsRelationManager::class.'@comments',
        'content',
        'relationManager:'.RtTagsRelationManager::class.'@tags',
    ])->and($block['content']['label'])->toBe('Edit');
});

it('sends no tab order for the default before/after placement', function (): void {
    EditRtPost::$combined = true;
    EditRtPost::$contentPosition = ContentTabPosition::After;

    $block = rtManagers(rtContent($this, "/rt/rt-posts/{$this->post->id}/edit"));

    expect($block)->not->toHaveKey('tabOrder')
        ->and($block['content']['position'])->toBe('after');
});
