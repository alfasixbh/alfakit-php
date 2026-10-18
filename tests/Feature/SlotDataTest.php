<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\EditPost;
use AlfaSix\Alfakit\Theming\Concerns\HasSlots;
use AlfaSix\Alfakit\Theming\Slot;
use AlfaSix\Alfakit\Theming\Slots;

afterEach(function (): void {
    Slots::flush();
    Icons::flush();
});

it('passes $data with the page, resource, record and panel to slot closures', function (): void {
    $seen = [];
    alfakit()->getPanel('admin')->slot(Slot::PAGE_END, function (array $data) use (&$seen): string {
        $seen = $data;

        return '<p>'.($data['record']?->getAttribute('title') ?? 'none').'</p>';
    });

    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $this->actingAs($user);

    $slots = inertiaProps(inertiaGet($this, "/admin/posts/{$post->id}/edit"))['alfakit']['slots'];

    expect($slots[Slot::PAGE_END][0]['html'])->toBe('<p>Hello</p>')
        ->and($seen['page'])->toBe(EditPost::class)
        ->and($seen['resource'])->toBe(EditPost::getResource())
        ->and($seen['panel'])->toBeInstanceOf(Panel::class)
        ->and($seen)->toHaveKey('tenant');
});

it('passes no record on pages without one', function (): void {
    alfakit()->getPanel('admin')->slot(Slot::PAGE_END, fn (array $data): string => '<p>'.($data['record'] === null ? 'none' : 'record').'</p>');

    $this->actingAs(admin());
    $slots = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['slots'];

    expect($slots[Slot::PAGE_END][0]['html'])->toBe('<p>none</p>');
});

it('sends `when` conditions with slot items for per-render matching', function (): void {
    alfakit()->getPanel('admin')->slot(Slot::TABLE_HEADER_CELL, '<b>*</b>', when: ['column' => 'title']);
    Slots::register(Slot::MODAL_SCHEMA_BEFORE, '<p>careful</p>', when: ['action' => ['delete', 'forceDelete']]);
    Slots::register(Slot::TABLE_CONTENT_AFTER, '<p>always</p>');

    $this->actingAs(admin());
    $slots = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['slots'];

    expect($slots[Slot::TABLE_HEADER_CELL][0])->toBe(['type' => 'html', 'html' => '<b>*</b>', 'when' => ['column' => 'title']])
        ->and($slots[Slot::MODAL_SCHEMA_BEFORE][0]['when'])->toBe(['action' => ['delete', 'forceDelete']])
        ->and($slots[Slot::TABLE_CONTENT_AFTER][0])->not->toHaveKey('when');
});

it('renders one slot with extra data on the server', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->slot(Slot::TABLE_HEADER_CELL, fn (array $data): string => '<i>'.$data['column'].'</i>');
    $panel->slot(Slot::TABLE_HEADER_CELL, '<b>title only</b>', when: ['column' => 'title']);

    expect(array_column($panel->renderSlot(Slot::TABLE_HEADER_CELL, ['column' => 'title']), 'html'))->toBe(['<i>title</i>', '<b>title only</b>'])
        ->and(array_column($panel->renderSlot(Slot::TABLE_HEADER_CELL, ['column' => 'body']), 'html'))->toBe(['<i>body</i>'])
        ->and($panel->renderSlot('missing.slot'))->toBe([]);
});

it('matches `when` conditions strictly', function (): void {
    expect(Panel::slotDataMatches([], []))->toBeTrue()
        ->and(Panel::slotDataMatches(['column' => 'title'], ['column' => 'title']))->toBeTrue()
        ->and(Panel::slotDataMatches(['column' => ['a', 'b']], ['column' => 'b']))->toBeTrue()
        ->and(Panel::slotDataMatches(['column' => 'title'], []))->toBeFalse()
        ->and(Panel::slotDataMatches(['id' => 1], ['id' => '1']))->toBeFalse();
});

it('gives head slot closures $data with the nonce', function (): void {
    alfakit()->getPanel('admin')->slot(Slot::HEAD_END, fn (array $data): string => '<meta name="n" content="'.$data['nonce'].'">');

    expect(alfakit()->getPanel('admin')->renderHeadSlot(Slot::HEAD_END, 'abc'))->toContain('content="abc"');
});

it('maps every Filament table render hook to a slot', function (string $hook, string $constant): void {
    expect(FilamentClassMap::slotFor('Filament\Tables\View\TablesRenderHook', $hook))->toBe($constant)
        ->and(defined(Slot::class.'::'.$constant))->toBeTrue();
})->with([
    ['TOOLBAR_BEFORE', 'TABLE_TOOLBAR_BEFORE'],
    ['TOOLBAR_AFTER', 'TABLE_TOOLBAR_AFTER'],
    ['CONTENT_BEFORE', 'TABLE_CONTENT_BEFORE'],
    ['CONTENT_AFTER', 'TABLE_CONTENT_AFTER'],
    ['HEADER_CELL', 'TABLE_HEADER_CELL'],
    ['SELECTION_INDICATOR_ACTIONS_BEFORE', 'TABLE_SELECTION_INDICATOR_ACTIONS_BEFORE'],
    ['SELECTION_INDICATOR_ACTIONS_AFTER', 'TABLE_SELECTION_INDICATOR_ACTIONS_AFTER'],
    ['TOOLBAR_GROUPING_SELECTOR_BEFORE', 'TABLE_TOOLBAR_GROUPING_SELECTOR_BEFORE'],
    ['TOOLBAR_REORDER_TRIGGER_AFTER', 'TABLE_TOOLBAR_REORDER_TRIGGER_AFTER'],
    ['TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE', 'TABLE_TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE'],
]);

it('serves repeater, builder and key-value item icons through their aliases', function (): void {
    $icons = fn (object $field): array => collect(['delete', 'reorder'])
        ->mapWithKeys(fn (string $name): array => [$name => (fn () => $this->makeItemAction($name))->call($field)->getIcon()])
        ->all();

    expect($icons(Repeater::make('items')))->toBe(['delete' => 'trash-2', 'reorder' => 'grip-vertical']);

    Icons::register([
        'alfakit::forms.components.builder.actions.delete' => 'x',
        'alfakit::forms.components.key-value.actions.reorder' => 'move',
    ]);

    expect($icons(Builder::make('blocks'))['delete'])->toBe('x')
        ->and($icons(Repeater::make('items'))['delete'])->toBe('trash-2')
        ->and($icons(KeyValue::make('meta'))['reorder'])->toBe('move');
});

it('uses the HasSlots trait on panels', function (): void {
    expect(in_array(HasSlots::class, class_uses_recursive(Panel::class), true))->toBeTrue();
});
