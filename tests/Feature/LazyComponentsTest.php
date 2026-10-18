<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\RelationManagers\CommentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Widgets\PostStatsWidget;
use AlfaSix\Alfakit\Widgets\Widget;

function lazyAdmin(): User
{
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $post->comments()->create(['author_name' => 'Bea', 'body' => 'second', 'sort' => 0]);
    $post->comments()->create(['author_name' => 'Al', 'body' => 'first', 'sort' => 1]);

    return $user;
}

/**
 * The partial reload a renderer sends for `$only` (the deferred prop, or `page`).
 *
 * @return array<string, mixed>
 */
function partialProps(AlfaSix\Alfakit\Tests\TestCase $test, string $url, string $only): array
{
    $component = inertiaGet($test, $url)->json('component');

    $response = $test->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $only,
    ]);

    expect($response->json('deferredProps'))->toBeNull();

    return inertiaProps($response);
}

function setLazy(string $class, bool $lazy): void
{
    (new ReflectionProperty($class, 'isLazy'))->setValue(null, $lazy);
}

afterEach(function (): void {
    setLazy(Widget::class, true);
    setLazy(RelationManager::class, true);
});

it('sends lazy widgets as placeholders and loads them with a deferred prop', function (): void {
    $this->actingAs(lazyAdmin());
    $id = Widget::idFromClass(PostStatsWidget::class);

    $response = inertiaGet($this, '/admin');
    $placeholder = $response->json('props.page.content.0.widgets.0');

    expect($placeholder)->toMatchArray(['id' => $id, 'lazy' => true, 'type' => 'StatsOverviewWidget'])
        ->and($placeholder)->not->toHaveKey('stats')
        ->and($response->json('props'))->not->toHaveKey(Page::LAZY_PROP)
        ->and($response->json('deferredProps'))->toBe([Page::LAZY_PROP => [Page::LAZY_PROP]]);

    $deferred = partialProps($this, '/admin', Page::LAZY_PROP);

    expect($deferred)->not->toHaveKey('page')
        ->and(array_keys($deferred[Page::LAZY_PROP]))->toBe(["widget:{$id}"])
        ->and($deferred[Page::LAZY_PROP]["widget:{$id}"])->not->toHaveKey('lazy')
        ->and($deferred[Page::LAZY_PROP]["widget:{$id}"]['stats'][0]['value'])->toBe(1);
});

it('defers lazy relation managers and renders them in place on partial reloads', function (): void {
    $this->actingAs(lazyAdmin());
    $target = 'relationManager:'.CommentsRelationManager::class;

    $managers = fn (array $props): array => collect($props['page']['content'])->firstWhere('type', 'RelationManagers')['managers'];

    $first = $managers(inertiaProps(inertiaGet($this, '/admin/posts/1/edit')));

    expect($first[0])->toMatchArray(['component' => $target, 'title' => 'Comments', 'lazy' => true])
        ->and($first[0])->not->toHaveKey('table');

    $deferred = partialProps($this, '/admin/posts/1/edit', Page::LAZY_PROP)[Page::LAZY_PROP][$target];

    expect(array_map(fn (array $record) => $record['cells']['author_name']['state'], $deferred['table']['records']))->toBe(['Al', 'Bea']);

    // Paging and sorting reload `page`: the manager comes back complete, in its new state.
    $sorted = $managers(partialProps($this, '/admin/posts/1/edit?comments-relation-manager_sort=author_name&comments-relation-manager_direction=desc', 'page'))[0];

    expect($sorted)->not->toHaveKey('lazy')
        ->and(array_map(fn (array $record) => $record['cells']['author_name']['state'], $sorted['table']['records']))->toBe(['Bea', 'Al']);
});

it('makes no deferred request when nothing on the page is lazy', function (): void {
    setLazy(Widget::class, false);
    setLazy(RelationManager::class, false);
    $this->actingAs(lazyAdmin());

    $dashboard = inertiaGet($this, '/admin');
    $edit = inertiaGet($this, '/admin/posts/1/edit');

    expect($dashboard->json('deferredProps'))->toBeNull()
        ->and($dashboard->json('props.page.content.0.widgets.0.stats.0.value'))->toBe(1)
        ->and($edit->json('deferredProps'))->toBeNull()
        ->and(collect($edit->json('props.page.content'))->firstWhere('type', 'RelationManagers')['managers'][0]['table']['records'])->toHaveCount(2);
});
