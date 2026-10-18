<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Widgets\PostStatsWidget;
use AlfaSix\Alfakit\Widgets\Widget;
use Illuminate\Http\Request;

function pollingAdmin(): User
{
    return User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
}

/**
 * Perform the partial reload a renderer sends when polling the given components.
 *
 * @param  array<string>  $targets
 * @return array<string, mixed>
 */
function pollProps(AlfaSix\Alfakit\Tests\TestCase $test, string $url, array $targets): array
{
    $component = inertiaGet($test, $url)->json('component');

    return inertiaProps($test->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => Page::POLL_PROP,
        Page::POLL_HEADER => implode(',', $targets),
    ]));
}

it('does not send polled components on a normal visit', function (): void {
    $props = inertiaProps(inertiaGet($this->actingAs(pollingAdmin()), '/admin'));

    expect($props)->toHaveKey('page')
        ->and($props)->not->toHaveKey(Page::POLL_PROP);
});

it('polls only the requested widget without the rest of the page', function (): void {
    $user = pollingAdmin();
    Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $id = Widget::idFromClass(PostStatsWidget::class);

    $props = pollProps($this->actingAs($user), '/admin', ["widget:{$id}", 'widget:missing', 'table']);

    expect($props)->not->toHaveKey('page')
        ->and(array_keys($props[Page::POLL_PROP]))->toBe(["widget:{$id}"])
        ->and($props[Page::POLL_PROP]["widget:{$id}"]['id'])->toBe($id)
        ->and($props[Page::POLL_PROP]["widget:{$id}"]['stats'][0]['value'])->toBe(1);
});

it('polls the page table with the current query string state', function (): void {
    $user = pollingAdmin();
    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'author_id' => $user->id]);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta', 'author_id' => $user->id]);

    $props = pollProps($this->actingAs($user), '/admin/posts?sort=title&direction=desc', ['table']);
    $table = $props[Page::POLL_PROP]['table'];

    expect($props)->not->toHaveKey('page')
        ->and($table['type'])->toBe('Table')
        ->and($table['component'])->toBe('table')
        ->and($table['sort'])->toBe(['column' => 'title', 'direction' => 'desc'])
        ->and($table['records'][0]['cells']['title']['state'])->toBe('Beta');
});

it('reads at most fifty unique poll targets from the header', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set(Page::POLL_HEADER, ' table , table,'.implode(',', array_map(fn (int $i): string => "widget:w{$i}", range(1, 80))));

    $targets = Page::pollTargets($request);

    expect($targets[0])->toBe('table')
        ->and($targets)->toHaveCount(50)
        ->and(array_unique($targets))->toBe($targets);
});
